<?php
// =============================================================================
//  dons.php — Helpers partagés du flux de don (V2 Chantier 2 — JAK 2026)
// =============================================================================
//  Rôle : fonctions utilitaires utilisées par les 5 endpoints api/pay/*.
//         Aucune logique métier de paiement ici : c'est un sac d'outils
//         (référence, lookup par uuid, construction d'URLs de retour).
//
//  Pourquoi un helper séparé ?
//    • generate_reference() et purl_*() sont des détails de plomberie qui
//      n'ont pas leur place dans payservices.php (client HTTP pur) ni dans
//      db.php (proxy SQL). Les regrouper évite la duplication entre les 5
//      endpoints qui en dépendent.
//    • don_par_uuid_query() : la DBA n'a PAS livré de fonction don_par_uuid
//      (la recherche par uuid se ferait via un SELECT). On centralise ce
//      SELECT contrôlé ici plutôt que de le copier dans retour/status/reconcile.
//
//  SÉCURITÉ :
//    • generate_reference() utilise random_bytes (CSPRNG) → non prédictible.
//    • don_par_uuid_query() caste l'uuid en uuid::uuid via db_raw/db_cast
//      (le cast valide le format côté PostgreSQL ; pas d'injection possible).
//    • Aucune fonction ici n'exécute d'effet de bord (pure lecture/génération).
// =============================================================================

require_once __DIR__.'/db.php';

/**
 * Génère une référence interne de don.
 *
 * Format : 'JAK' + date('ymd') (6 car) + 4 hex (aléatoires) = 13 car.
 *
 * ⚠ CONTRAINTE : la colonne reference_interne (migration 004) et la clé
 *    d'idempotence soutien_recus.reference_don attendent ≤ 11 caractères.
 *    On compose donc : 'JAK' (3) + 'ymd' compressé (5 : A-Z+0-9 pour l'année
 *    et le mois/jour) ... NON — pour rester lisible et garanti ≤ 11, on fait :
 *       'JAK' (3) + jour de l'année base36 (3 car max pour 365) + 5 hex
 *    soit 3 + 3 + 5 = 11 caractères exacts, et la part aléatoire (5 hex
 *    = 16^5 ≈ 1M combinaisons par jour) rend la collision négligeable.
 *
 * La clé d'idempotence réelle reste ON CONFLICT (reference_don) en base :
 * même en cas de collision rarissime, l'INSERT échouerait proprement plutôt
 * que de doubler un soutien. La référence est aussi passée à pay_services
 * comme pReference (clé de transaction opérateur).
 *
 * @return string  Référence ≤ 11 caractères, alphanumérique majuscules.
 */
function generate_reference(): string {
    $dayOfYear = (int)date('z') + 1;          // 1-366
    $dayB36    = strtoupper(base_convert((string)$dayOfYear, 10, 36));
    $dayB36    = str_pad($dayB36, 3, '0', STR_PAD_LEFT);  // 3 car exacts
    $rand      = strtoupper(bin2hex(random_bytes(3)));    // 6 hex → on garde 5
    $rand      = substr($rand, 0, 5);
    return 'JAK' . $dayB36 . $rand;            // 3 + 3 + 5 = 11 caractères
}

/**
 * Retrouve un don depuis son uuid pay_services.
 *
 * Il n'existe pas de fonction PL/pgSQL don_par_uuid : on fait un SELECT
 * contrôlé via db_query (SQL interne, jamais de saisie client interpolée
 * sans cast). Le cast uuid::uuid valide le format côté PostgreSQL.
 *
 * @param string $uuid  UUID au format texte (ex: 'a1b2...').
 * @param array  $cfg
 * @return array|null   Ligne {id, statut, reference_interne} ou null si absent.
 */
function don_par_uuid_query(string $uuid, array $cfg): ?array {
    // Cast explicite uuid : PostgreSQL valide le format et refuse tout ce qui
    // n'est pas un UUID canonique. On utilise db_cast (quote + cast) pour ne
    // jamais interpoler l'uuid brut dans le SQL.
    $uuidSql = db_cast($uuid, 'uuid');      // fragment "'...'::uuid" (db_raw)
    $sql = "SELECT id, statut, reference_interne FROM don WHERE uuid = "
         . $uuidSql['sql'];                  // fragment déjà échappé/casté
    $rows = db_query($sql, $cfg);
    if (!$rows) return null;
    $r = $rows[0];
    return [
        'id'                => isset($r['id']) ? (int)$r['id'] : null,
        'statut'            => (string)($r['statut'] ?? ''),
        'reference_interne' => $r['reference_interne'] ?? null,
    ];
}

/**
 * URL de retour SUCCÈS (redirect navigateur depuis pay_services).
 *
 * On embarque la RÉFÉRENCE INTERNE (?ref=) — connue AVANT l'appel pay_services,
 * contrairement à l'uuid qui n'est renvoyé QUE par add_payement. C'est le seul
 * identifiant qu'on maîtrise à l'aller : il permet à retour.php de résoudre le
 * don même si l'opérateur n'ajoute pas d'?uuid= à la redirection. Sans ça,
 * retour.php n'a aucun moyen d'identifier le don au retour (cf. bug CRIT-001).
 *
 * @param array   $cfg
 * @param ?string $ref  Référence interne du don (generate_reference()).
 */
function purl_success(array $cfg, ?string $ref = null): string {
    $url = rtrim((string)$cfg['purl_base'], '/') . '/api/pay/retour.php';
    if ($ref !== null && $ref !== '') {
        $url .= '?ref=' . rawurlencode($ref);
    }
    return $url;
}

/**
 * URL de retour ÉCHEC. On réutilise retour.php avec statut=echec (pour l'échec
 * explicite) ET la référence (pour identifier le don côté retour.php).
 *
 * @param array   $cfg
 * @param ?string $ref  Référence interne du don.
 */
function purl_fail(array $cfg, ?string $ref = null): string {
    $url = rtrim((string)$cfg['purl_base'], '/') . '/api/pay/retour.php?statut=echec';
    if ($ref !== null && $ref !== '') {
        $url .= '&ref=' . rawurlencode($ref);
    }
    return $url;
}

/**
 * Retrouve un don depuis sa référence interne (fallback de retour.php quand
 * l'opérateur ne renvoie pas d'uuid dans la redirection).
 *
 * SELECT contrôlé via db_query : la référence provient du paramètre ?ref= de
 * l'URL (donc potentiellement hostile) → on la neutralise via db_quote (quote +
 * échappement des apostrophes), jamais interpolée brute.
 *
 * @param string $ref  Référence interne (ex : 'JAK...', ≤ 11 car).
 * @param array  $cfg
 * @return array|null  Ligne {id, uuid, statut, reference_interne} ou null.
 */
function don_par_reference_query(string $ref, array $cfg): ?array {
    $sql = "SELECT id, uuid, statut, reference_interne FROM don "
         . "WHERE reference_interne = " . db_quote($ref)
         . " ORDER BY id DESC LIMIT 1";
    $rows = db_query($sql, $cfg);
    if (!$rows) return null;
    $r = $rows[0];
    return [
        'id'                => isset($r['id']) ? (int)$r['id'] : null,
        'uuid'              => $r['uuid'] ?? null,
        'statut'            => (string)($r['statut'] ?? ''),
        'reference_interne' => $r['reference_interne'] ?? null,
    ];
}
