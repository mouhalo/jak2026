<?php
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/whatsapp.php';
$cfg = ['whatsapp_url'=>'https://example/send_otp','langue'=>'fr'];

// wa_send_otp reçoit désormais un E.164 COMPLET et l'envoie tel quel.
$captured = null;
$t = function($url,$body) use (&$captured){ $captured=['url'=>$url,'body'=>$body]; return ['status'=>200,'json'=>['success'=>true,'message'=>'ok','message_id'=>'abc']]; };

$r = wa_send_otp('+221700000000','123456',$cfg,$t);
ok($r['ok']===true, 'succès WhatsApp');
eq($captured['body']['telephone'], '+221700000000', 'E.164 SN transmis tel quel');
eq($captured['body']['code'], '123456', 'code envoyé');
eq($captured['body']['langue'], 'fr', 'langue fr envoyée');

// Multi-pays : jamais de +221 forcé pour un non-221.
wa_send_otp('+2250700000000','123456',$cfg,$t);
eq($captured['body']['telephone'], '+2250700000000', 'E.164 CI transmis (pas de +221)');
wa_send_otp('+33700000000','123456',$cfg,$t);
eq($captured['body']['telephone'], '+33700000000', 'E.164 FR transmis (pas de +221)');

// Échec métier
$t2 = function($url,$body){ return ['status'=>200,'json'=>['success'=>false,'message'=>'KO','error_code'=>'META_RATE_LIMIT']]; };
$r = wa_send_otp('+221700000000','123456',$cfg,$t2);
ok($r['ok']===false && $r['error_code']==='META_RATE_LIMIT', 'échec métier remonté');

done();
