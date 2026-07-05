<?php
// =============================================================================
//  validate.php — Validation des champs éditables d'une fiche membre (V2 / J1)
// =============================================================================
//  V1 : validait aussi le téléphone (unicité contre data['jak']).
//  V2 : le téléphone d'IDENTITÉ vient de la session authentifiée (post-OTP),
//       pas du body client. L'édition via save-fiche ne change PAS le numéro de
//       login (le cloisonnement membre_sauver_fiche repose sur id+tel fixes).
//       On ne valide donc plus le téléphone ici : tout champ 'telephone' du body
//       est ignoré pour la sauvegarde (la fiche affiche le tel de session).
//
//  Restent validés : nom_complet, adresse, biographie (longueurs), photo (format).
// =============================================================================
function validate_member_fields(array $in, array $data, string $selfId): array {
  $errors = []; $fields = [];
  $limits = ['nom_complet'=>120, 'adresse'=>160, 'biographie'=>2000];

  foreach ($limits as $k => $max) {
    if (array_key_exists($k, $in)) {
      $v = trim((string)$in[$k]);
      if (mb_strlen($v) > $max) { $errors[] = "$k:trop long (max $max)"; }
      else { $fields[$k] = $v; }
    }
  }

  // Le téléphone du body est ignoré en V2 (l'identité = session post-OTP).
  // On ne le valide ni ne l'enregistre via ce flux.

  if (array_key_exists('photo', $in)) {
    $p = (string)$in['photo'];
    $isData = str_starts_with($p, 'data:image/');
    $isPath = preg_match('#^[\w./\-]+\.(png|jpe?g|webp)$#i', $p);
    if ($p !== '' && !$isData && !$isPath) { $errors[] = 'photo:format invalide'; }
    elseif ($isData && strlen($p) > 2_000_000) { $errors[] = 'photo:image trop lourde'; }
    else { $fields['photo'] = $p; }
  }

  return ['errors'=>$errors, 'fields'=>$fields];
}
