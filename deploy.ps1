<#
.SYNOPSIS
  Déploiement FTP du site statique JAK 2026 vers l'hébergement jak.sn.

.DESCRIPTION
  Lit les identifiants FTP depuis .env, parcourt le dossier du site de façon
  récursive et téléverse chaque fichier via curl (--ftp-create-dirs crée les
  sous-dossiers distants au besoin). Les fichiers de développement (.env, ce
  script, docs .md, .git, node_modules) ne sont jamais envoyés.

.PARAMETER DryRun
  Liste ce qui serait envoyé, sans rien téléverser.

.PARAMETER Ftps
  Tente une connexion chiffrée (FTPS explicite). Repli silencieux en FTP clair
  si le serveur ne le propose pas.

.EXAMPLE
  .\deploy.ps1 -DryRun
  .\deploy.ps1
#>
param(
  [switch]$DryRun,
  [switch]$Ftps
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

# --- Vérifier curl ---
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

# --- Fichiers à NE PAS déployer ---
# Tous les *.md (docs internes : CLAUDE, RAPPORT, CONCEPT, AUDIT_SEO…) sont exclus.
$excludeNames = @('.env', 'deploy.ps1', '.gitignore', 'data.json')
$excludeDirs  = @('.git', 'node_modules', '.vscode', '.idea', 'state', '.superpowers', 'docs')

$files = Get-ChildItem -Path $root -Recurse -File | Where-Object {
  $rel   = $_.FullName.Substring($root.Length).TrimStart('\', '/')
  $parts = $rel -split '[\\/]'
  ($excludeNames -notcontains $_.Name) -and
  ($_.Extension -ne '.md') -and
  (-not ($parts | Where-Object { $excludeDirs -contains $_ }))
}

Write-Host "Cible : ftp://$ftpHost`:$ftpPort/$remotePrefix  (utilisateur $ftpUser)" -ForegroundColor Cyan
Write-Host ("{0} fichier(s) à traiter{1}" -f $files.Count, $(if ($DryRun) { ' — DRY RUN' } else { '' })) -ForegroundColor Cyan

$ok = 0; $fail = 0
foreach ($f in $files) {
  $rel    = $f.FullName.Substring($root.Length).TrimStart('\', '/')
  $relUrl = (($rel -split '[\\/]') | ForEach-Object { [Uri]::EscapeDataString($_) }) -join '/'
  $url    = "ftp://$ftpHost`:$ftpPort/$remotePrefix$relUrl"

  if ($DryRun) { Write-Host "  [dry] $rel" -ForegroundColor DarkGray; $ok++; continue }

  $args = @(
    '--silent', '--show-error', '--ftp-create-dirs', '--ftp-pasv',
    '--connect-timeout', '20',
    '-T', $f.FullName,
    '--user', "$ftpUser`:$ftpPass",
    $url
  )
  if ($Ftps) { $args = @('--ssl') + $args }   # FTPS explicite, repli clair si indisponible

  $out = & curl.exe @args 2>&1
  if ($LASTEXITCODE -eq 0) {
    Write-Host "  OK  $rel" -ForegroundColor Green; $ok++
  } else {
    Write-Host "  ERR $rel  -> $out" -ForegroundColor Red; $fail++
  }
}

Write-Host ("Terminé : {0} envoyé(s), {1} échec(s)." -f $ok, $fail) -ForegroundColor $(if ($fail) { 'Yellow' } else { 'Green' })
if ($fail) { exit 1 }
