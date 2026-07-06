<?php
// =============================================================================
//  reconcile.php — Rattrapage des dons en_attente (V2 Chantier 2)
// =============================================================================
//  Rôle : 3e et dernier chemin de confirmation. Il balaie les dons restés
//         en_attente (< 24h, uuid attaché) et poll pay_services pour chacun,
//         afin de rattraper les paiements dont le redirect/polling navigateur
//         n'a pas abouti (fermeture d'onglet, réseau, etc.).
//
//  DEUX MODES D'EXÉCUTION
//    • CRON (illimité)   : php_sapi_name() === 'cli' OU présence de ?force.
//      → balayage complet. À appeler depuis une tâche planifiée (toutes les
//        5-15 min). Le ?force sert au déclenchement admin manuel.
//    • HTTP (throttlé)   : sinon (chargement de page côté navigateur).
//      → throttled par un fichier state/reconcile.lock (filemtime) pour ne
//        balayer qu'au plus toutes les N secondes (reconcile_throttle_sec),
//        éviter de marteler pay_services à chaque page vue.
//
//  SÉCURITÉ / ROBUSTESSE
//    • Limite à 50 dons par exécution (sécurité anti-saturation).
//    • Tout échec individuel (poll, confirm) est catché et journalisé : un
//      don problématique ne stoppe pas le balayage des autres.
//    • Idempotence : don_confirmer/don_echouer sont des no-op si déjà traité.
//    • Le fichier lock est créé à la volée (mkdir du dossier state au besoin).
//
//  OUTPUT : 200 {success:true, throttled?:true, traites:N, confirmes:M, echoues:K}
// =============================================================================
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/payservices.php';
require_once __DIR__.'/../lib/dons.php';
require_once __DIR__.'/../lib/auth.php';

$cfg = app_boot();   // démarre la session (nécessaire au contrôle de rôle ci-dessous)

// --- Détection du mode ----------------------------------------------------
// CLI (cron) → illimité. HTTP → throttlé par défaut. Le bypass ?force est réservé
// à un ADMIN AUTHENTIFIÉ (MAJ-002 : ?force était un bypass de throttle ouvert à
// n'importe quel anonyme → martèlement de pay_services + sql_jsonpro = abus/DoS).
$isCron = (php_sapi_name() === 'cli');
if (!$isCron && isset($_GET['force'])) {
    $a = auth_current($cfg);
    if ($a && ($a['role'] ?? '') === 'admin') {
        $isCron = true;   // admin authentifié : bypass du throttle autorisé
    } else {
        // Non-admin : on IGNORE ?force et on reste en mode throttlé (pas d'erreur,
        // pas de fuite d'info — la page publique appelle reconcile sans ?force).
        error_log('[jak-pay] reconcile ?force refusé (non-admin) — throttle appliqué');
    }
}

// En mode HTTP, on throttle via un fichier timestamp. En CLI on passe.
if (!$isCron) {
    $lockPath = __DIR__.'/../state/reconcile.lock';
    $lockDir  = dirname($lockPath);
    if (!is_dir($lockDir)) { @mkdir($lockDir, 0700, true); }

    $throttle = (int)($cfg['reconcile_throttle_sec'] ?? 300);
    $now      = time();
    if (is_file($lockPath) && ($now - (int)filemtime($lockPath)) < $throttle) {
        // Trop tôt : on sort proprement sans rien faire.
        json_out(['success'=>true, 'throttled'=>true], 200);
    }
    // On touche le lock AVANT de balayer (verrou "en cours" + horodatage).
    @touch($lockPath);
}

// --- Récupération des dons en_attente récents ----------------------------
// SQL interne contrôlé (pas de saisie client interpolée). On borne à 24h pour
// ne pas rattraper indéfiniment des dons sans uuid/abandonnés.
$sql = "SELECT id, uuid, reference_interne FROM don "
     . "WHERE statut = 'en_attente' "
     . "  AND uuid IS NOT NULL "
     . "  AND cree_le > now() - interval '24 hours' "
     . "ORDER BY id";
try {
    $rows = db_query($sql, $cfg);
} catch (Throwable $e) {
    error_log('[jak-pay] reconcile select en_attente : '.$e->getMessage());
    json_out(['success'=>false, 'message'=>'Service indisponible.'], 503);
}

// Sécurité : plafond par exécution.
$rows = array_slice($rows, 0, 50);

$traites  = 0;
$confirmes = 0;
$echoues  = 0;

foreach ($rows as $r) {
    $donId = isset($r['id']) ? (int)$r['id'] : null;
    $uuid  = isset($r['uuid']) ? (string)$r['uuid'] : null;
    if ($donId === null || $uuid === null || $uuid === '') {
        continue;
    }
    $traites++;

    try {
        $ps = ps_payment_status($uuid, $cfg);
    } catch (Throwable $e) {
        error_log('[jak-pay] reconcile poll don '.$donId.' : '.$e->getMessage());
        continue;   // don suivant : on ne stoppe pas le balayage.
    }
    if (!$ps['ok']) {
        continue;   // pay_services injoignable pour cet uuid → on réessaiera plus tard.
    }

    $st = strtoupper((string)($ps['statut'] ?? ''));

    if ($st === 'COMPLETED' || $st === 'SUCCESSFUL') {
        try {
            $res = db_call_function('don_confirmer',
                [$donId, db_cast($uuid, 'uuid')], $cfg);
            if (is_array($res) && ($res['action'] ?? '') === 'confirme') {
                $confirmes++;
            }
        } catch (Throwable $e) {
            error_log('[jak-pay] reconcile don_confirmer '.$donId.' : '.$e->getMessage());
        }
        // Journal d'audit (best-effort). db_cast ⇒ quote + cast jsonb propre.
        try {
            $payload = json_encode(['action'=>'confirme_via_reconcile', 'uuid'=>$uuid],
                                   JSON_UNESCAPED_UNICODE);
            db_call_function('journal_ajouter',
                ['reconciliation', 'don', $donId, db_cast($payload, 'jsonb')], $cfg);
        } catch (Throwable $e) { /* audit non critique */ }
    } elseif ($st === 'FAILED' || $st === 'CANCELED') {
        // FAILED et CANCELED = statuts terminaux d'échec (cf. walletApi.js).
        try {
            $res = db_call_function('don_echouer',
                [$donId, db_cast($uuid, 'uuid'), 'reconcile '.$st], $cfg);
            if (is_array($res) && ($res['action'] ?? '') === 'echoue') {
                $echoues++;
            }
        } catch (Throwable $e) {
            error_log('[jak-pay] reconcile don_echouer '.$donId.' : '.$e->getMessage());
        }
        try {
            $payload = json_encode(['action'=>'echoue_via_reconcile', 'uuid'=>$uuid],
                                   JSON_UNESCAPED_UNICODE);
            db_call_function('journal_ajouter',
                ['reconciliation', 'don', $donId, db_cast($payload, 'jsonb')], $cfg);
        } catch (Throwable $e) { /* audit non critique */ }
    }
    // PROCESSING / autre → rien à faire pour ce tour (on le reprendra au prochain).
}

json_out([
    'success'   => true,
    'traites'   => $traites,
    'confirmes' => $confirmes,
    'echoues'   => $echoues,
], 200);
