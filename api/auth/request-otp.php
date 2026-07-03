<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/store.php';
require_once __DIR__.'/../lib/otp.php';
require_once __DIR__.'/../lib/whatsapp.php';
$cfg = app_boot();

$in = read_body();
$role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'membre';
$now = time();
$sess = $_SESSION['otp'] ?? [];

if (!otp_can_send($sess, $now, $cfg)) {
  json_out(['success'=>false,'message'=>'Veuillez patienter avant un nouvel envoi.','cooldown'=>$cfg['otp_resend']], 429);
}

if ($role === 'admin') {
  $phone = $cfg['admin_phone']; $memberId = null;
} else {
  $phone9 = preg_replace('/\D/', '', (string)($in['telephone'] ?? ''));
  $data = store_load($cfg);
  $m = strlen($phone9)===9 ? member_find_by_phone($data, $phone9) : null;
  // Anti-énumération : réponse identique même si le membre n'existe pas.
  if (!$m) json_out(['success'=>true,'message'=>'Si ce numéro correspond à une fiche, un code a été envoyé.','cooldown'=>$cfg['otp_resend']]);
  $phone = $phone9; $memberId = $m['id'];
}

$code = otp_generate();
otp_set_challenge($sess, $role, $memberId, $phone, $code, $now, $cfg);
$_SESSION['otp'] = $sess;

$res = wa_send_otp($phone, $code, $cfg);
if (!$res['ok']) {
  json_out(['success'=>false,'message'=>'Envoi du code impossible pour le moment. Réessayez.'], 502);
}
$msg = $role==='admin' ? 'Code envoyé au numéro administrateur.' : 'Si ce numéro correspond à une fiche, un code a été envoyé.';
json_out(['success'=>true,'message'=>$msg,'cooldown'=>$cfg['otp_resend']]);
