<?php
// =============================================================================
//  store_test.php — Tests store + projection data.js (V2 / J1)
// =============================================================================
//  V1 : testait store_save/store_load sur fichiers + member_find_by_phone
//       depuis le tableau data en mémoire.
//  V2 : la source canonique est la base. On teste :
//       - store_load() lit la base (site_data_json) avec les 5 clés attendues
//       - store_regenerate_datajs() produit un data.js SANS id/telephone
//       - member_find_by_phone() / member_find_by_id() via la base (row_to_json)
//       - member_to_front() projette une ligne personne pour le front
//
//  Exécution :
//    C:\php\php.exe api\tests\store_test.php
// =============================================================================
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/config.php';
require __DIR__.'/../lib/db.php';
require __DIR__.'/../lib/store.php';
$cfg = require __DIR__.'/../lib/config.php';
// data.js dans un tmp pour ne pas écraser l'asset du projet pendant le test.
$cfg['datajs_path'] = sys_get_temp_dir().'/jak_store_test_'.uniqid().'.js';

// --- store_load : lit la base, 5 clés attendues ---
try {
  $data = store_load($cfg);
  ok(is_array($data) && isset($data['jak']), 'store_load retourne un tableau avec jak');
  $expected = ['settings', 'cheikh', 'dignitaires', 'jak', 'galerie'];
  $missing = array_diff($expected, array_keys($data));
  ok(!$missing, 'store_load contient les 5 clés (manquantes: '.implode(',', $missing).')');
  ok(count($data['jak']) > 0, 'jak contient des membres');
} catch (Throwable $e) {
  ok(false, 'store_load a échoué : '.$e->getMessage());
}

// --- store_regenerate_datajs : projection sans id/telephone ---
try {
  store_regenerate_datajs($cfg);
  $js = file_get_contents($cfg['datajs_path']);
  ok(str_starts_with($js, 'window.SITE_DATA='), 'data.js commence par window.SITE_DATA=');
  ok(str_ends_with(trim($js), ';'), 'data.js finit par ;');
  // Aucun téléphone (E.164) dans l'asset public.
  ok(preg_match('/\+221\d{9}|\+33\d{9}/', $js) === 0, 'data.js public SANS téléphone');
  // Aucun slug membre m-xxx.
  ok(preg_match('/m-[a-z]/', $js) === 0, 'data.js public SANS id membre (slug)');
} catch (Throwable $e) {
  ok(false, 'store_regenerate_datajs a échoué : '.$e->getMessage());
}

// --- member_find_by_phone / member_find_by_id : lookup d'intégration ---
// Les données de test (vrai numéro/slug/id d'un membre existant en base) sont
// externalisées dans config.php (gitignoré) : AUCUN vrai numéro de membre n'est
// committé dans le dépôt. Si la config de test est absente → skip explicite.
$testPhone = $cfg['test_member_phone'] ?? '';
$testSlug  = $cfg['test_member_slug'] ?? '';
$testId    = $cfg['test_member_id']   ?? null;
if ($testPhone === '' || $testSlug === '' || $testId === null) {
  echo "  SKIP: member_find_by_phone/id — config de test absente (test_member_phone/slug/id dans config.php)\n";
} else {
  try {
    $m = member_find_by_phone($cfg, $testPhone);
    ok($m !== null, 'member_find_by_phone trouve le membre');
    ok(($m['slug'] ?? '') === $testSlug, 'slug correct');
    ok(($m['telephone'] ?? '') === $testPhone, 'téléphone E.164');
    ok(member_find_by_phone($cfg, '+221000000000') === null, 'numéro inconnu → null');
  } catch (Throwable $e) {
    ok(false, 'member_find_by_phone a échoué : '.$e->getMessage());
  }

  try {
    $m = member_find_by_id($cfg, (int)$testId);
    ok($m !== null && ($m['slug'] ?? '') === $testSlug, 'member_find_by_id trouve le membre');
    ok(member_find_by_id($cfg, -1) === null, 'id inexistant → null');
  } catch (Throwable $e) {
    ok(false, 'member_find_by_id a échoué : '.$e->getMessage());
  }
}

// --- member_to_front : projection pour auth.js ---
$p = ['slug'=>'m-test', 'url_photo'=>'img.png', 'nom_complet'=>'Test', 'adresse'=>'Dakar', 'telephone'=>'+221777000000', 'biographie'=>'bio', 'fonction'=>'Membre', 'fondateur'=>true];
$f = member_to_front($p);
eq($f['id'], 'm-test', 'member_to_front: id = slug');
eq($f['photo'], 'img.png', 'member_to_front: url_photo → photo');
eq($f['telephone'], '+221777000000', 'member_to_front: telephone conservé (pour pré-remplir le form)');

// --- Whitelist : rejet fonction non autorisée ---
try {
  db_call_function('fonction_bidon_inexistante', [1], $cfg);
  ok(false, 'whitelist aurait dû rejeter fonction_bidon_inexistante');
} catch (InvalidArgumentException $e) {
  ok(true, 'whitelist rejette fonction hors liste');
} catch (Throwable $e) {
  ok(false, 'mauvaise exception : '.get_class($e));
}

@unlink($cfg['datajs_path']);
done();
