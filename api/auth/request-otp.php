<?php
// =============================================================================
//  request-otp.php — Demande d'un code OTP (V2 / J1)
// =============================================================================
//  V1 : défi stocké en $_SESSION (hash, expire, tentatives).
//  V2 : défi persisté en BASE (otp_defi) via otp_creer (PL/pgSQL). On ne garde
//       en session que l'otp_id (bigint) retourné + le last_send (cooldown UX).
//
//  Sécurité préservée (anti-énumération) :
//    - respond_then_continue() : la réponse HTTP part avant l'envoi WhatsApp,
//      sans que le client puisse mesurer le travail restant.
//    - Leurre : un défi est posé MÊME pour un numéro inconnu (le code n'est pas
//      envoyé) → verify-otp se comporte à l'identique (pas d'oracle d'énumération).
//    - Throttle fichier (anti-abus) inchangé.
//
//  Téléphone : normalisé en E.164 ('+221...') avant lookup/otp_creer.
// =============================================================================
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/store.php';
require_once __DIR__.'/../lib/otp.php';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/whatsapp.php';
require_once __DIR__.'/../lib/throttle.php';
$cfg = app_boot();

$in   = read_body();
$role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'membre';
$now  = time();
$sess = $_SESSION['otp'] ?? [];
$GENERIC = 'Si ce numéro correspond à une fiche, un code a été envoyé.';

// Émet la réponse HTTP puis rend la main sans que le client puisse mesurer le
// travail restant (envoi WhatsApp). Sous LiteSpeed/PHP-FPM : fastcgi_finish_request.
function respond_then_continue(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  session_write_close();
  if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
  else { @ob_flush(); @flush(); }
}

if ($role === 'admin') {
  $phone = $cfg['admin_phone'];
  // Admin : le téléphone configuré est 9 chiffres → on normalise en E.164.
  $phoneIntl = otp_normalize_phone($phone, $cfg) ?? ('+'.($cfg['default_country_code'] ?? '221').$phone);
  $thCfg = $cfg; $thCfg['otp_per_number_daily'] = $cfg['otp_admin_daily'] ?? 50;
  $th = throttle_check_and_touch($phone, $now, $thCfg);
  if (!$th['allowed']) json_out(['success'=>false,'message'=>'Veuillez patienter avant un nouvel envoi.','cooldown'=>$cfg['otp_resend']], 429);
  $code = otp_generate();
  $hash = otp_hash($code, $cfg);
  try {
    $otpId = db_call_function('otp_creer', ['admin', $phoneIntl, null, $hash], $cfg);
  } catch (Throwable $e) {
    error_log('[jak-otp] otp_creer admin échec : '.$e->getMessage());
    json_out(['success'=>false,'message'=>'Envoi du code impossible pour le moment. Réessayez.'], 500);
  }
  $_SESSION['otp'] = ['otp_id'=>$otpId, 'last_send'=>$now];
  $res = wa_send_otp($phone, $code, $cfg);
  if (!$res['ok']) json_out(['success'=>false,'message'=>'Envoi du code impossible pour le moment. Réessayez.'], 502);
  json_out(['success'=>true,'message'=>'Code envoyé au numéro administrateur.','cooldown'=>$cfg['otp_resend']]);
}

// Rôle membre — anti-énumération : réponse, statut ET temps de réponse identiques
// que le numéro corresponde ou non à une fiche.
$phone9 = preg_replace('/\D/', '', (string)($in['telephone'] ?? ''));
if (strlen($phone9) !== 9) {
  json_out(['success'=>true,'message'=>$GENERIC,'cooldown'=>$cfg['otp_resend']]);
}
$th = throttle_check_and_touch($phone9, $now, $cfg);
if (!$th['allowed']) {
  json_out(['success'=>false,'message'=>'Veuillez patienter avant un nouvel envoi.','cooldown'=>$cfg['otp_resend']], 429);
}

// Lookup membre en base (E.164). En cas d'échec DB, on reste silencieux
// (anti-énumération) en posant quand même un défi leurre sans personne_id.
$phoneIntl = otp_normalize_phone($phone9, $cfg);
$m = null;
$personneId = null;
if ($phoneIntl !== null) {
  try { $m = member_find_by_phone($cfg, $phoneIntl); } catch (Throwable $e) { $m = null; }
  $personneId = ($m && isset($m['id'])) ? (int)$m['id'] : null;
}

$code = otp_generate();
$hash = otp_hash($code, $cfg);
// Challenge posé MÊME sans membre (leurre non envoyé) : verify-otp se comporte
// à l'identique (jamais 'none' pour un inconnu) → pas d'oracle d'énumération au verify.
try {
  $otpId = db_call_function('otp_creer', ['membre', $phoneIntl, $personneId, $hash], $cfg);
} catch (Throwable $e) {
  error_log('[jak-otp] otp_creer membre échec : '.$e->getMessage());
  // On reste générique (anti-énumération) même en cas d'erreur DB.
  $otpId = null;
}
$_SESSION['otp'] = ['otp_id'=>$otpId, 'last_send'=>$now];

respond_then_continue(['success'=>true,'message'=>$GENERIC,'cooldown'=>$cfg['otp_resend']]);
if ($m && $otpId !== null) {
  $res = wa_send_otp($phone9, $code, $cfg);
  if (!$res['ok']) { error_log('[jak-otp] echec envoi WhatsApp membre'); }
}
exit;
