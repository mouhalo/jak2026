<?php
function auth_login(string $role, ?string $memberId, array $cfg): void {
  session_regenerate_id(true);
  $_SESSION['auth'] = ['role'=>$role, 'member_id'=>$memberId, 'exp'=>time()+$cfg['session_ttl']];
}

function auth_current(array $cfg): ?array {
  $a = $_SESSION['auth'] ?? null;
  if (!$a || ($a['exp'] ?? 0) < time()) return null;
  return ['role'=>$a['role'], 'member_id'=>$a['member_id'] ?? null];
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
