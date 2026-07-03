<?php
require_once __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/auth.php';
$cfg = app_boot();
$a = auth_current($cfg);
json_out(['authenticated'=>(bool)$a, 'role'=>$a['role'] ?? null, 'member_id'=>$a['member_id'] ?? null]);
