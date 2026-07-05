<?php
// =============================================================================
//  liste.php — Carrousel public des soutiens (V2 Chantier 2)
// =============================================================================
//  Rôle : endpoint PUBLIC renvoyant la liste paginée des dons confirmés,
//         prête à afficher dans le carrousel des soutiens (page de collecte).
//
//  SÉCURITÉ — CONFIDENTIALITÉ
//    • soutien_lister() (PL/pgSQL) ne retourne QUE : numero_masque, nom_affiche,
//      canal, confirme_le. JAMAIS de montant (stocké mais non listé — décision
//      utilisateur) ni de téléphone clair (absent de soutien_recus).
//    • On ne fait que transmettre ces colonnes : aucune fuite possible ici,
//      la projection a lieu côté SQL. Vérifié dans la migration 007 (4f).
//
//  INPUT  : GET ?limit=20&offset=0   (limit défaut 20, max 50 ; offset ≥ 0)
//  OUTPUT : 200 {success:true, soutiens:[{numero_masque,nom_affiche,canal,confirme_le}], total:N}
// =============================================================================
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/db.php';

$cfg = app_boot();

// --- Pagination (bornée côté PHP, en plus du bornage SQL) -----------------
$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
if ($limit < 1)   { $limit = 20; }
if ($limit > 50)  { $limit = 50; }   // plafond strict pour le carrousel
if ($offset < 0)  { $offset = 0; }

// --- Lecture des soutiens -------------------------------------------------
// ⚠ soutien_lister est RETURNS TABLE(...) → set-returning. On ne peut PAS
// l'appeler comme un scalaire (SELECT soutien_lister(...) AS data) : il faut
// l'invoquer dans la clause FROM. db_call_function ne convient donc pas ici ;
// on construit le SELECT contrôlé avec db_query + arguments via db_quote
// (entiers, sûrs). La fonction reste whitelistée (DB_ALLOWED_FUNCTIONS).
try {
    $sql = 'SELECT numero_masque, nom_affiche, canal, confirme_le '
         . 'FROM soutien_lister(' . (int)$limit . ', ' . (int)$offset . ')';
    $rows = db_query($sql, $cfg);
} catch (Throwable $e) {
    error_log('[jak-pay] liste soutien_lister : '.$e->getMessage());
    json_out(['success'=>false, 'message'=>'Service indisponible.'], 503);
}
if (!is_array($rows)) { $rows = []; }

// --- Projection stricte (au cas où) ---------------------------------------
// On ne conserve que les 4 colonnes attendues, pour garantir qu'aucune autre
// colonne ne fuite par accident d'évolution SQL future.
$soutiens = [];
foreach ($rows as $r) {
    $soutiens[] = [
        'numero_masque' => $r['numero_masque'] ?? null,
        'nom_affiche'   => $r['nom_affiche']   ?? null,
        'canal'         => $r['canal']         ?? null,
        'confirme_le'   => $r['confirme_le']   ?? null,
    ];
}

json_out([
    'success'  => true,
    'soutiens' => $soutiens,
    'total'    => count($soutiens),
], 200);
