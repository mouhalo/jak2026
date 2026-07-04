<#
.SYNOPSIS
  Déploiement FTP INCRÉMENTAL du site JAK 2026 vers jak.sn : n'envoie que les
  fichiers nouveaux ou modifiés depuis le dernier déploiement.

.DESCRIPTION
  Même sélection de fichiers et même upload curl que deploy.ps1, mais compare
  chaque fichier à un manifeste local de hachages MD5 (.deploy-manifest.json) et
  ne téléverse que ce qui a changé. Le manifeste est mis à jour après chaque
  envoi réussi. Il n'est ni committé ni déployé.

.PARAMETER DryRun
  Liste ce qui serait envoyé (nouveaux/modifiés), sans rien téléverser ni
  modifier le manifeste.

.PARAMETER Baseline
  Ne téléverse RIEN : enregistre l'état actuel de tous les fichiers comme
  « déjà déployé ». À lancer UNE fois juste après un déploiement complet
  (deploy.ps1) pour amorcer le manifeste — ensuite les runs seront incrémentaux.

.PARAMETER Full
  Ignore le manifeste et (ré)envoie tout, puis reconstruit le manifeste.

.PARAMETER Ftps
  Tente une connexion chiffrée (FTPS explicite), repli FTP clair sinon.

.EXAMPLE
  .\deploy_lite.ps1 -Baseline     # 1re fois, après un deploy.ps1 complet
  .\deploy_lite.ps1 -DryRun       # voir ce qui partirait
  .\deploy_lite.ps1               # envoyer uniquement les fichiers modifiés
#>
param(
  [switch]$DryRun,
  [switch]$Baseline,
  [switch]$Full,
  [switch]$Ftps
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) {
  throw "curl.exe introuvable (requis pour l'upload FTP). Windows 10+ l'inclut normalement."
}

# --- Charger .env (format KEY=VALUE ; la valeur peut contenir : et <) ---
$envFile = Join-Path $root '.env'
if (-not (Test-Path $envFile)) { throw ".env introuvable : $envFile" }
$cfg = @{}
foreach ($line in Get-Content $envFile) {
  $l = $line.Trim()
  if ($l -eq '' -or $l.StartsWith('#')) { continue }
  $i = $l.IndexOf('=')
  if ($i -lt 1) { continue }
  $cfg[$l.Substring(0, $i).Trim()] = $l.Substring($i + 1).Trim()
}

$ftpHost = $cfg['FTP_HOST']
$ftpPort = if ($cfg['FTP_PORT']) { $cfg['FTP_PORT'] } else { '21' }
$ftpUser = $cfg['FTP_USER']
$ftpPass = $cfg['FTP_PASS']
$remote  = if ($cfg['REMOTE_DIR']) { $cfg['REMOTE_DIR'].Trim().Trim('/') } else { '' }
foreach ($k in 'FTP_HOST', 'FTP_USER', 'FTP_PASS') {
  if (-not $cfg[$k]) { throw "Paramètre manquant dans .env : $k" }
}
$remotePrefix = if ($remote) { "$remote/" } else { '' }

# --- Fichiers à NE PAS déployer (identique à deploy.ps1 + le manifeste) ---
$excludeNames = @('.env', 'deploy.ps1', 'deploy_lite.ps1', '.gitignore', 'data.json', '.deploy-manifest.json')
$excludeDirs  = @('.git', 'node_modules', '.vscode', '.idea', 'state', '.superpowers', 'docs', 'tests', 'tools', 'db')

$files = Get-ChildItem -Path $root -Recurse -File | Where-Object {
  $rel   = $_.FullName.Substring($root.Length).TrimStart('\', '/')
  $parts = $rel -split '[\\/]'
  ($excludeNames -notcontains $_.Name) -and
  ($_.Extension -ne '.md') -and
  ($_.Extension -ne '.ps1') -and
  (-not ($parts | Where-Object { $excludeDirs -contains $_ }))
}

# --- Charger le manifeste de hachages (relpath -> MD5) ---
$manFile  = Join-Path $root '.deploy-manifest.json'
$manifest = @{}
if ((Test-Path $manFile) -and -not $Full) {
  try {
    $obj = Get-Content $manFile -Raw | ConvertFrom-Json
    foreach ($p in $obj.PSObject.Properties) { $manifest[$p.Name] = [string]$p.Value }
  } catch { Write-Host "Manifeste illisible — reconstruction complète." -ForegroundColor Yellow }
}

$mode = if ($Baseline) { 'BASELINE (aucun envoi)' } elseif ($Full) { 'FULL' } elseif ($DryRun) { 'DRY RUN' } else { 'INCRÉMENTAL' }
Write-Host "Cible : ftp://$ftpHost`:$ftpPort/$remotePrefix  (utilisateur $ftpUser)" -ForegroundColor Cyan
Write-Host ("{0} fichier(s) candidats — mode {1}" -f $files.Count, $mode) -ForegroundColor Cyan

$sent = 0; $skip = 0; $fail = 0
foreach ($f in $files) {
  $rel    = ($f.FullName.Substring($root.Length).TrimStart('\', '/')) -replace '\\', '/'
  $hash   = (Get-FileHash -LiteralPath $f.FullName -Algorithm MD5).Hash
  $known  = $manifest.ContainsKey($rel) -and $manifest[$rel] -eq $hash

  # Baseline : on enregistre l'état sans rien envoyer.
  if ($Baseline) { $manifest[$rel] = $hash; $skip++; continue }

  # Inchangé (et pas -Full) : on saute.
  if ($known -and -not $Full) { $skip++; continue }

  $tag = if ($manifest.ContainsKey($rel)) { 'modifié' } else { 'nouveau' }
  if ($DryRun) { Write-Host ("  [dry] {0}  ({1})" -f $rel, $tag) -ForegroundColor DarkGray; $sent++; continue }

  $relUrl = (($rel -split '/') | ForEach-Object { [Uri]::EscapeDataString($_) }) -join '/'
  $url    = "ftp://$ftpHost`:$ftpPort/$remotePrefix$relUrl"
  $args   = @(
    '--silent', '--show-error', '--ftp-create-dirs', '--ftp-pasv',
    '--connect-timeout', '20',
    '-T', $f.FullName,
    '--user', "$ftpUser`:$ftpPass",
    $url
  )
  if ($Ftps) { $args = @('--ssl') + $args }

  $out = & curl.exe @args 2>&1
  if ($LASTEXITCODE -eq 0) {
    Write-Host ("  OK  {0}  ({1})" -f $rel, $tag) -ForegroundColor Green
    $manifest[$rel] = $hash   # mémorise le hash uniquement si l'envoi a réussi
    $sent++
  } else {
    Write-Host ("  ERR {0}  -> {1}" -f $rel, $out) -ForegroundColor Red
    $fail++
  }
}

# --- Sauvegarder le manifeste (sauf en DryRun) ---
if (-not $DryRun) {
  $out = [ordered]@{}
  foreach ($k in ($manifest.Keys | Sort-Object)) { $out[$k] = $manifest[$k] }
  $out | ConvertTo-Json | Set-Content -LiteralPath $manFile -Encoding UTF8
}

if ($Baseline) {
  Write-Host ("Baseline enregistrée : {0} fichier(s) marqués comme déployés (aucun envoi)." -f $skip) -ForegroundColor Green
} else {
  Write-Host ("Terminé : {0} envoyé(s), {1} inchangé(s) ignoré(s), {2} échec(s)." -f $sent, $skip, $fail) -ForegroundColor $(if ($fail) { 'Yellow' } else { 'Green' })
}
if ($fail) { exit 1 }
