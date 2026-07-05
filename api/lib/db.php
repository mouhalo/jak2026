<?php
// =============================================================================
//  db.php — Client PHP de l'API HTTP sql_jsonpro (V2 / J1)
// =============================================================================
//  Rôle : proxy médiateur UNIQUE entre le runtime applicatif et PostgreSQL.
//         Le PHP ne se connecte jamais en PDO direct (contrainte PRD #1) :
//         tout accès DB passe par sql_jsonpro, en appelant des FONCTIONS
//         PL/pgSQL whitelistées (jamais de SQL construit côté client).
//
//  Architecture en 3 couches de défense :
//    1. Autorisation par rôle aux endpoints (require_role) — contrôle principal.
//    2. db_call_function() n'accepte que des noms de fonction whitelistés.
//    3. sql_jsonpro restreint par application ('jaksn') côté serveur.
//
//  Contrat sql_jsonpro (sondé empiriquement 2026-07-04, voir mémoire projet) :
//    POST {application, query} →
//      succès : {status:"success", code:"QUERY_SUCCESS", data:{rows:[...], rowCount, duration}}
//      erreur : {status:"error", code:"QUERY_ERROR"|"INTERNAL_ERROR", message, error}
//    ⚠ Le proxy plante (IndexError/500) sur les requêtes contenant '%' (LIKE) :
//      éviter le LIKE ; préférer des fonctions PL/pgSQL paramétrées.
// =============================================================================

// -----------------------------------------------------------------------------
//  Whitelist des fonctions PL/pgSQL autorisées via ce proxy.
//  Défense en profondeur (PAS le contrôle d'accès principal — cf. require_role).
//  Une fonction privée/admin listée ici n'est PAS accessible à un membre ou
//  anonyme : l'endpoint appelant impose son rôle AVANT l'appel.
// -----------------------------------------------------------------------------
const DB_ALLOWED_FUNCTIONS = [
  // Lecture publique / pivot site
  'site_data_json',
  'evenement_lire_courant',
  'evenement_lire',
  'galerie_lister',
  'galerie_lire',
  'cheikh_lire',
  // Lookup membre (OTP + verify)
  'personne_par_telephone',
  'personne_par_id',
  'personne_lire',
  // OTP (défis en base)
  'otp_creer',
  'otp_verifier',
  // Édition membre (cloisonnée id+tel côté SQL)
  'membre_sauver_fiche',
  // Admin — CRUD ciblé (accès restreint par require_role à l'endpoint)
  'personne_lister',
  'personne_modifier',
  'personne_creer',
  'personne_supprimer',
  'cheikh_modifier',
  'cheikh_citation_ajouter',
  'cheikh_citation_modifier',
  'cheikh_citation_supprimer',
  'evenement_modifier',
  'galerie_creer',
  'galerie_modifier',
  'galerie_supprimer',
  'programme_jour_creer',
  'programme_jour_modifier',
  'programme_jour_supprimer',
  'numero_contact_creer',
  'numero_contact_modifier',
  'numero_contact_supprimer',
  // ═══ Chantier 2 — Dons (Wave / Orange Money) ═══
  // Cloisonnement : create.php/liste.php sont publics (anonymes) ; les fonctions
  // de confirmation (don_confirmer/don_echouer/don_attacher_uuid/soutien_upsert/
  // journal_ajouter) ne sont appelées QUE par les endpoints serveur internes
  // (retour.php, status.php, reconcile.php), jamais exposées directement au client.
  'don_creer',          // public : create.php
  'soutien_lister',     // public : liste.php (carrousel — ni montant ni tel clair)
  'don_attacher_uuid',  // interne : create.php (après réponse pay_services)
  'don_confirmer',      // interne : retour.php / status.php / reconcile.php (idempotent)
  'don_echouer',        // interne : idem (idempotent)
  'soutien_upsert',     // interne : appelé par don_confirmer via SQL
  'journal_ajouter',    // interne : audit
  'numero_mask',        // interne : masquage téléphone (utilisé par don_creer via SQL)
];

/**
 * Exécute une requête SQL brute via sql_jsonpro.
 *
 * Réservé à un usage interne contrôlé (db_call_function). Ne JAMAIS exposer
 * de SQL construit côté client via cette fonction.
 *
 * @param string $sql  Requête SQL (typiquement SELECT f(args) AS data)
 * @param array  $cfg  Config app (clés sql_jsonpro_url, app_name)
 * @return array       Tableau de lignes (data.rows)
 * @throws RuntimeException  En cas d'erreur réseau, JSON invalide, ou erreur SQL.
 */
function db_query(string $sql, array $cfg): array {
  $payload = json_encode([
    'application' => $cfg['app_name'] ?? 'jaksn',
    'query'       => $sql,
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init($cfg['sql_jsonpro_url']);
  // Résolution du bundle CA pour la vérif SSL. On ne DÉSACTIVE JAMAIS la
  // vérif (CURLOPT_SSL_VERIFYPEER toujours true) ; on pointe juste vers un
  // CA valide. En prod, le bundle système suffit (ca_bundle='').
  $caBundle = $cfg['ca_bundle'] ?? '';
  if (!$caBundle) {
    foreach (($cfg['ca_bundle_candidates'] ?? []) as $cand) {
      if (@is_file($cand)) { $caBundle = $cand; break; }
    }
  }
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: '.strlen($payload)],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,   // TOUJOURS vérifié.
    CURLOPT_SSL_VERIFYHOST => 2,
  ]);
  if ($caBundle) {
    curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
  }
  $raw  = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($raw === false) {
    throw new RuntimeException('sql_jsonpro injoignable : '.$err, 502);
  }
  $resp = json_decode($raw, true);
  if (!is_array($resp)) {
    throw new RuntimeException('sql_jsonpro : réponse non-JSON (HTTP '.$code.')', 502);
  }

  // Enveloppe d'erreur sql_jsonpro.
  if (($resp['status'] ?? '') !== 'success') {
    $msg    = $resp['message'] ?? 'erreur SQL inconnue';
    $sqlerr = $resp['code'] ?? ($resp['error']['type'] ?? 'UNKNOWN');
    // Les erreurs PostgreSQL (QUERY_ERROR) sont des 422 logiques ; on les
    // laisse remonter avec un message lisible pour le debug.
    throw new RuntimeException('sql_jsonpro ['.$sqlerr.'] : '.$msg, 422);
  }

  return $resp['data']['rows'] ?? [];
}

/**
 * Appelle une fonction PL/pgSQL whitelistée et retourne son résultat.
 *
 * Construit `SELECT name(args) AS data` et retourne la valeur de la colonne
 * `data` de la première ligne, ou null si aucune ligne.
 *
 * ⚠ Pour les fonctions retournant un TYPE COMPOSITE (ex. `personne`, un
 * RECORD), sql_jsonpro sérialise le résultat en chaîne tuple PostgreSQL
 * "(25,1,membre,...)" NON exploitable. Utiliser alors db_call_row_function()
 * qui enveloppe dans row_to_json() pour obtenir un vrai objet JSON.
 *
 * SÉCURITÉ :
 *  - $name DOIT figurer dans DB_ALLOWED_FUNCTIONS (sinon exception).
 *  - $args sont passés via db_quote() (échappement SQL-safe par typage).
 *  - Le nom est validé par regex (^[a-z0-9_]+$) avant toute interpolation.
 *
 * @param string $name  Nom de la fonction PL/pgSQL (ex. 'site_data_json')
 * @param array  $args  Arguments positionnels (ex. [1] ou ['+221700000000', 'hash'])
 * @param array  $cfg   Config app
 * @return mixed        Valeur retournée par la fonction (json/array/string/null)
 * @throws InvalidArgumentException  Si la fonction n'est pas whitelistée.
 * @throws RuntimeException          En cas d'erreur SQL/réseau.
 */
function db_call_function(string $name, array $args, array $cfg): mixed {
  // Défense #2 : whitelist stricte.
  if (!in_array($name, DB_ALLOWED_FUNCTIONS, true)) {
    throw new InvalidArgumentException('Fonction DB non autorisée : '.$name, 500);
  }
  // Garde-fou : le nom doit être un identifiant SQL sûr (lettres, chiffres,
  // underscore) — anticipe les futurs noms versionnés type 'soutien_v2'.
  if (!preg_match('/^[a-z0-9_]+$/', $name)) {
    throw new InvalidArgumentException('Nom de fonction invalide : '.$name, 500);
  }

  // Construction de la liste d'arguments SQL-safe (db_quote ou db_raw).
  $sqlArgs = _db_build_args($args);
  $sql = 'SELECT '.$name.'('.implode(', ', $sqlArgs).') AS data';

  $rows = db_query($sql, $cfg);
  if (!$rows) return null;
  $first = $rows[0];
  return array_key_exists('data', $first) ? $first['data'] : $first;
}

/**
 * Appelle une fonction retournant un TYPE COMPOSITE (RECORD) et le renvoie
 * comme objet JSON associatif exploitable.
 *
 * Construit `SELECT row_to_json(name(args)) AS data`. sql_jsonpro ne décompose
 * pas les types composites ; row_to_json() force la sérialisation en objet JSON.
 *
 * Mêmes sécurités que db_call_function (whitelist + échappement).
 *
 * @param string $name
 * @param array  $args
 * @param array  $cfg
 * @return array|null   Objet JSON de la ligne, ou null si aucune ligne.
 */
function db_call_row_function(string $name, array $args, array $cfg): ?array {
  if (!in_array($name, DB_ALLOWED_FUNCTIONS, true)) {
    throw new InvalidArgumentException('Fonction DB non autorisée : '.$name, 500);
  }
  if (!preg_match('/^[a-z0-9_]+$/', $name)) {
    throw new InvalidArgumentException('Nom de fonction invalide : '.$name, 500);
  }
  $sqlArgs = _db_build_args($args);
  // row_to_json autour de l'appel → objet JSON propre.
  $sql = 'SELECT row_to_json('.$name.'('.implode(', ', $sqlArgs).')) AS data';
  $rows = db_query($sql, $cfg);
  if (!$rows) return null;
  $first = $rows[0];
  $data = $first['data'] ?? null;
  return is_array($data) ? $data : null;
}

/**
 * Échappe/typifie une valeur PHP pour interpolation SQL-safe dans un SELECT.
 *
 * On n'utilise pas de paramètres bindés (sql_jsonpro prend du SQL brut) : on
 * type donc explicitement chaque valeur pour éviter toute injection.
 *
 * - null      → NULL
 * - bool      → TRUE/FALSE
 * - int/float → littéral numérique (integer)
 * - string    → quoté en '...' avec échappement '' (doubles apostrophes)
 *
 * ⚠ PostgreSQL coerce automatiquement integer→bigint (widening) mais REFUSE
 * integer→smallint (narrowing). Pour les fonctions attendant un smallint
 * (ex. site_data_json(p_evenement_id smallint)), utiliser db_cast().
 *
 * @param mixed $v
 * @return string
 */
function db_quote(mixed $v): string {
  if ($v === null) return 'NULL';
  if (is_bool($v)) return $v ? 'TRUE' : 'FALSE';
  if (is_int($v) || is_float($v)) return (string)$v;
  // string : échappement PostgreSQL standard (doubles apostrophes).
  // La base tourne avec standard_conforming_strings=on (défaut PostgreSQL,
  // vérifié empiriquement) : dans un littéral '...' l'antislash est LITTÉRAL,
  // il ne faut donc PAS le doubler (le doubler corromprait les données en
  // insérant un antislash parasite). Seule l'apostrophe doit être neutralisée
  // (doublement ''). On retire aussi les null bytes par sécurité.
  $s = str_replace("\0", '', (string)$v);
  $s = str_replace("'", "''", $s);
  return "'".$s."'";
}

/**
 * Échappe une valeur ET applique un cast explicite vers un type PostgreSQL.
 *
 * Indispensable pour les arguments smallint (PostgreSQL ne coerce pas
 * integer→smallint automatiquement), ou tout type où l'inférence est ambiguë.
 *
 * Retourne un fragment SQL BRUT (déjà échappé+casté) à passer tel quel dans
 * le tableau $args de db_call_function : celui-ci détecte les fragments
 * marqués (db_raw) et ne les re-quote pas.
 *
 * Exemples :
 *   db_cast(1, 'smallint')         → fragment "1::smallint"
 *   db_cast('+221...', 'varchar')  → fragment "'+221...'::varchar"
 *
 * @param mixed  $value
 * @param string $type  Nom du type SQL cible (smallint, bigint, text, varchar, ...)
 * @return array  Fragment SQL brut à passer dans $args (cf. db_raw).
 */
function db_cast(mixed $value, string $type): array {
  return db_raw(db_quote($value).'::'.$type);
}

/**
 * Encapsule un fragment SQL déjà formé pour qu'il ne soit PAS re-quoté par
 * db_call_function. À utiliser pour les valeurs nécessitant un cast explicite
 * (db_cast) ou toute expression SQL sûre contrôlée côté serveur.
 *
 *   db_call_function('site_data_json', [db_raw('1::smallint')], $cfg)
 *
 * @param string $sql  Fragment SQL déjà échappé/typé (JAMAIS de saisie client).
 * @return array       Marqueur reconnu par db_call_function.
 */
function db_raw(string $sql): array {
  return ['__raw__' => true, 'sql' => $sql];
}

/**
 * Construit la liste d'arguments SQL-safe : db_quote pour les valeurs,
 * passage direct pour les fragments db_raw (castés ou expressions).
 */
function _db_build_args(array $args): array {
  $out = [];
  foreach ($args as $a) {
    if (is_array($a) && ($a['__raw__'] ?? false)) {
      $out[] = $a['sql'];   // fragment déjà formé (cast, expression).
    } else {
      $out[] = db_quote($a);
    }
  }
  return $out;
}
