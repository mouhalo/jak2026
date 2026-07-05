<?php
// =============================================================================
//  payservices.php — Client PHP de l'API pay_services (V2 Chantier 2)
// =============================================================================
//  Rôle : proxy médiateur UNIQUE entre le runtime applicatif et pay_services.
//         pay_services n'a PAS de token d'auth → appel direct navigateur = faille
//         (lecture de statuts, création de transactions arbitraires). Tout appel
//         passe par ici, contrôlé par les endpoints api/pay/*.
//
//  Contrat pay_services (sondé empiriquement 2026-07-05, voir mémoire projet) :
//    POST /add_payement  → {uuid, status:PROCESSING, om|maxit, payment_url, qrCode}
//                         erreur : {detail} (422 si champs manquants, 400 si service down)
//    GET  /payment_status/{uuid} → {status:success, data:{statut:PROCESSING|COMPLETED|...}}
//
//  SSL : réutilise EXACTEMENT l'approche de whatsapp.php / db.php — résolveur
//  CA bundle via ca_bundle_candidates, vérif SSL JAMAIS désactivée.
// =============================================================================

/**
 * Résout le bundle CA pour la vérif SSL cURL (cf. whatsapp.php / config.php).
 * On ne DÉSACTIVE jamais la vérif — on pointe juste vers un CA valide.
 */
function _ps_ca_bundle(array $cfg): string {
  $ca = $cfg['ca_bundle'] ?? '';
  if (!$ca) {
    foreach (($cfg['ca_bundle_candidates'] ?? []) as $cand) {
      if (@is_file($cand)) return $cand;
    }
  }
  return $ca;
}

/**
 * Requête HTTP générique vers pay_services (POST JSON ou GET).
 *
 * @param string $path    Chemin relatif (ex. '/add_payement' ou '/payment_status/{uuid}')
 * @param array  $cfg
 * @param string $method  'POST' (défaut) ou 'GET'
 * @param ?array $body    Body JSON pour POST (ignoré en GET)
 * @return array {status:int, json:array}  (json=[] si réponse non-JSON)
 */
function _ps_request(string $path, array $cfg, string $method='POST', ?array $body=null): array {
  $url = $cfg['pay_services_url'] . $path;
  $ch = curl_init($url);
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,   // TOUJOURS vérifié.
    CURLOPT_SSL_VERIFYHOST => 2,
  ];
  if ($method === 'POST') {
    $payload = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
    $opts[CURLOPT_POST] = true;
    $opts[CURLOPT_POSTFIELDS] = $payload;
    $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Content-Length: '.strlen($payload)];
  } else {
    $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
  }
  $ca = _ps_ca_bundle($cfg);
  if ($ca) { $opts[CURLOPT_CAINFO] = $ca; }
  curl_setopt_array($ch, $opts);

  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($raw === false) {
    throw new RuntimeException('pay_services injoignable : '.$err, 502);
  }
  $json = json_decode((string)$raw, true);
  return ['status'=>$status, 'json'=>is_array($json) ? $json : []];
}

/**
 * Crée une demande de paiement auprès de pay_services.
 *
 * @param array $params  {pAppName, pServiceName, pMethode, pReference, pClientTel, pMontant,
 *                        purl_success, purl_fail}
 * @param array $cfg
 * @return array {ok:bool, uuid?, payment_url?, om?, maxit?, qrCode?, message?}
 */
function ps_add_payement(array $params, array $cfg): array {
  $res = _ps_request('/add_payement', $cfg, 'POST', $params);
  $j = $res['json'] ?? [];
  // Succès : HTTP 200 + uuid présent. Erreur : {detail} (422/400).
  if ($res['status'] === 200 && !empty($j['uuid'])) {
    return [
      'ok'          => true,
      'uuid'        => $j['uuid'],
      'payment_url' => $j['payment_url'] ?? null,   // Wave : URL ; OM : null
      'om'          => $j['om'] ?? null,            // OM : URL de paiement
      'maxit'       => $j['maxit'] ?? null,         // OM : alias
      'qrCode'      => $j['qrCode'] ?? null,        // OM : QR base64 PNG
    ];
  }
  // Erreur pay_services (service down, jaksn non enregistré, validation...).
  $detail = $j['detail'] ?? 'erreur pay_services inconnue';
  if (is_array($detail)) {
    // Erreur de validation Pydantic (422) : liste de {msg}.
    $msgs = array_column($detail, 'msg');
    $detail = implode('; ', $msgs);
  }
  return ['ok'=>false, 'message'=>(string)$detail, 'http'=>$res['status']];
}

/**
 * Récupère le statut d'une transaction pay_services.
 *
 * @param string $uuid  UUID de la transaction (format UUID PostgreSQL)
 * @param array  $cfg
 * @return array {ok:bool, statut?, montant?, telephone?, message?}
 *   statut normalisé en MAJUSCULES : PROCESSING | COMPLETED | SUCCESSFUL | FAILED
 */
function ps_payment_status(string $uuid, array $cfg): array {
  $res = _ps_request('/payment_status/'.$uuid, $cfg, 'GET');
  $j = $res['json'] ?? [];
  if ($res['status'] === 200 && ($j['status'] ?? '') === 'success') {
    $data = $j['data'] ?? [];
    return [
      'ok'       => true,
      'statut'   => strtoupper((string)($data['statut'] ?? 'PROCESSING')),
      'montant'  => $data['montant'] ?? null,
      'telephone'=> $data['telephone'] ?? null,
    ];
  }
  // 404 = demande non trouvée (uuid inexistant) → pas une erreur fatale, juste "inconnu".
  $detail = $j['detail'] ?? 'statut indisponible';
  return ['ok'=>false, 'message'=>(string)$detail, 'http'=>$res['status']];
}
