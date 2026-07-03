<?php
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/otp.php';
$cfg = ['otp_secret'=>'s3cr3t','otp_ttl'=>300,'otp_max_try'=>5,'otp_resend'=>60];

// Génération
$c = otp_generate();
ok(preg_match('/^\d{6}$/',$c)===1, 'code = 6 chiffres');

// Cooldown
$sess = [];
ok(otp_can_send($sess, 1000, $cfg)===true, 'envoi permis si pas de défi');
otp_set_challenge($sess, 'membre', 'm-a', '770000001', '123456', 1000, $cfg);
ok(otp_can_send($sess, 1030, $cfg)===false, 'renvoi bloqué avant 60 s');
ok(otp_can_send($sess, 1061, $cfg)===true, 'renvoi permis après 60 s');
ok(!isset($sess['code']), 'code jamais stocké en clair');

// Mauvais code incrémente
$r = otp_verify($sess, '000000', 1010, $cfg);
ok($r['ok']===false && $r['reason']==='bad', 'mauvais code refusé');

// Bon code réussit + renvoie contexte
$r = otp_verify($sess, '123456', 1010, $cfg);
ok($r['ok']===true, 'bon code accepté');
eq($r['role'], 'membre', 'role renvoyé');
eq($r['member_id'], 'm-a', 'member_id renvoyé');
ok(empty($sess), 'défi effacé après succès');

// Expiration
$sess=[]; otp_set_challenge($sess,'admin',null,'777301221','654321',2000,$cfg);
$r = otp_verify($sess, '654321', 2000+301, $cfg);
ok($r['ok']===false && $r['reason']==='expired', 'code expiré refusé');
ok(empty($sess), 'défi expiré effacé');

// Blocage après max tentatives
$sess=[]; otp_set_challenge($sess,'admin',null,'777301221','654321',3000,$cfg);
for($i=0;$i<5;$i++){ $r=otp_verify($sess,'000000',3001,$cfg); }
ok($r['reason']==='locked' || empty($sess), 'verrouillé après 5 essais');
$r = otp_verify($sess,'654321',3002,$cfg);
ok($r['ok']===false, 'bon code refusé une fois verrouillé');

done();
