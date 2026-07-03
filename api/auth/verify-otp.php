<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/store.php';
require_once __DIR__.'/../lib/otp.php';
require_once __DIR__.'/../lib/auth.php';
$cfg = app_boot();

$in = read_body();
$code = preg_replace('/\D/', '', (string)($in['code'] ?? ''));
$sess = $_SESSION['otp'] ?? [];
$r = otp_verify($sess, $code, time(), $cfg);
$_SESSION['otp'] = $sess;

if (!$r['ok']) {
  $map = ['expired'=>'Code expiré, redemandez-en un.','locked'=>'Trop de tentatives, redemandez un code.','none'=>'Aucun code en cours.','bad'=>'Code incorrect.'];
  json_out(['success'=>false,'message'=>$map[$r['reason']] ?? 'Code invalide.'], 401);
}

auth_login($r['role'], $r['member_id'], $cfg);
unset($_SESSION['otp']);

$member = null;
if ($r['role']==='membre') {
  $data = store_load($cfg);
  $member = member_find_by_id($data, $r['member_id']);
}
json_out(['success'=>true,'role'=>$r['role'],'member'=>$member]);
