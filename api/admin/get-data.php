<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/store.php';
$cfg = app_boot();
require_role('admin', $cfg);
json_out(['success'=>true, 'data'=>store_load($cfg)]);
