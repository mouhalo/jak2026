<?php
// =============================================================================
//  otp.php — Helpers OTP (V2 / J1)
// =============================================================================
//  V1 : le défi était stocké en $_SESSION (hash, expire, tentatives, ...).
//  V2 : le défi est persisté en BASE (table otp_defi) via otp_creer/otp_verifier
//       (PL/pgSQL livrées par dba_master). Ce fichier ne garde que les helpers
//       qui restent côté PHP :
//         - otp_generate()  : code 6 chiffres
//         - otp_hash()      : HMAC-SHA256 du code (le secret reste côté PHP,
//                              la base ne reçoit JAMAIS le code en clair)
//         - otp_normalize_phone() : 9 chiffres saisis → E.164 ('+221...')
//         - otp_can_send()  : cooldown basé sur $_SESSION (lissage UX, non sécurité)
//
//  La logique de vérification (expiration, verrouillage, comparaison) est
//  désormais en base (otp_verifier), qui reçoit les DEUX hashes.
// =============================================================================

/**
 * Génère un code OTP à 6 chiffres.
 */
function otp_generate(): string {
  return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Calcule le hash HMAC-SHA256 d'un code OTP.
 * Le secret (otp_secret) reste côté PHP (config.php gitignoré) ; la base
 * ne reçoit que ce hash, jamais le code en clair.
 */
function otp_hash(string $code, array $cfg): string {
  return hash_hmac('sha256', $code, $cfg['otp_secret']);
}

/**
 * Normalise un numéro saisi (9 chiffres nus, format sénégalais) en E.164.
 *
 * Règle : on extrait les chiffres ; si 9 chiffres → on préfixe avec l'indicatif
 * pays configuré (défaut 221). Si déjà en E.164 (commence par +), on retourne
 * tel quel (chiffres uniquement recomposés avec +).
 *
 * @param string $input   Numéro saisi (ex: '70 000 00 00' ou '+221700000000')
 * @param array  $cfg
 * @return string|null    E.164 '+221700000000' ou null si invalide
 */
function otp_normalize_phone(string $input, array $cfg): ?string {
  $digits = preg_replace('/\D/', '', $input);
  if ($digits === '') return null;
  $cc = $cfg['default_country_code'] ?? '221';
  if (strlen($digits) === 9) {
    return '+'.$cc.$digits;
  }
  // Déjà un format avec indicatif (10-15 chiffres) → on garde tel quel.
  if (strlen($digits) >= 10 && strlen($digits) <= 15) {
    return '+'.$digits;
  }
  return null;
}

/**
 * Cooldown de renvoi (UX, non sécurité — le throttle fichier reste l'anti-abus).
 * @param array $sess  Session OTP courante ({'last_send'=>int|null})
 * @param int   $now   Timestamp courant
 * @param array $cfg
 */
function otp_can_send(array $sess, int $now, array $cfg): bool {
  if (empty($sess['last_send'])) return true;
  return ($now - $sess['last_send']) >= $cfg['otp_resend'];
}
