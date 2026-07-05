<?php
// =============================================================================
//  otp_test.php — Tests helpers OTP + round-trip base (V2 / J1)
// =============================================================================
//  V1 : testait otp_set_challenge/otp_verify en session (supprimés en V2).
//  V2 : teste les helpers restants (otp_generate, otp_hash, otp_normalize_phone)
//       + le round-trip otp_creer/otp_verifier EN BASE (via db.php).
//
//  Exécution :
//    C:\php\php.exe api\tests\otp_test.php
//  (Nécessite la base jaksn_db accessible via sql_jsonpro.)
// =============================================================================
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/config.php';
require __DIR__.'/../lib/otp.php';
require __DIR__.'/../lib/db.php';
$cfg = require __DIR__.'/../lib/config.php';

// --- Helpers ---

$c = otp_generate();
ok(preg_match('/^\d{6}$/', $c) === 1, 'code = 6 chiffres');

// otp_hash : déterministe (même code → même hash), secret dépendant.
$h1 = otp_hash('123456', $cfg);
$h2 = otp_hash('123456', $cfg);
$h3 = otp_hash('654321', $cfg);
eq($h1, $h2, 'otp_hash déterministe');
ok($h1 !== $h3, 'codes différents → hashes différents');
ok(strlen($h1) === 64, 'hash HMAC-SHA256 = 64 hex');

// otp_normalize_phone : 9 chiffres → E.164 avec indicatif configuré.
// Numéros FACTICES (plage réservée 7000000xx) : aucun vrai numéro de membre committé.
eq(otp_normalize_phone('700000000', $cfg), '+221700000000', '9 chiffres → +221...');
eq(otp_normalize_phone('70 000 00 00', $cfg), '+221700000000', 'espaces stripés');
eq(otp_normalize_phone('+221700000000', $cfg), '+221700000000', 'E.164 préservé');
eq(otp_normalize_phone('+33600000000', $cfg), '+33600000000', 'format FR préservé');
eq(otp_normalize_phone('123', $cfg), null, 'trop court → null');

// otp_can_send : cooldown basé sur last_send.
ok(otp_can_send([], 1000, $cfg) === true, 'envoi permis sans last_send');
$sess = ['last_send' => 1000];
ok(otp_can_send($sess, 1030, $cfg) === false, 'renvoi bloqué avant 60s');
ok(otp_can_send($sess, 1061, $cfg) === true, 'renvoi permis après 60s');

// --- Round-trip otp_creer / otp_verifier EN BASE ---

try {
  // Succès : bon code.
  $hash = otp_hash('999888', $cfg);
  $otpId = db_call_function('otp_creer', ['membre', '+221770000088', null, $hash], $cfg);
  ok($otpId > 0, 'otp_creer retourne un id > 0');

  $r = db_call_function('otp_verifier', [$otpId, $hash], $cfg);
  ok(($r['ok'] ?? false) === true, 'bon code → ok=true');
  eq($r['raison'] ?? '', 'ok', 'raison = ok');

  // Rejeu après consommation → 'none' (idempotence).
  $r2 = db_call_function('otp_verifier', [$otpId, $hash], $cfg);
  ok(($r2['ok'] ?? false) === false && ($r2['raison'] ?? '') === 'none', 'défi consommé → none');

  // Mauvais code : incrémente tentatives, défi vivant.
  $hash2 = otp_hash('111111', $cfg);
  $otpId2 = db_call_function('otp_creer', ['membre', '+221770000077', null, $hash2], $cfg);
  $rb = db_call_function('otp_verifier', [$otpId2, otp_hash('000000', $cfg)], $cfg);
  ok(($rb['ok'] ?? false) === false && ($rb['raison'] ?? '') === 'bad', 'mauvais code → bad');

  // Verrouillage : 5 mauvais codes → locked.
  for ($i = 0; $i < 5; $i++) {
    $rl = db_call_function('otp_verifier', [$otpId2, otp_hash('000000', $cfg)], $cfg);
  }
  $rFinal = db_call_function('otp_verifier', [$otpId2, $hash2], $cfg);
  ok(($rFinal['ok'] ?? false) === false, 'verrouillé après 5 essais');
} catch (Throwable $e) {
  ok(false, 'round-trip base a échoué : '.$e->getMessage());
}

done();
