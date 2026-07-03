<?php
// Throttle OTP basé fichier (sans BD). Appliqué à l'identique pour numéros
// connus et inconnus afin d'éviter toute énumération. Plafonds par numéro/jour
// et global/jour pour protéger le quota WhatsApp (250/j).
function _throttle_path(array $cfg): string {
  return $cfg['throttle_path'] ?? (__DIR__.'/../state/otp-throttle.json');
}
function _throttle_key(string $phone9, array $cfg): string {
  return hash_hmac('sha256', $phone9, $cfg['otp_secret'] ?? 'x');
}
// Renvoie ['allowed'=>bool,'reason'=>'ok'|'cooldown'|'per_number_cap'|'global_cap'|'nostore'].
// Sur allowed, enregistre l'envoi (last_send + compteurs) de façon atomique (flock).
function throttle_check_and_touch(string $phone9, int $now, array $cfg): array {
  $path   = _throttle_path($cfg);
  $dir    = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  $resend = $cfg['otp_resend'] ?? 60;
  $perNum = $cfg['otp_per_number_daily'] ?? 5;
  $global = $cfg['otp_global_daily'] ?? 200;

  $fp = @fopen($path, 'c+');
  if ($fp === false) { error_log('[jak-otp] throttle store indisponible (fail-open): '.$path); return ['allowed'=>true, 'reason'=>'nostore']; } // fail-open borné par le quota service (250/j)
  flock($fp, LOCK_EX);
  $raw  = stream_get_contents($fp);
  $data = json_decode((string)$raw, true); if (!is_array($data)) $data = [];
  $today = gmdate('Y-m-d', $now);
  if (($data['day'] ?? '') !== $today) $data = ['day'=>$today, 'global'=>0, 'n'=>[]];

  $key = _throttle_key($phone9, $cfg);
  $rec = $data['n'][$key] ?? ['last'=>0, 'count'=>0];

  $allowed = true; $reason = 'ok';
  if (($now - (int)$rec['last']) < $resend)          { $allowed=false; $reason='cooldown'; }
  elseif ((int)$rec['count'] >= $perNum)             { $allowed=false; $reason='per_number_cap'; }
  elseif ((int)($data['global'] ?? 0) >= $global)    { $allowed=false; $reason='global_cap'; }

  if ($allowed) {
    $rec['last']  = $now;
    $rec['count'] = (int)$rec['count'] + 1;
    $data['n'][$key] = $rec;
    $data['global']  = (int)($data['global'] ?? 0) + 1;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($data)); fflush($fp);
  }
  flock($fp, LOCK_UN); fclose($fp);
  return ['allowed'=>$allowed, 'reason'=>$reason];
}
