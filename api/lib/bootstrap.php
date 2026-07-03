<?php
function app_boot(): array {
  $cfg = require __DIR__.'/config.php';
  $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off');
  session_set_cookie_params([
    'lifetime'=>0, 'path'=>'/', 'httponly'=>true,
    'secure'=>$https, 'samesite'=>'Lax',
  ]);
  session_start();
  header('Content-Type: application/json; charset=utf-8');
  return $cfg;
}

function json_out(array $payload, int $status=200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function read_body(): array {
  $raw = file_get_contents('php://input');
  $d = json_decode((string)$raw, true);
  return is_array($d) ? $d : [];
}
