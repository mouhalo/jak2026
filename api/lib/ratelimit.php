<?php
// =============================================================================
//  ratelimit.php — Rate-limiter générique basé fichier (V2 — durcissement)
// =============================================================================
//  Réutilise le pattern de throttle.php (OTP) — flock + JSON, écriture atomique —
//  mais générique : plusieurs « buckets » évalués ensemble (ex. par IP ET par
//  téléphone), fenêtre fixe + cooldown optionnel. Sert à borner create.php (dons)
//  contre l'abus (création en masse de dons + de transactions pay_services). Sans BD.
//
//  FAIL-OPEN borné : si le store fichier est indisponible (disque plein, droits),
//  on AUTORISE — on ne bloque pas un paiement légitime pour une panne disque ;
//  l'abus reste borné par les autres couches (validation stricte, pay_services).
// =============================================================================

/**
 * Vérifie ET incrémente atomiquement plusieurs compteurs (TOUS doivent passer).
 * Sémantique fenêtre fixe : un compteur repart à 0 quand sa fenêtre est écoulée.
 *
 * @param string $storePath  Fichier JSON d'état (créé au besoin).
 * @param array  $buckets    Règles : [ ['key'=>'ip:1.2.3.4','max'=>30,'window'=>3600,'cooldown'=>0], ... ]
 *   - key      : identifiant du compteur (préfixer par type pour éviter les collisions)
 *   - max      : nb max d'événements autorisés dans la fenêtre
 *   - window   : durée de la fenêtre, en secondes
 *   - cooldown : délai minimal (s) entre 2 événements pour cette clé (0 = aucun)
 * @param int    $now        Timestamp courant.
 * @return array ['allowed'=>bool, 'reason'=>'ok'|'cooldown'|'cap'|'nostore', 'key'=>?string]
 */
function rate_limit_check(string $storePath, array $buckets, int $now): array {
  $dir = dirname($storePath);
  if (!is_dir($dir)) @mkdir($dir, 0700, true);

  $fp = @fopen($storePath, 'c+');
  if ($fp === false) {
    error_log('[jak-rl] store indisponible (fail-open) : '.$storePath);
    return ['allowed'=>true, 'reason'=>'nostore', 'key'=>null];
  }
  flock($fp, LOCK_EX);
  $raw  = stream_get_contents($fp);
  $data = json_decode((string)$raw, true);
  if (!is_array($data)) $data = [];

  // Purge des entrées dont la fenêtre est entièrement écoulée → borne la taille
  // du fichier. (cooldown ≪ window : perdre l'historique d'une fenêtre passée
  // n'a aucun effet observable.)
  foreach ($data as $k => $rec) {
    if ((int)($rec['reset'] ?? 0) < $now) unset($data[$k]);
  }

  // 1re passe : évaluation seule (aucune écriture tant que tout n'est pas validé).
  $allowed = true; $reason = 'ok'; $failKey = null;
  foreach ($buckets as $b) {
    $k   = (string)$b['key'];
    $rec = $data[$k] ?? null;            // absent → fenêtre neuve, compteur 0 → OK
    if ($rec === null) continue;
    $cd  = (int)($b['cooldown'] ?? 0);
    if ($cd > 0 && ($now - (int)($rec['last'] ?? 0)) < $cd) { $allowed=false; $reason='cooldown'; $failKey=$k; break; }
    if ((int)($rec['count'] ?? 0) >= (int)$b['max'])        { $allowed=false; $reason='cap';      $failKey=$k; break; }
  }

  // 2e passe : incrément atomique de TOUS les buckets (seulement si tout passe).
  if ($allowed) {
    foreach ($buckets as $b) {
      $k   = (string)$b['key'];
      $win = (int)$b['window'];
      $rec = $data[$k] ?? ['count'=>0, 'reset'=>$now + $win, 'last'=>0];
      if (!isset($rec['reset']) || (int)$rec['reset'] < $now) { $rec = ['count'=>0, 'reset'=>$now + $win, 'last'=>0]; }
      $rec['count'] = (int)($rec['count'] ?? 0) + 1;
      $rec['last']  = $now;
      $data[$k] = $rec;
    }
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($data)); fflush($fp);
  }

  flock($fp, LOCK_UN); fclose($fp);
  return ['allowed'=>$allowed, 'reason'=>$reason, 'key'=>$failKey];
}
