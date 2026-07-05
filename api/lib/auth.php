<?php
// =============================================================================
//  auth.php — Session d'authentification (V2 / J1)
// =============================================================================
//  V1 : $_SESSION['auth'] = {role, member_id (slug string), exp}
//  V2 : $_SESSION['auth'] = {role, personne_id (bigint), telephone (E.164), exp}
//
//  Changements clés :
//    - member_id (slug) → personne_id (bigint) : la vraie PK en base.
//    - On stocke aussi le téléphone authentifié : il sert de double-clé au
//      cloisonnement de membre_sauver_fiche (WHERE id=? AND telephone=?).
//      Le téléphone vient TOUJOURS de la session post-OTP, jamais du client.
// =============================================================================

function auth_login(string $role, ?int $personneId, ?string $telephone, array $cfg): void {
  session_regenerate_id(true);
  $_SESSION['auth'] = [
    'role'        => $role,
    'personne_id' => $personneId,
    'telephone'   => $telephone,
    'exp'         => time() + $cfg['session_ttl'],
  ];
}

function auth_current(array $cfg): ?array {
  $a = $_SESSION['auth'] ?? null;
  if (!$a || ($a['exp'] ?? 0) < time()) return null;
  return [
    'role'        => $a['role'],
    'personne_id' => isset($a['personne_id']) ? (int)$a['personne_id'] : null,
    'telephone'   => $a['telephone'] ?? null,
  ];
}

function auth_logout(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
}

function require_role(string $role, array $cfg): array {
  $a = auth_current($cfg);
  if (!$a || $a['role'] !== $role) json_out(['success'=>false,'message'=>'Non autorisé'], 401);
  return $a;
}
