<?php
function wa_e164(string $phone9): string { return '+221'.$phone9; }

function _wa_curl(string $url, array $body): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $json = json_decode((string)$raw, true);
  return ['status'=>$status, 'json'=>is_array($json)?$json:[]];
}

function wa_send_otp(string $phone9, string $code, array $cfg, ?callable $transport=null): array {
  $transport = $transport ?? '_wa_curl';
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
