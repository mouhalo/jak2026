<?php
function store_load(array $cfg): array {
  if (!is_file($cfg['data_path'])) return [];
  $raw = file_get_contents($cfg['data_path']);
  $d = json_decode($raw, true);
  return is_array($d) ? $d : [];
}

function store_save(array $cfg, array $data): void {
  $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  _atomic_write($cfg['data_path'], $json);
  $js = 'window.SITE_DATA='.json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).";\n";
  _atomic_write($cfg['datajs_path'], $js);
}

function _atomic_write(string $path, string $content): void {
  $tmp = $path.'.tmp'.getmypid();
  file_put_contents($tmp, $content, LOCK_EX);
  rename($tmp, $path);
}

function member_find_by_phone(array $data, string $phone9): ?array {
  foreach (($data['jak'] ?? []) as $m) {
    if (($m['telephone'] ?? '') !== '' && $m['telephone'] === $phone9) return $m;
  }
  return null;
}

function member_find_by_id(array $data, string $id): ?array {
  foreach (($data['jak'] ?? []) as $m) {
    if (($m['id'] ?? '') === $id) return $m;
  }
  return null;
}

function member_apply_update(array &$data, string $id, array $fields): bool {
  $allowed = ['photo','nom_complet','adresse','telephone','biographie'];
  foreach (($data['jak'] ?? []) as $i => $m) {
    if (($m['id'] ?? '') === $id) {
      foreach ($allowed as $k) {
        if (array_key_exists($k, $fields)) $data['jak'][$i][$k] = $fields[$k];
      }
      return true;
    }
  }
  return false;
}
