<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/store.php';
require_once __DIR__.'/../lib/otp.php';
require_once __DIR__.'/../lib/whatsapp.php';
require_once __DIR__.'/../lib/throttle.php';
$cfg = app_boot();

$in   = read_body();
$role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'membre';
$now  = time();
$sess = $_SESSION['otp'] ?? [];
$GENERIC = 'Si ce numéro correspond à une fiche, un code a été envoyé.';

if ($role === 'admin') {
  $phone = $cfg['admin_phone'];
  $th = throttle_check_and_touch($phone, $now, $cfg);
  if (!$th['allowed']) json_out(['success'=>false,'message'=>'Veuillez patienter avant un nouvel envoi.','cooldown'=>$cfg['otp_resend']], 429);
  $code = otp_generate();
  otp_set_challenge($sess, 'admin', null, $phone, $code, $now, $cfg);
  $_SESSION['otp'] = $sess;
  $res = wa_send_otp($phone, $code, $cfg);
  if (!$res['ok']) json_out(['success'=>false,'message'=>'Envoi du code impossible pour le moment. Réessayez.'], 502);
  json_out(['success'=>true,'message'=>'Code envoyé au numéro administrateur.','cooldown'=>$cfg['otp_resend']]);
}

// Rôle membre — anti-énumération : comportement ET réponse identiques que le numéro
// corresponde ou non à une fiche. Le throttle s'applique AVANT toute recherche membre.
$phone9 = preg_replace('/\D/', '', (string)($in['telephone'] ?? ''));
if (strlen($phone9) !== 9) {
  json_out(['success'=>true,'message'=>$GENERIC,'cooldown'=>$cfg['otp_resend']]);
}
$th = throttle_check_and_touch($phone9, $now, $cfg);
if (!$th['allowed']) {
  json_out(['success'=>false,'message'=>'Veuillez patienter avant un nouvel envoi.','cooldown'=>$cfg['otp_resend']], 429);
}
$data = store_load($cfg);
$m = member_find_by_phone($data, $phone9);
if ($m) {
  $code = otp_generate();
  otp_set_challenge($sess, 'membre', $m['id'], $phone9, $code, $now, $cfg);
  $_SESSION['otp'] = $sess;
  $res = wa_send_otp($phone9, $code, $cfg);
  if (!$res['ok']) { error_log('[jak-otp] echec envoi WhatsApp membre'); } // jamais révélé au client
}
json_out(['success'=>true,'message'=>$GENERIC,'cooldown'=>$cfg['otp_resend']]); // réponse uniforme
