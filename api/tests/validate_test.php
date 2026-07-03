<?php
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/validate.php';

$data = ['jak'=>[
  ['id'=>'m-a','telephone'=>'770000001'],
  ['id'=>'m-b','telephone'=>'770000002'],
]];

// Cas valide
$r = validate_member_fields(
  ['nom_complet'=>'Ablaye','adresse'=>'Dakar','telephone'=>'771112233','biographie'=>'Bio','photo'=>'data:image/jpeg;base64,AAAA'],
  $data, 'm-a');
eq($r['errors'], [], 'aucune erreur sur entrée valide');
eq($r['fields']['telephone'], '771112233', 'téléphone conservé');

// Téléphone mauvais format
$r = validate_member_fields(['telephone'=>'12345'], $data, 'm-a');
ok(in_array('telephone', array_map(fn($e)=>explode(':',$e)[0], $r['errors'])) || count($r['errors'])>0, 'téléphone 5 chiffres rejeté');

// Téléphone déjà pris par un AUTRE membre
$r = validate_member_fields(['telephone'=>'770000002'], $data, 'm-a');
ok(count($r['errors'])>0, 'téléphone d’un autre membre rejeté');

// Garder son propre téléphone est autorisé
$r = validate_member_fields(['telephone'=>'770000001'], $data, 'm-a');
eq($r['errors'], [], 'garder son propre numéro est OK');

// Nom trop long
$r = validate_member_fields(['nom_complet'=>str_repeat('x',200)], $data, 'm-a');
ok(count($r['errors'])>0, 'nom > 120 rejeté');

// id/fondateur ignorés (non modifiables)
$r = validate_member_fields(['id'=>'hack','fondateur'=>'OUI','nom_complet'=>'Ok'], $data, 'm-a');
ok(!array_key_exists('id',$r['fields']) && !array_key_exists('fondateur',$r['fields']), 'id/fondateur non retenus');

done();
