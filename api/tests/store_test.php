<?php
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/store.php';

$tmp = sys_get_temp_dir().'/jak_test_'.uniqid();
$cfg = ['data_path'=>$tmp.'.json', 'datajs_path'=>$tmp.'.js'];
$data = ['settings'=>['x'=>1], 'jak'=>[
  ['id'=>'m-a','telephone'=>'770000001','nom_complet'=>'A','biographie'=>'ba','adresse'=>'aa','photo'=>'p1','fondateur'=>'NON','fonction'=>''],
  ['id'=>'m-b','telephone'=>'770000002','nom_complet'=>'B','biographie'=>'bb','adresse'=>'ab','photo'=>'p2','fondateur'=>'OUI','fonction'=>'Prés'],
]];

store_save($cfg, $data);
ok(is_file($cfg['data_path']), 'data.json écrit');
ok(is_file($cfg['datajs_path']), 'data.js régénéré');
$js = file_get_contents($cfg['datajs_path']);
ok(str_starts_with($js,'window.SITE_DATA='), 'data.js commence par window.SITE_DATA=');
ok(str_ends_with(trim($js),';'), 'data.js finit par ;');

$reloaded = store_load($cfg);
eq($reloaded['settings']['x'], 1, 'store_load relit settings');

$m = member_find_by_phone($data, '770000002');
eq($m['id'], 'm-b', 'find_by_phone trouve m-b');
eq(member_find_by_phone($data, '999999999'), null, 'find_by_phone inconnu = null');
eq(member_find_by_id($data,'m-a')['nom_complet'], 'A', 'find_by_id trouve A');

$okUpd = member_apply_update($data, 'm-a', ['nom_complet'=>'AA','telephone'=>'770000009','adresse'=>'x','biographie'=>'y','photo'=>'z']);
ok($okUpd===true, 'update renvoie true');
eq($data['jak'][0]['nom_complet'], 'AA', 'nom mis à jour');
eq($data['jak'][0]['telephone'], '770000009', 'téléphone mis à jour');
eq($data['jak'][0]['id'], 'm-a', 'id inchangé');
ok(member_apply_update($data,'inconnu',[])===false, 'update id inconnu = false');

$js = file_get_contents($cfg['datajs_path']);
ok(strpos($js,'770000001')===false && strpos($js,'m-a')===false, 'data.js public ne contient ni telephone ni id');
$jsonRaw = file_get_contents($cfg['data_path']);
ok(strpos($jsonRaw,'770000001')!==false && strpos($jsonRaw,'m-a')!==false, 'data.json canonique contient bien telephone et id');

@unlink($cfg['data_path']); @unlink($cfg['datajs_path']);
done();
