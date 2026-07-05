<?php
// =============================================================================
//  router.php — Routeur pour le serveur PHP built-in (php -S)
// =============================================================================
//  Usage :
//    C:\php\php.exe -S localhost:3000 router.php
//
//  Rôle : sert les fichiers statiques (HTML/CSS/JS/images) ET exécute le PHP
//         (api/), comme le ferait LiteSpeed/Apache en production. Le serveur
//         statique Python ne peut PAS exécuter PHP → ce routeur est requis pour
//         tester la V2 (proxy db.php, OTP, save-fiche) en local.
//
//  Note : php -S n'a pas fastcgi_finish_request → request-otp.php garde ce
//         garde-fou derrière function_exists() (anti-énumération V1).
// =============================================================================

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = urldecode($uri);

// 1. Chemin absolu depuis la racine du projet (docroot = ici).
$file = __DIR__ . $uri;

// 2. Répertoire demandé → chercher index.html
if (is_dir($file)) {
  $candidates = ['index.html', 'dignitaires.html', 'jak.html', 'galerie.html'];
  foreach ($candidates as $idx) {
    if (is_file($file . '/' . $idx)) { $file = $file . '/' . $idx; break; }
  }
}

// 3. Fichier existant (statique ou PHP) → laisser le serveur built-in le servir.
//    php -S sert nativement les .php en les exécutant et les assets en direct.
if (is_file($file)) {
  return false;  // false = "le serveur built-in gère la requête lui-même"
}

// 4. Route d'API sans extension (ex. /api/session) → essayer .php
if (str_starts_with($uri, '/api/')) {
  $phpCandidate = $file . '.php';
  if (is_file($phpCandidate)) {
    $_SERVER['SCRIPT_NAME'] = $uri . '.php';
    require $phpCandidate;
    return true;
  }
}

// 5. Sinon : 404
http_response_code(404);
echo '404 Not Found — ' . htmlspecialchars($uri);
