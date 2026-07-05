<?php
// =============================================================================
//  retour.php — Page de retour après paiement (V2 Chantier 2)
// =============================================================================
//  Rôle : PAGE HTML de retour navigateur (redirect pay_services après paiement).
//         C'est le chemin de confirmation PRIMAIRE (cf. spec : pay_services
//         redirige le navigateur ici avec ?uuid=...).
//
//  ⚠ C'est une PAGE, pas une API JSON : on rend du HTML directement (header
//    Content-Type text/html). L'i18n JS du site ne s'applique pas → textes
//    en français simple, page autonome sans JS.
//
//  FLUX
//    1. pay_services redirige vers retour.php?uuid=... (succès) ou
//       retour.php?statut=echec (échec, sans uuid exploitable).
//    2. Si on a un uuid : on cherche le don. S'il est encore en_attente, on
//       interroge pay_services (poll primaire) et on confirme/échoue le don
//       de façon idempotente (don_confirmer/don_echouer ne font rien si déjà
//       traité → safe même si status.php a déjà confirmé en parallèle).
//    3. On rend un écran de remerciement (succès) ou d'échec (sinon).
//
//  SÉCURITÉ
//    • Aucune donnée sensible affichée (pas de montant, pas de téléphone).
//    • Les erreurs DB/pay_services ne plantent pas la page : on affiche un
//      écran générique (le rattrapage reconcile.php corrigera l'état).
//    • Le cast uuid protège contre toute injection via le paramètre.
// =============================================================================
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/payservices.php';
require_once __DIR__.'/../lib/dons.php';

// app_boot() pose Content-Type: application/json (prévu pour les API) ; on
// le surcharge en text/html CAR retour.php est une PAGE de retour navigateur.
$cfg = app_boot();
header('Content-Type: text/html; charset=utf-8');

$uuid         = isset($_GET['uuid']) ? (string)$_GET['uuid'] : null;
$statut_param = isset($_GET['statut']) ? (string)$_GET['statut'] : null;
$base         = rtrim((string)$cfg['purl_base'], '/');

// --- Détermination de l'état à afficher -----------------------------------
// Par défaut : échec (arrivée via purl_fail sans uuid, ou uuid introuvable).
$etat = 'echoue';   // 'confirme' | 'echoue' | 'inconnu'

if ($uuid !== null && $uuid !== '') {
    try {
        $row = don_par_uuid_query($uuid, $cfg);
    } catch (Throwable $e) {
        // DB injoignable : on affiche un écran neutre. Le rattrapage corrigera.
        $row = null;
        error_log('[jak-pay] retour don_par_uuid_query : '.$e->getMessage());
    }

    if ($row !== null) {
        $donId  = $row['id'];
        $statut = $row['statut'];

        // Déjà traité (status.php ou un précédent retour) → on reflète l'état.
        if ($statut === 'confirme') {
            $etat = 'confirme';
        } elseif ($statut === 'echoue') {
            $etat = 'echoue';
        } else {
            // Toujours en_attente : on poll pay_services (confirmation primaire).
            try {
                $ps = ps_payment_status($uuid, $cfg);
                if ($ps['ok']) {
                    $st = $ps['statut'] ?? '';
                    if ($st === 'COMPLETED' || $st === 'SUCCESSFUL') {
                        db_call_function('don_confirmer',
                            [$donId, db_cast($uuid, 'uuid')], $cfg);
                        $etat = 'confirme';
                    } elseif ($st === 'FAILED') {
                        db_call_function('don_echouer',
                            [$donId, db_cast($uuid, 'uuid'), 'payment_status FAILED'],
                            $cfg);
                        $etat = 'echoue';
                    } else {
                        // PROCESSING / autre → encore en attente opérateur.
                        $etat = 'inconnu';
                    }
                } else {
                    // pay_services injoignable → on n'échoue pas le don pour
                    // autant (le rattrapage reconcile.php s'en chargera).
                    $etat = 'inconnu';
                }
            } catch (Throwable $e) {
                error_log('[jak-pay] retour poll/confirme : '.$e->getMessage());
                $etat = 'inconnu';
            }
        }
    } elseif ($statut_param !== 'echec') {
        // uuid fourni mais don introuvable : ni succès ni échec certain.
        $etat = 'inconnu';
    }
}
// Si pas d'uuid et statut=echec (purl_fail) → $etat reste 'echoue'.

// --- Rendu HTML -----------------------------------------------------------
$titre  = '';
$msg    = '';
$icone  = '';
if ($etat === 'confirme') {
    $titre = 'Don confirmé — Merci !';
    $msg   = 'Votre don a bien été reçu. Qu\'Allah vous récompense pour votre générosité.';
    $icone = '✓';
} elseif ($etat === 'echoue') {
    $titre = 'Paiement non abouti';
    $msg   = 'Le paiement n\'a pas pu être confirmé. Aucun montant n\'a été débité. Vous pouvez réessayer.';
    $icone = '✕';
} else { // inconnu / en cours
    $titre = 'Paiement en cours de vérification';
    $msg   = 'Votre paiement est en cours de traitement. Il sera confirmé dans quelques instants. Vous pouvez fermer cette page.';
    $icone = '…';
}

$titreEsc = htmlspecialchars($titre, ENT_QUOTES, 'UTF-8');
$msgEsc   = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
$lienEsc  = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
$couleur  = ($etat === 'confirme') ? '#1b8a3a' : (($etat === 'echoue') ? '#b3261e' : '#7a5a00');

echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titreEsc}</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center;
    justify-content: center;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: #f6f5f1; color: #1f1f1f; padding: 20px;
  }
  .card {
    max-width: 460px; width: 100%; text-align: center;
    background: #fff; border-radius: 16px; padding: 40px 28px;
    box-shadow: 0 6px 30px rgba(0,0,0,.08);
  }
  .badge {
    width: 72px; height: 72px; border-radius: 50%; margin: 0 auto 18px;
    display: flex; align-items: center; justify-content: center;
    font-size: 38px; font-weight: 700; color: #fff;
    background: {$couleur};
  }
  h1 { font-size: 22px; margin: 0 0 12px; }
  p  { font-size: 16px; line-height: 1.5; color: #444; margin: 0 0 24px; }
  a.retour {
    display: inline-block; padding: 12px 26px; border-radius: 999px;
    background: #1f6feb; color: #fff; text-decoration: none; font-weight: 600;
  }
  a.retour:hover { background: #1558b0; }
  .footer { margin-top: 26px; font-size: 12px; color: #888; }
</style>
</head>
<body>
  <main class="card" role="main">
    <div class="badge" aria-hidden="true">{$icone}</div>
    <h1>{$titreEsc}</h1>
    <p>{$msgEsc}</p>
    <a class="retour" href="{$lienEsc}">Retour au site</a>
    <div class="footer">JAK 2026 — Jeunesse Al Khayri</div>
  </main>
</body>
</html>
HTML;
