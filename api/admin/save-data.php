<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/store.php';
$cfg = app_boot();
require_role('admin', $cfg);

$in = read_body();
$data = $in['data'] ?? null;
if (!is_array($data) || !isset($data['jak']) || !isset($data['settings'])) {
  json_out(['success'=>false,'message'=>'Données invalides'], 422);
}
store_save($cfg, $data);
json_out(['success'=>true]);
