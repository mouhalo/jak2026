<?php
// =============================================================================
//  status.php — Polling navigateur du statut d'un don (V2 Chantier 2)
// =============================================================================
//  Rôle : endpoint PUBLIC interrogé en polling par le navigateur (après que
//         create.php a renvoyé l'uuid) pour suivre l'avancement du paiement.
//         C'est le 2e chemin de confirmation (le 1er étant retour.php).
//
//  FLUX
//    1. Le navigateur GET/POST {uuid} ici toutes les N secondes.
//    2. On cherche le don en base (don_par_uuid_query).
//    3. S'il est déjà confirmé/échoué → on renvoie son statut (no-op).
//    4. S'il est encore en_attente → on poll pay_services et on confirme/
//       échoue le don de façon idempotente.
//    5. Réponse : {success, statut:'confirme'|'echoue'|'en_attente', don_id}.
//
//  SÉCURITÉ / ROBUSTESSE
//    • Idempotence : don_confirmer/don_echouer ne font rien si déjà traité →
//      safe même si retour.php ou reconcile.php ont confirmé entre-temps.
//    • Best-effort : si pay_services est injoignable, on renvoie 'en_attente'
//      SANS planter le polling (le navigateur réessaiera ; reconcile.php
//      rattrapera). On n'échoue jamais un don sur une indispo réseau.
//    • Aucune donnée sensible renvoyée (pas de montant/téléphone).
//
//  INPUT  : GET ?uuid=...  ou POST JSON {uuid}
//  OUTPUT : 200 {success:true, statut:'confirme'|'echoue'|'en_attente', don_id:int}
//           404 {success:false}   (uuid introuvable)
// =============================================================================
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/payservices.php';
require_once __DIR__.'/../lib/dons.php';

$cfg = app_boot();

// uuid accepté en GET (polling simple) ou POST JSON.
$in   = read_body();
$uuid = isset($_GET['uuid']) ? (string)$_GET['uuid'] : (string)($in['uuid'] ?? '');
$uuid = trim($uuid);

if ($uuid === '') {
    json_out(['success'=>false, 'message'=>'uuid manquant.'], 422);
}

// --- 1. Lookup du don par uuid -------------------------------------------
try {
    $row = don_par_uuid_query($uuid, $cfg);
} catch (Throwable $e) {
    error_log('[jak-pay] status don_par_uuid_query : '.$e->getMessage());
    json_out(['success'=>false, 'message'=>'Service indisponible.'], 503);
}
if ($row === null) {
    json_out(['success'=>false], 404);
}

$donId  = (int)$row['id'];
$statut = (string)$row['statut'];

// --- 2. Déjà traité ? ----------------------------------------------------
if ($statut === 'confirme') {
    json_out(['success'=>true, 'statut'=>'confirme', 'don_id'=>$donId], 200);
}
if ($statut === 'echoue') {
    json_out(['success'=>true, 'statut'=>'echoue', 'don_id'=>$donId], 200);
}

// --- 3. Encore en_attente → poll pay_services (confirmation secondaire) --
try {
    $ps = ps_payment_status($uuid, $cfg);
} catch (Throwable $e) {
    // Indispo réseau pay_services : on ne casse pas le polling.
    error_log('[jak-pay] status ps_payment_status : '.$e->getMessage());
    json_out(['success'=>true, 'statut'=>'en_attente', 'don_id'=>$donId], 200);
}

if (!$ps['ok']) {
    // pay_services injoignable / 404 : on reste en attente (rattrapage ultérieur).
    json_out(['success'=>true, 'statut'=>'en_attente', 'don_id'=>$donId], 200);
}

$st = strtoupper((string)($ps['statut'] ?? ''));
if ($st === 'COMPLETED' || $st === 'SUCCESSFUL') {
    try {
        db_call_function('don_confirmer',
            [$donId, db_cast($uuid, 'uuid')], $cfg);
        json_out(['success'=>true, 'statut'=>'confirme', 'don_id'=>$donId], 200);
    } catch (Throwable $e) {
        error_log('[jak-pay] status don_confirmer : '.$e->getMessage());
        json_out(['success'=>true, 'statut'=>'en_attente', 'don_id'=>$donId], 200);
    }
}
if ($st === 'FAILED') {
    try {
        db_call_function('don_echouer',
            [$donId, db_cast($uuid, 'uuid'), 'payment_status FAILED'], $cfg);
        json_out(['success'=>true, 'statut'=>'echoue', 'don_id'=>$donId], 200);
    } catch (Throwable $e) {
        error_log('[jak-pay] status don_echouer : '.$e->getMessage());
        json_out(['success'=>true, 'statut'=>'en_attente', 'don_id'=>$donId], 200);
    }
}

// PROCESSING ou autre statut opérateur → toujours en attente.
json_out(['success'=>true, 'statut'=>'en_attente', 'don_id'=>$donId], 200);
