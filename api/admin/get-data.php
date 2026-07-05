<?php
// =============================================================================
//  get-data.php — Lecture admin des données complètes (V2 / J1)
// =============================================================================
//  V1 : store_load() lisait data.json (avec champs privés id/telephone).
//  V2 : store_load() lit désormais la base (site_data_json, qui contient TOUS
//       les champs y compris id/telephone). L'admin a besoin de ces champs
//       privés pour gérer les membres → on les garde dans cette réponse ( rôle
//       admin imposé par require_role, jamais servi au public ).
// =============================================================================
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/store.php';
$cfg = app_boot();
require_role('admin', $cfg);
json_out(['success'=>true, 'data'=>store_load($cfg)]);
