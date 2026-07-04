<#
  Supprime du serveur FTP les fichiers db/ déployés par erreur (préparation
  Postgres, non destinés au web). Réutilise les identifiants de .env.
  Usage :  .\clean_remote_db.ps1
#>
$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

$envFile = Join-Path $root '.env'
if (-not (Test-Path $envFile)) { throw ".env introuvable" }
$cfg = @{}
foreach ($line in Get-Content $envFile) {
  $l = $line.Trim()
  if ($l -eq '' -or $l.StartsWith('#')) { continue }
  $i = $l.IndexOf('='); if ($i -lt 1) { continue }
  $cfg[$l.Substring(0, $i).Trim()] = $l.Substring($i + 1).Trim()
}
$ftpHost = $cfg['FTP_HOST']; $ftpPort = if ($cfg['FTP_PORT']) { $cfg['FTP_PORT'] } else { '21' }
$ftpUser = $cfg['FTP_USER']; $ftpPass = $cfg['FTP_PASS']
$remote  = if ($cfg['REMOTE_DIR']) { $cfg['REMOTE_DIR'].Trim().Trim('/') } else { '' }
$prefix  = if ($remote) { "$remote/" } else { '' }

$targets = @('db/seed.mjs', 'db/seed.sql', 'db/schema.sql')
foreach ($t in $targets) {
  $out = & curl.exe --silent --show-error --user "$ftpUser`:$ftpPass" `
    "ftp://$ftpHost`:$ftpPort/" -Q "DELE $prefix$t" -w "%{http_code}" 2>&1
  if ($LASTEXITCODE -eq 0) { Write-Host "  supprimé : $t" -ForegroundColor Green }
  else { Write-Host "  (déjà absent ou erreur) : $t -> $out" -ForegroundColor DarkYellow }
}

Write-Host "Vérification :" -ForegroundColor Cyan
$code = & curl.exe -s -o NUL -w "%{http_code}" "https://jak.sn/db/seed.mjs"
Write-Host ("  https://jak.sn/db/seed.mjs -> {0} {1}" -f $code, $(if ($code -eq '404') { '✓ retiré' } else { '(encore présent)' }))
