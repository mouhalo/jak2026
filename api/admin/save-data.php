<?php
// =============================================================================
//  save-data.php — Sauvegarde admin globale (V2 / J1 — MODE DÉGRADÉ)
// =============================================================================
//  V1 : écrasement global de data.json via store_save(), puis data.js régénéré.
//  V2 : la source canonique est la BASE. Un écrasement global complet nécessite
//       de mapper chaque section du JSON sur des fonctions CRUD ciblées
//       (personne_modifier, evenement_modifier, ...) — travail non trivial qui
//       relève d'une admin avancée (post-J1).
//
//  Pour J1, on désactive l'écrasement global (sécurité : éviter une réécriture
//  sauvage de la base depuis un payload client) et on se contente de
//  régénérer data.js depuis l'état courant de la base. L'édition admin ciblée
//  (une fiche à la fois) se fera via des endpoints dédiés à venir.
//
//  TODO (post-J1) : endpoints admin ciblés par entité (admin/personne/save.php,
//  admin/evenement/save.php, ...) appelant les fonctions CRUD PL/pgSQL.
// =============================================================================
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/store.php';
$cfg = app_boot();
require_role('admin', $cfg);

// On valide toujours la structure reçue (détection d'erreurs côté client).
$in = read_body();
$data = $in['data'] ?? null;
if (!is_array($data) || !isset($data['jak']) || !is_array($data['jak']) || !isset($data['settings']) || !is_array($data['settings'])) {
  json_out(['success'=>false,'message'=>'Données invalides'], 422);
}

// J1 : régénération de data.js depuis la base (la base reste la source de vérité).
// L'écrasement global est désactivé — l'édition admin se fera par endpoints ciblés.
store_regenerate_datajs($cfg);
json_out(['success'=>true, 'message'=>'data.js régénéré depuis la base. Édition admin ciblée à venir (post-J1).']);
