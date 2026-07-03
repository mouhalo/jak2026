<?php
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/throttle.php';
$tmp = sys_get_temp_dir().'/jak_throttle_'.uniqid().'.json';
$cfg = ['throttle_path'=>$tmp,'otp_secret'=>'s','otp_resend'=>60,'otp_per_number_daily'=>3,'otp_global_daily'=>5];

$r = throttle_check_and_touch('770000001', 1000, $cfg); ok($r['allowed']===true,'1er envoi permis (global=1, 001 count=1)');
$r = throttle_check_and_touch('770000001', 1030, $cfg); ok($r['allowed']===false && $r['reason']==='cooldown','cooldown meme numero');
$r = throttle_check_and_touch('770000002', 1030, $cfg); ok($r['allowed']===true,'autre numero permis (global=2)');
$r = throttle_check_and_touch('770000001', 1061, $cfg); ok($r['allowed']===true,'permis apres 60s (001 count=2, global=3)');
$r = throttle_check_and_touch('770000001', 1122, $cfg); ok($r['allowed']===true,'3e envoi 001 (cap 3) ok (count=3, global=4)');
$r = throttle_check_and_touch('770000001', 1183, $cfg); ok($r['allowed']===false && $r['reason']==='per_number_cap','4e 001 bloque plafond numero');
$r = throttle_check_and_touch('770000003', 1300, $cfg); ok($r['allowed']===true,'5e envoi global ok');
$r = throttle_check_and_touch('770000004', 1400, $cfg); ok($r['allowed']===false && $r['reason']==='global_cap','6e bloque plafond global');
$r = throttle_check_and_touch('770000004', 1400+90000, $cfg); ok($r['allowed']===true,'reset au changement de jour');
// fail-open si le store est indisponible (parent = fichier, donc non créable)
$blocker = sys_get_temp_dir().'/jak_block_'.uniqid();
file_put_contents($blocker, 'x');
$rf = throttle_check_and_touch('770000009', 2000, ['throttle_path'=>$blocker.'/sub/x.json','otp_secret'=>'s']);
ok($rf['allowed']===true && $rf['reason']==='nostore','fail-open si store indisponible');
@unlink($blocker);
@unlink($tmp);
done();
