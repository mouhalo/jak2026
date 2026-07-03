<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/store.php';
require_once __DIR__.'/../lib/validate.php';
$cfg = app_boot();
$a = require_role('membre', $cfg);

$in = read_body();
$data = store_load($cfg);
$v = validate_member_fields($in, $data, $a['member_id']);
if ($v['errors']) json_out(['success'=>false,'message'=>'Champs invalides','errors'=>$v['errors']], 422);

if (!member_apply_update($data, $a['member_id'], $v['fields'])) {
  json_out(['success'=>false,'message'=>'Fiche introuvable'], 404);
}
store_save($cfg, $data);
json_out(['success'=>true, 'member'=>member_find_by_id($data, $a['member_id'])]);
