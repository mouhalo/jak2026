<?php
// =============================================================================
//  create.php — Création d'un don + demande de paiement (V2 Chantier 2)
// =============================================================================
//  Rôle : endpoint PUBLIC/anonyme d'amorçage d'un don Wave/Orange Money.
//
//  FLUX
//    1. Le navigateur POST {montant, canal, telephone, nom?} ici.
//    2. On valide les entrées (montant, canal, téléphone E.164, nom optionnel).
//    3. On crée un don en_attente en base (don_creer) → id du don.
//    4. On demande une transaction à pay_services (ps_add_payement).
//    5. On attache l'uuid reçu au don (don_attacher_uuid).
//    6. On renvoie au navigateur l'URL/QR de paiement (il redirige/affiche).
//
//  SÉCURITÉ
//    • Public : aucune auth, mais bornes strictes (montant min/max, 2 canaux).
//    • pay_services est appelé côté SERVEUR (jamais navigateur) : son absence
//      de token n'est donc pas exploitable.
//    • Le téléphone est normalisé E.164 puis masqué en SQL (don_creer) ; le
//      clair n'est stocké que pour audit (telephone_clair), jamais renvoyé.
//    • Si pay_services échoue, on marque le don échoué (best-effort) pour ne
//      pas laisser de don en_attente orphelin polluer le rattrapage.
//
//  INPUT  : POST JSON {montant:int, canal:'OM'|'WAVE', telephone:str, nom:str?}
//  OUTPUT : 200 {success:true, uuid, canal, payment_url?, om?, qrCode?}
//           422 {success:false, message}        (validation)
//           500 {success:false, message}        (DB don_creer)
//           502 {success:false, message}        (pay_services indisponible)
// =============================================================================
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/payservices.php';
require_once __DIR__.'/../lib/otp.php';     // otp_normalize_phone()
require_once __DIR__.'/../lib/dons.php';

$cfg = app_boot();
$in   = read_body();

// --- 1. Validation des entrées -------------------------------------------
$montant = isset($in['montant']) ? (int)$in['montant'] : null;
$min = (int)($cfg['don_montant_min'] ?? 100);
$max = (int)($cfg['don_montant_max'] ?? 2000000);
if ($montant === null || $montant < $min || $montant > $max) {
    json_out(['success'=>false, 'message'=>"Montant invalide (entre {$min} et {$max} FCFA)."],
             422);
}

// Canal : 2 valeurs autorisées, normalisées en majuscules.
$canal = strtoupper(trim((string)($in['canal'] ?? '')));
if ($canal !== 'OM' && $canal !== 'WAVE') {
    json_out(['success'=>false, 'message'=>'Canal invalide (OM ou WAVE).'], 422);
}

// Téléphone : 9 chiffres saisis → E.164. Rejette tout format non reconnu.
$telRaw   = (string)($in['telephone'] ?? '');
$telE164  = otp_normalize_phone($telRaw, $cfg);
if ($telE164 === null) {
    json_out(['success'=>false, 'message'=>'Numéro de téléphone invalide.'], 422);
}
// 9 chiffres nus pour pay_services (pClientTel) : corps national sans indicatif.
$tel9 = preg_replace('/\D/', '', $telE164);
// on retire l'indicatif pays (les premiers chiffres au-delà de 9) s'il y en a.
if (strlen($tel9) > 9) {
    $tel9 = substr($tel9, -9);
}

// Nom affiché : optionnel, trim, ≤120 car. NULL → la base affichera 'Anonyme'.
$nom = trim((string)($in['nom'] ?? ''));
if ($nom === '') {
    $nom = null;
} elseif (mb_strlen($nom) > 120) {
    $nom = mb_substr($nom, 0, 120);
}

// --- 2. Création du don en_attente ---------------------------------------
$ref = generate_reference();
try {
    $donId = db_call_function('don_creer',
        [$montant, $canal, $telE164, $nom, $ref], $cfg);
} catch (Throwable $e) {
    error_log('[jak-pay] don_creer échec : '.$e->getMessage());
    json_out(['success'=>false,
              'message'=>'Impossible d\'enregistrer le don. Réessayez.'], 500);
}
if ($donId === null) {
    json_out(['success'=>false,
              'message'=>'Impossible d\'enregistrer le don. Réessayez.'], 500);
}

// --- 3. Demande de paiement pay_services ---------------------------------
// pServiceName : INTOUCH pour WAVE, OFMS pour OM (cf. mémoire projet / spec).
$params = [
    'pAppName'     => $cfg['pay_app_name'] ?? 'JAKSN',
    'pServiceName' => ($canal === 'WAVE') ? 'INTOUCH' : 'OFMS',
    'pMethode'     => $canal,
    'pReference'   => $ref,
    'pClientTel'   => $tel9,
    'pMontant'     => $montant,
    'purl_success' => purl_success($cfg, $ref),
    'purl_fail'    => purl_fail($cfg, $ref),
];
$ps = ps_add_payement($params, $cfg);

// --- 4. Échec pay_services → on marque le don échoué (best-effort) -------
if (!$ps['ok']) {
    try {
        db_call_function('don_echouer',
            [$donId, null, 'pay_services: '.($ps['message'] ?? '')], $cfg);
    } catch (Throwable $e) {
        error_log('[jak-pay] don_echouer (best-effort) échec : '.$e->getMessage());
    }
    // Message adapté au canal : Wave (INTOUCH) est souvent en panne côté opérateur,
    // on oriente l'utilisateur vers OM qui est plus stable. OM down = panne générale.
    $msg = 'Service de paiement indisponible. Réessayez plus tard.';
    if ($canal === 'WAVE') {
        $msg = 'Wave (INTOUCH) est momentanément indisponible. Essayez avec Orange Money.';
    } elseif ($canal === 'OM') {
        $msg = 'Orange Money est momentanément indisponible. Réessayez plus tard.';
    }
    json_out(['success'=>false, 'message'=>$msg, 'canal'=>$canal], 502);
}

// --- 5. Succès : on attache l'uuid au don (FIABLE, avec retries) ---------
// L'uuid est la clé de TOUTE confirmation ultérieure : status.php, retour.php
// ET reconcile.php sont keyés par uuid. S'il n'est pas stocké, le don devient
// INCONFIRMABLE quel que soit le chemin (paiement potentiellement débité, aucune
// reprise possible car pay_services n'expose pas de lookup par référence). On ne
// laisse donc JAMAIS passer un don orphelin : on valide, on réessaie, et à défaut
// on refuse la demande (MAJ-001).
$uuid = (string)$ps['uuid'];

// Validation stricte du format UUID canonique : un uuid mal formé ferait échouer
// db_cast(...,'uuid') à CHAQUE appel de confirmation → don définitivement bloqué.
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
    error_log('[jak-pay] create: uuid pay_services mal formé, don '.$donId.' : '.$uuid);
    try {
        db_call_function('don_echouer', [$donId, null, 'uuid pay_services invalide'], $cfg);
    } catch (Throwable $e) {
        error_log('[jak-pay] don_echouer (uuid invalide) échec : '.$e->getMessage());
    }
    json_out(['success'=>false,
              'message'=>'Erreur technique du service de paiement. Réessayez.'], 502);
}

// Attache idempotente avec retries (absorbe une indispo DB transitoire).
// ⚠ cast uuid explicite : PostgreSQL ne coerce pas text→uuid automatiquement.
$attached = false;
for ($try = 1; $try <= 3; $try++) {
    try {
        db_call_function('don_attacher_uuid', [$donId, db_cast($uuid, 'uuid')], $cfg);
        $attached = true;
        break;
    } catch (Throwable $e) {
        error_log('[jak-pay] don_attacher_uuid tentative '.$try.'/3 (don '.$donId.') : '
            .$e->getMessage());
    }
}

// Échec persistant → on REFUSE plutôt que de créer un don orphelin inconfirmable.
// Le don reste en_attente sans uuid (il vieillira hors de la fenêtre reconcile) ;
// l'utilisateur réessaie et obtient une transaction saine.
if (!$attached) {
    error_log('[jak-pay] CRITIQUE: uuid '.$uuid.' NON attaché au don '.$donId
        .' (orphelin évité, demande refusée)');
    json_out(['success'=>false,
              'message'=>'Impossible de finaliser la demande de paiement. Réessayez.'], 502);
}

// --- 6. Réponse au navigateur --------------------------------------------
json_out([
    'success'     => true,
    'uuid'        => $uuid,
    'canal'       => $canal,
    'payment_url' => $ps['payment_url'] ?? null,
    'om'          => $ps['om'] ?? null,
    'qrCode'      => $ps['qrCode'] ?? null,
], 200);
