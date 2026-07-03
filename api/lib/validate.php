<?php
function validate_member_fields(array $in, array $data, string $selfId): array {
  $errors = []; $fields = [];
  $limits = ['nom_complet'=>120, 'adresse'=>160, 'biographie'=>2000];

  foreach ($limits as $k => $max) {
    if (array_key_exists($k, $in)) {
      $v = trim((string)$in[$k]);
      if (mb_strlen($v) > $max) { $errors[] = "$k:trop long (max $max)"; }
      else { $fields[$k] = $v; }
    }
  }

  if (array_key_exists('telephone', $in)) {
    $t = preg_replace('/\D/', '', (string)$in['telephone']);
    if (!preg_match('/^\d{9}$/', $t)) {
      $errors[] = 'telephone:9 chiffres attendus';
    } else {
      foreach (($data['jak'] ?? []) as $m) {
        if (($m['id'] ?? '') !== $selfId && ($m['telephone'] ?? '') === $t) {
          $errors[] = 'telephone:déjà utilisé par un autre membre';
          break;
        }
      }
      if (!in_array('telephone:déjà utilisé par un autre membre', $errors, true)) $fields['telephone'] = $t;
    }
  }

  if (array_key_exists('photo', $in)) {
    $p = (string)$in['photo'];
    $isData = str_starts_with($p, 'data:image/');
    $isPath = preg_match('#^[\w./\-]+\.(png|jpe?g|webp)$#i', $p);
    if ($p !== '' && !$isData && !$isPath) { $errors[] = 'photo:format invalide'; }
    elseif ($isData && strlen($p) > 2_000_000) { $errors[] = 'photo:image trop lourde'; }
    else { $fields['photo'] = $p; }
  }

  return ['errors'=>$errors, 'fields'=>$fields];
}
