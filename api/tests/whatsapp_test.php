<?php
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/whatsapp.php';
$cfg = ['whatsapp_url'=>'https://example/send_otp','langue'=>'fr'];

eq(wa_e164('REDACTED'), '+221REDACTED', 'format E.164 +221');

// Transport qui capture le corps et simule un succès
$captured = null;
$t = function($url,$body) use (&$captured){ $captured=['url'=>$url,'body'=>$body]; return ['status'=>200,'json'=>['success'=>true,'message'=>'ok','message_id'=>'abc']]; };
$r = wa_send_otp('REDACTED','123456',$cfg,$t);
ok($r['ok']===true, 'succès WhatsApp');
eq($captured['body']['telephone'], '+221REDACTED', 'telephone E.164 envoyé');
eq($captured['body']['code'], '123456', 'code envoyé');
eq($captured['body']['langue'], 'fr', 'langue fr envoyée');

// Échec métier
$t2 = function($url,$body){ return ['status'=>200,'json'=>['success'=>false,'message'=>'KO','error_code'=>'META_RATE_LIMIT']]; };
$r = wa_send_otp('REDACTED','123456',$cfg,$t2);
ok($r['ok']===false && $r['error_code']==='META_RATE_LIMIT', 'échec métier remonté');

done();
