<?php
// =============================================================================
//  store.php — Source canonique des données du site (V2 / J1)
// =============================================================================
//  V1 : data.json lu/écrit en fichier ; data.js régénéré par projection.
//  V2 : la base PostgreSQL est la source canonique. On lit via site_data_json()
//       (proxy sql_jsonpro → db.php). data.js reste le fallback statique jour-J,
//       régénéré depuis la base à chaque écriture. data.json devient un cache
//       optionnel (conservé pour l'admin qui a encore besoin des champs privés).
//
//  Contrat window.SITE_DATA = point de compatibilité intangible (PRD #2) :
//  data.js ne contient JAMAIS id ni telephone des membres (décision utilisateur).
// =============================================================================

require_once __DIR__.'/db.php';

/**
 * Charge les données canoniques depuis la base (site_data_json).
 *
 * Retourne la structure {settings, cheikh, dignitaires, jak, galerie} déjà
 * conforme au contrat window.SITE_DATA (mais AVEC telephone/id en clair —
 * la projection publique se fait dans store_regenerate_datajs).
 *
 * @param array $cfg
 * @return array  Structure SITE_DATA complète (avec champs privés).
 */
function store_load(array $cfg): array {
  try {
    // site_data_json(p_evenement_id smallint DEFAULT 1) — cast smallint requis
    // car PostgreSQL ne coerce pas integer→smallint (narrowing interdit).
    $data = db_call_function('site_data_json', [db_cast(1, 'smallint')], $cfg);
    return is_array($data) ? $data : [];
  } catch (Throwable $e) {
    // Mode dégradé : si la base est injoignable, on retombe sur data.json
    // (cache local) pour ne pas casser le site public en lecture.
    $path = $cfg['data_path'] ?? null;
    if ($path && is_file($path)) {
      $raw = file_get_contents($path);
      $d = json_decode($raw, true);
      return is_array($d) ? $d : [];
    }
    error_log('[jak-store] store_load DB échec et pas de cache : '.$e->getMessage());
    return [];
  }
}

/**
 * Régénère data.js PUBLIC depuis la base : projection sans champs privés.
 *
 * Lit site_data_json() (avec telephone/id en clair côté base), supprime
 * `id` et `telephone` de chaque membre `jak` (décision utilisateur #2),
 * puis écrit data.js atomiquement. C'est l'équivalent V2 du bloc de
 * projection qui était dans store_save() en V1.
 *
 * @param array $cfg
 */
function store_regenerate_datajs(array $cfg): void {
  $data = store_load($cfg);
  $public = $data;
  // Projection : on retire id + telephone des membres (jamais d'asset public).
  if (isset($public['jak']) && is_array($public['jak'])) {
    foreach ($public['jak'] as &$m) {
      if (is_array($m)) { unset($m['id'], $m['telephone']); }
    }
    unset($m);
  }
  // --- Garde 1 : refuser d'écrire une projection vide/incomplète ---------------
  // store_load() renvoie [] si la base est injoignable ET que le cache data.json
  // est absent/illisible. Écrire tel quel produirait `window.SITE_DATA=[];`, ce
  // qui casse les 4 pages publiques (elles lisent .settings/.jak/...). On préserve
  // alors l'asset valide déjà en place plutôt que de l'écraser (filet jour-J).
  if (empty($public['settings']) || empty($public['jak']) || !is_array($public['jak'])) {
    error_log('[jak-store] projection vide/incomplète : data.js NON réécrit (préservation du filet).');
    return;
  }

  $js = 'window.SITE_DATA='.json_encode($public, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).";\n";

  // --- Garde 2 : ceinture anti-fuite -------------------------------------------
  // Jamais de téléphone dans l'asset public, même si une future évolution de
  // site_data_json() fuitait par un canal non filtré (ex. dignitaires[]). Si la
  // sortie contient encore un numéro E.164 en valeur de chaîne JSON ("+\d{6,}")
  // ou une clé "telephone", on refuse d'écrire et on préserve l'asset existant.
  // NB : on ancre le motif sur les guillemets de chaîne JSON. Un « +chiffres » nu
  // apparaît légitimement dans les blobs base64 des photos embarquées (ex. « +267441 »)
  // et provoquerait un faux positif ; à l'intérieur d'une chaîne JSON tout guillemet
  // interne est échappé, donc un " nu ne borne qu'une vraie valeur (le téléphone fuité).
  if (preg_match('/"\+\d{6,}"/', $js) || strpos($js, '"telephone":') !== false) {
    error_log('[jak-store] ALERTE : téléphone détecté dans la projection publique : data.js NON réécrit.');
    return;
  }

  _atomic_write($cfg['datajs_path'], $js);
}

/**
 * Écriture atomique d'un fichier (réutilisé par data.js et le cache data.json).
 *
 * Sous Linux, `rename(tmp, path)` est atomique et remplace la cible même si
 * elle est ouverte. Sous Windows, `rename` échoue (code 5 « Accès refusé »)
 * dès que le fichier cible est verrouillé par un autre processus (navigateur
 * qui sert data.js, éditeur, antivirus). On retente donc quelques fois avec
 * un court délai, puis on retombe sur un `file_put_contents(LOCK_EX)` direct
 * (moins atomique mais robuste au verrou en écriture).
 */
function _atomic_write(string $path, string $content): void {
  $tmp = $path.'.tmp'.getmypid();
  if (file_put_contents($tmp, $content, LOCK_EX) === false) {
    error_log('[jak-store] échec écriture fichier temporaire : '.$tmp);
    return;  // on n'écrase pas la cible si le tmp n'a pas pu être écrit.
  }
  // Tentatives de rename atomique (jusqu'à ~1 s).
  for ($i = 0; $i < 5; $i++) {
    if (@rename($tmp, $path)) {
      return;  // succès
    }
    usleep(200000);  // 0,2 s entre tentatives (laisser le verrou se libérer).
  }
  // Fallback Windows : écriture directe avec verrou exclusif. Le .tmp est
  // ignoré (il sera écrasé au prochain appel). Moins atomique mais fiable.
  if (@file_put_contents($path, $content, LOCK_EX) === false) {
    error_log('[jak-store] échec écriture directe (fallback) : '.$path);
  }
  @unlink($tmp);
}

/**
 * Recherche un membre par téléphone (depuis la base).
 *
 * @param array  $cfg
 * @param string $phone  Téléphone au format E.164 ('+221700000000')
 * @return array|null    Ligne personne (avec id bigint, slug, ...) ou null.
 */
function member_find_by_phone(array $cfg, string $phone): ?array {
  // personne_par_telephone retourne un RECORD → row_to_json (cf. db.php).
  return db_call_row_function('personne_par_telephone', [$phone], $cfg);
}

/**
 * Recherche un membre par personne_id (depuis la base).
 *
 * @param array  $cfg
 * @param int    $personneId  id bigint de la table personne
 * @return array|null
 */
function member_find_by_id(array $cfg, int $personneId): ?array {
  // personne_par_id retourne un RECORD → row_to_json.
  return db_call_row_function('personne_par_id', [$personneId], $cfg);
}

/**
 * Projette une ligne personne (table DB) en fiche membre pour le front.
 *
 * Le front (auth.js openEditor) attend : photo, nom_complet, adresse,
 * telephone, biographie. On mappe depuis la ligne personne qui expose
 * url_photo → photo. On garde le téléphone pour pré-remplir le champ
 * (l'édition reste cloisonnée par id+tel côté SQL, pas par ce qu'affiche le form).
 *
 * @param array $personne  Ligne table personne
 * @return array           Fiche membre pour le front
 */
function member_to_front(array $personne): array {
  return [
    'id'          => $personne['slug'] ?? '',
    'photo'       => $personne['url_photo'] ?? '',
    'nom_complet' => $personne['nom_complet'] ?? '',
    'adresse'     => $personne['adresse'] ?? '',
    'telephone'   => $personne['telephone'] ?? '',
    'biographie'  => $personne['biographie'] ?? '',
    'fonction'    => $personne['fonction'] ?? '',
    'fondateur'   => $personne['fondateur'] ?? false,
  ];
}
