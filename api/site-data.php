<?php
// =============================================================================
//  site-data.php — Projection PUBLIQUE des données du site (V2 / J3)
// =============================================================================
//  Rôle : servir aux 4 pages publiques un JSON frais conforme à window.SITE_DATA,
//         lu en direct depuis PostgreSQL via sql_jsonpro (proxy db.php).
//
//  Différence avec api/admin/get-data.php :
//    - Pas de require_role (anonyme) : on N'expose JAMAIS id ni telephone.
//    - Projection identique à store_regenerate_datajs() : on retire les champs
//      privés de chaque membre `jak` avant la sérialisation.
//
//  Le JS public (site.js) appelle cet endpoint au chargement ; en cas d'échec
//  réseau/serveur, il retombe sur window.SITE_DATA (data.js statique), qui
//  reste le filet jour-J (contrat PRD #2 : dégradé statique garanti).
//
//  Cache : courte durée (60s) côté navigateur pour absorber un pic sans figer
//          trop longtemps les données (les fiches membres éditées doivent
//          apparaître rapidement). Le .htaccess n'applique pas immutable ici.
// =============================================================================

require_once __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/store.php';

$cfg = app_boot();   // positionne Content-Type: application/json + démarre la session

try {
  $data = store_load($cfg);   // lit site_data_json() via sql_jsonpro
} catch (Throwable $e) {
  error_log('[jak-site-data] store_load échec : '.$e->getMessage());
  json_out(['success'=>false, 'error'=>'data_unavailable'], 503);
}

if (empty($data) || empty($data['settings']) || empty($data['jak'])) {
  // Données incomplètes : on préfère laisser le front retomber sur data.js
  // plutôt que de servir une coquille vide qui casserait l'affichage.
  json_out(['success'=>false, 'error'=>'empty_dataset'], 503);
}

// --- Projection publique : on retire id + telephone de chaque membre --------
// (double sécurité : site_data_json() ne les expose pas non plus dans la
//  projection data.js, mais l'admin get-data.php les garde pour la gestion).
if (isset($data['jak']) && is_array($data['jak'])) {
  foreach ($data['jak'] as &$m) {
    if (is_array($m)) {
      unset($m['id'], $m['telephone']);
    }
  }
  unset($m);
}

// Ceinture anti-fuite (cf. store_regenerate_datajs) : aucun téléphone ne doit
// transiter vers le public, même par un canal imprévu.
$serialized = json_encode($data, JSON_UNESCAPED_UNICODE);
if ($serialized !== false &&
    (strpos($serialized, '"telephone":') !== false ||
     preg_match('/"\+\d{6,}"/', $serialized))) {
  error_log('[jak-site-data] ALERTE : téléphone détecté dans la projection — réponse 503.');
  json_out(['success'=>false, 'error'=>'leak_prevented'], 500);
}

// Cache navigateur court : 60s. Suffisant pour absorber un pic, pas trop long
// pour garder les éditions visibles rapidement. must-revalidate pour forcer
// la revalidation après expiration (jamais servi stale).
header('Cache-Control: public, max-age=60, must-revalidate');
json_out(['success'=>true, 'data'=>$data]);
