<?php
// =============================================================================
//  verify-otp.php — Vérification d'un code OTP (V2 / J1)
// =============================================================================
//  V1 : otp_verify() comparait les hashes en session.
//  V2 : otp_verifier() en BASE compare le hash stocké vs le hash soumis (les
//       deux calculés côté PHP via otp_hash). Retourne {ok, raison, role, personne_id}.
//
//  Sur succès : on remonte la fiche membre via personne_par_id (slug pour le
//  front), on pose la session auth (personne_id + telephone), on renvoie la
//  fiche projetée pour l'éditeur (auth.js openEditor).
// =============================================================================
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/store.php';
require_once __DIR__.'/../lib/otp.php';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
$cfg = app_boot();

$in    = read_body();
$code  = preg_replace('/\D/', '', (string)($in['code'] ?? ''));
$sess  = $_SESSION['otp'] ?? [];
$otpId = $sess['otp_id'] ?? null;

// Pas de défi en cours → 'none' (anti-énumération : ce cas reste identique
// qu'il y ait eu un membre ou non).
if (!$otpId) {
  json_out(['success'=>false,'message'=>'Aucun code en cours.'], 401);
}

// Hash du code soumis (calculé côté PHP, comme en V1).
$submittedHash = otp_hash($code, $cfg);

try {
  // p_max_tentatives est un smallint : PostgreSQL REFUSE le narrowing
  // integer→smallint, on caste donc explicitement (db_cast → '5'::smallint).
  $r = db_call_function('otp_verifier', [(int)$otpId, $submittedHash, db_cast((int)($cfg['otp_max_try'] ?? 5), 'smallint')], $cfg);
} catch (Throwable $e) {
  error_log('[jak-otp] otp_verifier échec : '.$e->getMessage());
  json_out(['success'=>false,'message'=>'Vérification impossible pour le moment. Réessayez.'], 500);
}
$r = is_array($r) ? $r : ['ok'=>false,'raison'=>'none'];

if (!($r['ok'] ?? false)) {
  $map = ['expired'=>'Code expiré, redemandez-en un.','locked'=>'Trop de tentatives, redemandez un code.','none'=>'Aucun code en cours.','bad'=>'Code incorrect.'];
  unset($_SESSION['otp']);
  json_out(['success'=>false,'message'=>$map[$r['raison'] ?? ''] ?? 'Code invalide.'], 401);
}

// Succès : on consomme le défi (otp_verifier l'a déjà marqué consomme=true en base).
unset($_SESSION['otp']);
$role       = $r['role'];
$personneId = isset($r['personne_id']) ? (int)$r['personne_id'] : null;
$member     = null;
$telephone  = null;

if ($role === 'membre' && $personneId) {
  try {
    $personne = member_find_by_id($cfg, $personneId);
    if ($personne) {
      $telephone = $personne['telephone'] ?? null;
      $member = member_to_front($personne);
    }
  } catch (Throwable $e) {
    error_log('[jak-otp] récupération fiche membre échec : '.$e->getMessage());
  }
}

// Session auth : on stocke personne_id (PK base) ET le téléphone authentifié
// (double-clé pour membre_sauver_fiche).
auth_login($role, $personneId, $telephone, $cfg);

json_out(['success'=>true,'role'=>$role,'member'=>$member]);
