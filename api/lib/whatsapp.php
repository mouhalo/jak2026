<?php
function wa_e164(string $phone9): string { return '+221'.$phone9; }

/**
 * Résout le bundle CA pour la vérif SSL cURL (cf. db.php / config.php).
 * On ne DÉSACTIVE jamais la vérif — on pointe juste vers un CA valide.
 * En prod, le bundle système suffit (ca_bundle='') ; en dev Windows PHP CLI
 * n'en fournit pas, on détecte alors le bundle Git.
 */
function _wa_ca_bundle(array $cfg): string {
  $ca = $cfg['ca_bundle'] ?? '';
  if (!$ca) {
    foreach (($cfg['ca_bundle_candidates'] ?? []) as $cand) {
      if (@is_file($cand)) return $cand;
    }
  }
  return $ca;
}

function _wa_curl(string $url, array $body, array $cfg): array {
  $ch = curl_init($url);
  $opts = [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,   // TOUJOURS vérifié.
    CURLOPT_SSL_VERIFYHOST => 2,
  ];
  // Bundle CA : on pointe vers un CA valide, on ne désactive JAMAIS la vérif.
  $ca = _wa_ca_bundle($cfg);
  if ($ca) { $opts[CURLOPT_CAINFO] = $ca; }
  curl_setopt_array($ch, $opts);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $json = json_decode((string)$raw, true);
  return ['status'=>$status, 'json'=>is_array($json)?$json:[]];
}

function wa_send_otp(string $phone9, string $code, array $cfg, ?callable $transport=null): array {
  // Transport par défaut : closure qui capture $cfg pour le résolveur CA bundle.
  // (Permet aussi l'injection d'un mock pour les tests, en gardant $cfg.)
  $transport = $transport ?? fn($url, $body) => _wa_curl($url, $body, $cfg);
  $body = ['telephone'=>wa_e164($phone9), 'code'=>$code, 'langue'=>$cfg['langue'] ?? 'fr'];
  try {
    $res = $transport($cfg['whatsapp_url'], $body);
  } catch (\Throwable $e) {
    return ['ok'=>false, 'message'=>'Service WhatsApp indisponible', 'error_code'=>'NETWORK'];
  }
  $j = $res['json'] ?? [];
  $ok = ($res['status'] ?? 0) === 200 && !empty($j['success']);
  return ['ok'=>$ok, 'message'=>$j['message'] ?? '', 'error_code'=>$j['error_code'] ?? null];
}
