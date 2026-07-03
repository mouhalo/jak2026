<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/auth.php';
app_boot();
auth_logout();
json_out(['success'=>true]);
