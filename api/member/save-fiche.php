<?php
// =============================================================================
//  save-fiche.php — Édition de sa fiche par un membre (V2 / J1)
// =============================================================================
//  V1 : member_apply_update() modifiait le tableau data en mémoire, puis
//       store_save() réécrivait data.json + data.js.
//  V2 : membre_sauver_fiche(p_personne_id, p_telephone, ...) en base —
//       cloisonnement par DOUBLE-CLÉ (id AND telephone) côté SQL (décision #3).
//       Le téléphone vient de la SESSION authentifiée, JAMAIS du body client.
//       Après sauvegarde, on régénère data.js depuis site_data_json().
//
//  Validation : on garde validate_member_fields() pour les limites de longueur
//       et le format photo. La validation téléphone du body est ignorée pour
//       l'autorisation (le téléphone d'identité = session), mais si le membre
//       souhaite changer son numéro, c'est un flux séparé (hors périmètre J1 —
//       la table impose telephone UNIQUE et la fiche est liée au tel de login).
// =============================================================================
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/store.php';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/validate.php';
$cfg = app_boot();
$a = require_role('membre', $cfg);

$in = read_body();
// Validation des champs éditables (longueurs, format photo).
$v = validate_member_fields($in, ['jak'=>[]], (string)($a['personne_id'] ?? ''));
if ($v['errors']) json_out(['success'=>false,'message'=>'Champs invalides','errors'=>$v['errors']], 422);

$personneId = $a['personne_id'] ?? null;
$telephone  = $a['telephone'] ?? null;   // Téléphone d'identité issu de la session.
if (!$personneId || !$telephone) {
  json_out(['success'=>false,'message'=>'Session membre incomplète'], 401);
}

$f = $v['fields'];
// membre_sauver_fiche(p_personne_id, p_telephone, p_nom_complet, p_url_photo, p_adresse, p_biographie).
// On ne passe que les champs réellement fournis (les autres à NULL → inchangés en base).
$args = [
  $personneId,
  $telephone,
  array_key_exists('nom_complet', $f) ? $f['nom_complet'] : null,
  array_key_exists('photo', $f)      ? $f['photo']       : null,
  array_key_exists('adresse', $f)    ? $f['adresse']     : null,
  array_key_exists('biographie', $f) ? $f['biographie']  : null,
];

try {
  // membre_sauver_fiche retourne un RECORD personne → row_to_json.
  $updated = db_call_row_function('membre_sauver_fiche', $args, $cfg);
} catch (Throwable $e) {
  $msg = $e->getMessage();
  if (stripos($msg, 'introuvable') !== false) {
    json_out(['success'=>false,'message'=>'Fiche introuvable (id/tél incohérents)'], 404);
  }
  error_log('[jak-member] membre_sauver_fiche échec : '.$msg);
  json_out(['success'=>false,'message'=>'Sauvegarde impossible pour le moment.'], 500);
}

if (!$updated) {
  json_out(['success'=>false,'message'=>'Fiche introuvable'], 404);
}

// Régénère data.js public depuis la base (projection sans id/telephone).
store_regenerate_datajs($cfg);

json_out(['success'=>true, 'member'=>member_to_front($updated)]);
