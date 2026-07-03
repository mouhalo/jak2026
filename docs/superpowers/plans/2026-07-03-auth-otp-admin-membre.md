# Authentification OTP (Admin & Membres) — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Protéger `admin.html` par OTP et permettre à chaque membre d'éditer sa fiche après un OTP WhatsApp, sans base de données.

**Architecture:** Fine couche PHP sur jak.sn (même origine) qui génère/vérifie les OTP (session PHP, code haché), envoie le code via l'API WhatsApp d'ICELABSOFT, et lit/écrit `data.json` (canonique) tout en régénérant `data.js` (repli statique). Le front reste inchangé pour l'affichage ; un `auth.js` ajoute l'accès membre.

**Tech Stack:** PHP 8.3 (CLI `C:\php\php.exe`, extensions curl/json/session/openssl/mbstring), JavaScript vanilla ES6, hébergement LiteSpeed/N0C (PHP + `.htaccess`).

## Global Constraints

- **Canal OTP : uniquement WhatsApp** (`https://api.icelabsoft.com/whatsapp_service/api/send_otp`), corps `{telephone, code, langue}`, téléphone **E.164 `+221`+9 chiffres**, code **6 chiffres**, `langue:"fr"`. Pas de repli SMS/e-mail.
- **Pas de base de données** — le datastore est `data.json` ; `data.js` est un artefact régénéré (`window.SITE_DATA=<json>;`).
- **Numéro admin** : `777301221` (9 chiffres), dans `config.php` uniquement, jamais exposé au client.
- **OTP** : haché (HMAC-SHA256 + secret), expiration **300 s**, **max 5 tentatives**, **cooldown 60 s**, 1 défi actif par session.
- **Sessions** : cookie `httponly=1`, `samesite=Lax`, `secure=1` **seulement en HTTPS** (désactivé en local http) ; `session_regenerate_id(true)` à la connexion.
- **Cloisonnement** : un membre ne modifie que la fiche de son `member_id` de session ; `id` immuable ; `telephone` = 9 chiffres, unique.
- **Test PHP** : scripts CLI avec assertions maison (`api/tests/_assert.php`), lancés via `C:\php\php.exe <fichier>` ; exit 0 = OK.
- **Outil PHP** : partout où le plan écrit `php`, utiliser `C:\php\php.exe`.
- Respecter la charte (`RAPPORT_GENERAL.md §4`), l'i18n `I18N` de `site.js`, les propriétés CSS logiques (RTL), cibles ≥ 44 px, `prefers-reduced-motion`.

---

## Structure des fichiers

```
jak2026/
├── api/
│   ├── lib/
│   │   ├── config.php       # secrets + réglages (NON committé)
│   │   ├── bootstrap.php    # session, helpers JSON (json_out, read_body)
│   │   ├── store.php        # data.json load/save (+ régen data.js), recherche/maj membre
│   │   ├── validate.php     # validation des champs fiche
│   │   ├── otp.php          # défi OTP (set/verify) sur un tableau de session
│   │   ├── whatsapp.php     # envoi OTP via ICELABSOFT (transport injectable)
│   │   └── auth.php         # login/logout/current_auth/require_role
│   ├── auth/{request-otp,verify-otp,logout}.php
│   ├── session.php
│   ├── member/save-fiche.php
│   ├── admin/save-data.php
│   ├── tools/seed.js        # génère data.json depuis data.js (Node)
│   └── tests/{_assert,store_test,validate_test,otp_test,whatsapp_test}.php
├── admin.php                # garde OTP admin → inclut admin.html
├── auth.js                  # front : accès membre + éditeur + état session
├── data.json                # canonique (seed, NON committé)
└── data.js                  # régénéré
```

---

### Task 1: Initialisation, config & garde `.htaccess`

**Files:**
- Create: `api/lib/config.php`
- Modify: `.gitignore`
- Modify: `.htaccess` (racine)
- Modify: `deploy.ps1:33-45` (exclusions)

**Interfaces:**
- Produces: `require 'api/lib/config.php'` retourne un tableau `['admin_phone','whatsapp_url','otp_secret','otp_ttl','otp_max_try','otp_resend','session_ttl','data_path','datajs_path','langue']`.

- [ ] **Step 1: Initialiser git (versionnement + commits du plan)**

Run:
```bash
git init && git add -A && git commit -m "chore: snapshot avant feature auth OTP"
```
Expected: dépôt initialisé, 1 commit.

- [ ] **Step 2: Générer le secret OTP**

Run:
```bash
C:/php/php.exe -r "echo bin2hex(random_bytes(32));"
```
Expected: une chaîne hexadécimale de 64 caractères — la copier pour l'étape suivante.

- [ ] **Step 3: Créer `api/lib/config.php`** (coller le secret généré dans `otp_secret`)

```php
<?php
// Secrets & réglages — NE PAS committer (voir .gitignore).
return [
  'admin_phone'  => '777301221',                 // 9 chiffres → +221777301221
  'whatsapp_url' => 'https://api.icelabsoft.com/whatsapp_service/api/send_otp',
  'otp_secret'   => 'COLLER_LE_SECRET_HEX_ICI',   // 64 hex de l'étape 2
  'otp_ttl'      => 300,   // s
  'otp_max_try'  => 5,
  'otp_resend'   => 60,    // s
  'session_ttl'  => 7200,  // s
  'langue'       => 'fr',
  'data_path'    => __DIR__ . '/../../data.json',
  'datajs_path'  => __DIR__ . '/../../data.js',
];
```

- [ ] **Step 4: Protéger les secrets et l'état — `.gitignore`**

Ajouter à la fin de `.gitignore` :
```
# Auth OTP — secrets & état serveur
api/lib/config.php
data.json
```

- [ ] **Step 5: Interdire l'accès HTTP direct à `admin.html`** (il ne sera servi que via `admin.php`)

Ajouter dans `.htaccess`, à l'intérieur du bloc `<IfModule mod_headers.c>` juste après le bloc `<Files "admin.html">` existant, un nouveau bloc au niveau racine (hors IfModule) :
```apache
# admin.html n'est accessible que via admin.php (lecture disque côté serveur)
<Files "admin.html">
  Require all denied
</Files>
```

- [ ] **Step 6: Exclure `data.json` et `config.php` du déploiement écrasant**

Dans `deploy.ps1`, la liste `$excludeNames` (vers la ligne 35) devient :
```powershell
$excludeNames = @('.env', 'deploy.ps1', '.gitignore', 'data.json')
```
> `data.json` est écrit en ligne : on ne le ré-uploade jamais après le seed initial. `config.php` **doit** être uploadé (le serveur en a besoin) ; il reste hors git via `.gitignore`, mais présent sur disque donc déployé. `api/lib/config.php` n'étant pas un `.md`, il part bien.

- [ ] **Step 7: Vérifier que la config se charge**

Run:
```bash
C:/php/php.exe -r "$c=require 'api/lib/config.php'; echo $c['admin_phone'],'|',strlen($c['otp_secret']);"
```
Expected: `777301221|64`

- [ ] **Step 8: Commit**

```bash
git add .gitignore .htaccess deploy.ps1
git commit -m "chore(auth): config, gitignore secrets, garde admin.html, exclusion deploy"
```

---

### Task 2: Module store (`data.json` ↔ `data.js`, recherche membre)

**Files:**
- Create: `api/lib/store.php`
- Create: `api/tests/_assert.php`
- Test: `api/tests/store_test.php`

**Interfaces:**
- Produces:
  - `store_load(array $cfg): array` — lit `data.json`, renvoie le tableau SITE_DATA.
  - `store_save(array $cfg, array $data): void` — écrit `data.json` (pretty) **et** régénère `data.js` (`window.SITE_DATA=<json>;`), écriture atomique.
  - `member_find_by_phone(array $data, string $phone9): ?array` — renvoie l'entrée `jak[]` ou null.
  - `member_find_by_id(array $data, string $id): ?array`
  - `member_apply_update(array &$data, string $id, array $fields): bool` — remplace les champs autorisés de l'entrée `id`, renvoie false si introuvable.

- [ ] **Step 1: Créer le helper d'assertions `api/tests/_assert.php`**

```php
<?php
$GLOBALS['_t']=0; $GLOBALS['_f']=0;
function ok($c,$m){ $GLOBALS['_t']++; if($c){echo "  PASS: $m\n";} else {$GLOBALS['_f']++; echo "  FAIL: $m\n";} }
function eq($a,$b,$m){ ok($a===$b, $m." [attendu ".var_export($b,true).", obtenu ".var_export($a,true)."]"); }
function done(){ $p=$GLOBALS['_t']-$GLOBALS['_f']; echo "\n$p/{$GLOBALS['_t']} OK\n"; exit($GLOBALS['_f']?1:0); }
```

- [ ] **Step 2: Écrire le test `api/tests/store_test.php`**

```php
<?php
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/store.php';

$tmp = sys_get_temp_dir().'/jak_test_'.uniqid();
$cfg = ['data_path'=>$tmp.'.json', 'datajs_path'=>$tmp.'.js'];
$data = ['settings'=>['x'=>1], 'jak'=>[
  ['id'=>'m-a','telephone'=>'770000001','nom_complet'=>'A','biographie'=>'ba','adresse'=>'aa','photo'=>'p1','fondateur'=>'NON','fonction'=>''],
  ['id'=>'m-b','telephone'=>'770000002','nom_complet'=>'B','biographie'=>'bb','adresse'=>'ab','photo'=>'p2','fondateur'=>'OUI','fonction'=>'Prés'],
]];

store_save($cfg, $data);
ok(is_file($cfg['data_path']), 'data.json écrit');
ok(is_file($cfg['datajs_path']), 'data.js régénéré');
$js = file_get_contents($cfg['datajs_path']);
ok(str_starts_with($js,'window.SITE_DATA='), 'data.js commence par window.SITE_DATA=');
ok(str_ends_with(trim($js),';'), 'data.js finit par ;');

$reloaded = store_load($cfg);
eq($reloaded['settings']['x'], 1, 'store_load relit settings');

$m = member_find_by_phone($data, '770000002');
eq($m['id'], 'm-b', 'find_by_phone trouve m-b');
eq(member_find_by_phone($data, '999999999'), null, 'find_by_phone inconnu = null');
eq(member_find_by_id($data,'m-a')['nom_complet'], 'A', 'find_by_id trouve A');

$okUpd = member_apply_update($data, 'm-a', ['nom_complet'=>'AA','telephone'=>'770000009','adresse'=>'x','biographie'=>'y','photo'=>'z']);
ok($okUpd===true, 'update renvoie true');
eq($data['jak'][0]['nom_complet'], 'AA', 'nom mis à jour');
eq($data['jak'][0]['telephone'], '770000009', 'téléphone mis à jour');
eq($data['jak'][0]['id'], 'm-a', 'id inchangé');
ok(member_apply_update($data,'inconnu',[])===false, 'update id inconnu = false');

@unlink($cfg['data_path']); @unlink($cfg['datajs_path']);
done();
```

- [ ] **Step 3: Lancer le test (doit échouer)**

Run: `C:/php/php.exe api/tests/store_test.php`
Expected: FAIL — `store.php` / fonctions non définies (erreur `require`/undefined function).

- [ ] **Step 4: Implémenter `api/lib/store.php`**

```php
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
```

- [ ] **Step 5: Lancer le test (doit passer)**

Run: `C:/php/php.exe api/tests/store_test.php`
Expected: PASS — dernière ligne `.../... OK`, exit 0.

- [ ] **Step 6: Commit**

```bash
git add api/lib/store.php api/tests/_assert.php api/tests/store_test.php
git commit -m "feat(auth): module store data.json + régénération data.js"
```

---

### Task 3: Validation des champs fiche

**Files:**
- Create: `api/lib/validate.php`
- Test: `api/tests/validate_test.php`

**Interfaces:**
- Produces: `validate_member_fields(array $in, array $data, string $selfId): array` — renvoie `['errors'=>string[], 'fields'=>array]`. `fields` contient uniquement les clés valides prêtes pour `member_apply_update`. `errors` vide = OK.

- [ ] **Step 1: Écrire `api/tests/validate_test.php`**

```php
<?php
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/validate.php';

$data = ['jak'=>[
  ['id'=>'m-a','telephone'=>'770000001'],
  ['id'=>'m-b','telephone'=>'770000002'],
]];

// Cas valide
$r = validate_member_fields(
  ['nom_complet'=>'Ablaye','adresse'=>'Dakar','telephone'=>'771112233','biographie'=>'Bio','photo'=>'data:image/jpeg;base64,AAAA'],
  $data, 'm-a');
eq($r['errors'], [], 'aucune erreur sur entrée valide');
eq($r['fields']['telephone'], '771112233', 'téléphone conservé');

// Téléphone mauvais format
$r = validate_member_fields(['telephone'=>'12345'], $data, 'm-a');
ok(in_array('telephone', array_map(fn($e)=>explode(':',$e)[0], $r['errors'])) || count($r['errors'])>0, 'téléphone 5 chiffres rejeté');

// Téléphone déjà pris par un AUTRE membre
$r = validate_member_fields(['telephone'=>'770000002'], $data, 'm-a');
ok(count($r['errors'])>0, 'téléphone d’un autre membre rejeté');

// Garder son propre téléphone est autorisé
$r = validate_member_fields(['telephone'=>'770000001'], $data, 'm-a');
eq($r['errors'], [], 'garder son propre numéro est OK');

// Nom trop long
$r = validate_member_fields(['nom_complet'=>str_repeat('x',200)], $data, 'm-a');
ok(count($r['errors'])>0, 'nom > 120 rejeté');

// id/fondateur ignorés (non modifiables)
$r = validate_member_fields(['id'=>'hack','fondateur'=>'OUI','nom_complet'=>'Ok'], $data, 'm-a');
ok(!array_key_exists('id',$r['fields']) && !array_key_exists('fondateur',$r['fields']), 'id/fondateur non retenus');

done();
```

- [ ] **Step 2: Lancer (doit échouer)**

Run: `C:/php/php.exe api/tests/validate_test.php`
Expected: FAIL — fonction non définie.

- [ ] **Step 3: Implémenter `api/lib/validate.php`**

```php
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
```

- [ ] **Step 4: Lancer (doit passer)**

Run: `C:/php/php.exe api/tests/validate_test.php`
Expected: PASS, exit 0.

- [ ] **Step 5: Commit**

```bash
git add api/lib/validate.php api/tests/validate_test.php
git commit -m "feat(auth): validation des champs fiche membre"
```

---

### Task 4: Module OTP (défi haché, expiration, tentatives, cooldown)

**Files:**
- Create: `api/lib/otp.php`
- Test: `api/tests/otp_test.php`

**Interfaces:**
- Produces (opèrent sur un tableau `&$sess` = sous-tableau de session, injectable pour test) :
  - `otp_generate(): string` — 6 chiffres.
  - `otp_can_send(array $sess, int $now, array $cfg): bool` — false si dernier envoi < `otp_resend`.
  - `otp_set_challenge(array &$sess, string $role, ?string $memberId, string $phone9, string $code, int $now, array $cfg): void`
  - `otp_verify(array &$sess, string $input, int $now, array $cfg): array` — `['ok'=>bool,'reason'=>string,'role'=>?,'member_id'=>?]`. Efface le défi si succès ou si tentatives épuisées/expiré.

- [ ] **Step 1: Écrire `api/tests/otp_test.php`**

```php
<?php
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/otp.php';
$cfg = ['otp_secret'=>'s3cr3t','otp_ttl'=>300,'otp_max_try'=>5,'otp_resend'=>60];

// Génération
$c = otp_generate();
ok(preg_match('/^\d{6}$/',$c)===1, 'code = 6 chiffres');

// Cooldown
$sess = [];
ok(otp_can_send($sess, 1000, $cfg)===true, 'envoi permis si pas de défi');
otp_set_challenge($sess, 'membre', 'm-a', '770000001', '123456', 1000, $cfg);
ok(otp_can_send($sess, 1030, $cfg)===false, 'renvoi bloqué avant 60 s');
ok(otp_can_send($sess, 1061, $cfg)===true, 'renvoi permis après 60 s');
ok(!isset($sess['code']), 'code jamais stocké en clair');

// Mauvais code incrémente
$r = otp_verify($sess, '000000', 1010, $cfg);
ok($r['ok']===false && $r['reason']==='bad', 'mauvais code refusé');

// Bon code réussit + renvoie contexte
$r = otp_verify($sess, '123456', 1010, $cfg);
ok($r['ok']===true, 'bon code accepté');
eq($r['role'], 'membre', 'role renvoyé');
eq($r['member_id'], 'm-a', 'member_id renvoyé');
ok(empty($sess), 'défi effacé après succès');

// Expiration
$sess=[]; otp_set_challenge($sess,'admin',null,'777301221','654321',2000,$cfg);
$r = otp_verify($sess, '654321', 2000+301, $cfg);
ok($r['ok']===false && $r['reason']==='expired', 'code expiré refusé');
ok(empty($sess), 'défi expiré effacé');

// Blocage après max tentatives
$sess=[]; otp_set_challenge($sess,'admin',null,'777301221','654321',3000,$cfg);
for($i=0;$i<5;$i++){ $r=otp_verify($sess,'000000',3001,$cfg); }
ok($r['reason']==='locked' || empty($sess), 'verrouillé après 5 essais');
$r = otp_verify($sess,'654321',3002,$cfg);
ok($r['ok']===false, 'bon code refusé une fois verrouillé');

done();
```

- [ ] **Step 2: Lancer (doit échouer)**

Run: `C:/php/php.exe api/tests/otp_test.php`
Expected: FAIL — fonctions non définies.

- [ ] **Step 3: Implémenter `api/lib/otp.php`**

```php
<?php
function otp_generate(): string {
  return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function _otp_hash(string $code, array $cfg): string {
  return hash_hmac('sha256', $code, $cfg['otp_secret']);
}

function otp_can_send(array $sess, int $now, array $cfg): bool {
  if (empty($sess['last_send'])) return true;
  return ($now - $sess['last_send']) >= $cfg['otp_resend'];
}

function otp_set_challenge(array &$sess, string $role, ?string $memberId, string $phone9, string $code, int $now, array $cfg): void {
  $sess = [
    'hash'      => _otp_hash($code, $cfg),
    'role'      => $role,
    'member_id' => $memberId,
    'phone'     => $phone9,
    'expire'    => $now + $cfg['otp_ttl'],
    'attempts'  => 0,
    'last_send' => $now,
  ];
}

function otp_verify(array &$sess, string $input, int $now, array $cfg): array {
  if (empty($sess['hash'])) return ['ok'=>false,'reason'=>'none','role'=>null,'member_id'=>null];
  if ($now > $sess['expire']) { $sess = []; return ['ok'=>false,'reason'=>'expired','role'=>null,'member_id'=>null]; }
  if ($sess['attempts'] >= $cfg['otp_max_try']) { $sess = []; return ['ok'=>false,'reason'=>'locked','role'=>null,'member_id'=>null]; }
  $sess['attempts']++;
  if (hash_equals($sess['hash'], _otp_hash($input, $cfg))) {
    $role = $sess['role']; $mid = $sess['member_id'];
    $sess = [];
    return ['ok'=>true,'reason'=>'ok','role'=>$role,'member_id'=>$mid];
  }
  if ($sess['attempts'] >= $cfg['otp_max_try']) { $sess = []; return ['ok'=>false,'reason'=>'locked','role'=>null,'member_id'=>null]; }
  return ['ok'=>false,'reason'=>'bad','role'=>null,'member_id'=>null];
}
```

- [ ] **Step 4: Lancer (doit passer)**

Run: `C:/php/php.exe api/tests/otp_test.php`
Expected: PASS, exit 0.

- [ ] **Step 5: Commit**

```bash
git add api/lib/otp.php api/tests/otp_test.php
git commit -m "feat(auth): module OTP (défi haché, expiration, tentatives, cooldown)"
```

---

### Task 5: Module WhatsApp (envoi via ICELABSOFT, transport injectable)

**Files:**
- Create: `api/lib/whatsapp.php`
- Test: `api/tests/whatsapp_test.php`

**Interfaces:**
- Produces:
  - `wa_e164(string $phone9): string` — `'+221'.$phone9`.
  - `wa_send_otp(string $phone9, string $code, array $cfg, ?callable $transport=null): array` — `['ok'=>bool,'message'=>string,'error_code'=>?]`. `$transport(string $url, array $body): array` renvoie `['status'=>int,'json'=>array]` ; par défaut, implémentation cURL réelle.

- [ ] **Step 1: Écrire `api/tests/whatsapp_test.php`** (transport simulé — aucun envoi réel)

```php
<?php
require __DIR__.'/_assert.php';
require __DIR__.'/../lib/whatsapp.php';
$cfg = ['whatsapp_url'=>'https://example/send_otp','langue'=>'fr'];

eq(wa_e164('777301221'), '+221777301221', 'format E.164 +221');

// Transport qui capture le corps et simule un succès
$captured = null;
$t = function($url,$body) use (&$captured){ $captured=['url'=>$url,'body'=>$body]; return ['status'=>200,'json'=>['success'=>true,'message'=>'ok','message_id'=>'abc']]; };
$r = wa_send_otp('777301221','123456',$cfg,$t);
ok($r['ok']===true, 'succès WhatsApp');
eq($captured['body']['telephone'], '+221777301221', 'telephone E.164 envoyé');
eq($captured['body']['code'], '123456', 'code envoyé');
eq($captured['body']['langue'], 'fr', 'langue fr envoyée');

// Échec métier
$t2 = function($url,$body){ return ['status'=>200,'json'=>['success'=>false,'message'=>'KO','error_code'=>'META_RATE_LIMIT']]; };
$r = wa_send_otp('777301221','123456',$cfg,$t2);
ok($r['ok']===false && $r['error_code']==='META_RATE_LIMIT', 'échec métier remonté');

done();
```

- [ ] **Step 2: Lancer (doit échouer)**

Run: `C:/php/php.exe api/tests/whatsapp_test.php`
Expected: FAIL — fonction non définie.

- [ ] **Step 3: Implémenter `api/lib/whatsapp.php`**

```php
<?php
function wa_e164(string $phone9): string { return '+221'.$phone9; }

function _wa_curl(string $url, array $body): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $json = json_decode((string)$raw, true);
  return ['status'=>$status, 'json'=>is_array($json)?$json:[]];
}

function wa_send_otp(string $phone9, string $code, array $cfg, ?callable $transport=null): array {
  $transport = $transport ?? '_wa_curl';
  $body = ['telephone'=>wa_e164($phone9), 'code'=>$code, 'langue'=>$cfg['langue'] ?? 'fr'];
  try {
    $res = $transport($cfg['whatsapp_url'], $body);
  } catch (\Throwable $e) {
    return ['ok'=>false, 'message'=>'Service WhatsApp indisponible', 'error_code'=>'NETWORK'];
  }
  $j = $res['json'] ?? [];
  $ok = ($res['status'] ?? 0) === 200 && !empty($j['success']);
  return ['ok'=>$ok, 'message'=>$j['message'] ?? '', 'error_code'=>$j['error_code'] ?? null];
}
```

- [ ] **Step 4: Lancer (doit passer)**

Run: `C:/php/php.exe api/tests/whatsapp_test.php`
Expected: PASS, exit 0.

- [ ] **Step 5: Commit**

```bash
git add api/lib/whatsapp.php api/tests/whatsapp_test.php
git commit -m "feat(auth): module envoi OTP WhatsApp (transport injectable)"
```

---

### Task 6: Bootstrap (session sécurisée + helpers JSON) & module auth

**Files:**
- Create: `api/lib/bootstrap.php`
- Create: `api/lib/auth.php`

**Interfaces:**
- Produces (`bootstrap.php`) :
  - `app_boot(): array` — configure et démarre la session, renvoie `$cfg`.
  - `json_out(array $payload, int $status=200): never` — envoie JSON et termine.
  - `read_body(): array` — décode le corps JSON de la requête.
- Produces (`auth.php`) :
  - `auth_login(string $role, ?string $memberId, array $cfg): void` — `session_regenerate_id(true)` + pose `$_SESSION['auth']`.
  - `auth_current(array $cfg): ?array` — `['role','member_id']` si session valide et non expirée, sinon null.
  - `auth_logout(): void`
  - `require_role(string $role, array $cfg): array` — renvoie l'auth ou `json_out(...,401)`.

- [ ] **Step 1: Implémenter `api/lib/bootstrap.php`**

```php
<?php
function app_boot(): array {
  $cfg = require __DIR__.'/config.php';
  $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off');
  session_set_cookie_params([
    'lifetime'=>0, 'path'=>'/', 'httponly'=>true,
    'secure'=>$https, 'samesite'=>'Lax',
  ]);
  session_start();
  header('Content-Type: application/json; charset=utf-8');
  return $cfg;
}

function json_out(array $payload, int $status=200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function read_body(): array {
  $raw = file_get_contents('php://input');
  $d = json_decode((string)$raw, true);
  return is_array($d) ? $d : [];
}
```

- [ ] **Step 2: Implémenter `api/lib/auth.php`**

```php
<?php
function auth_login(string $role, ?string $memberId, array $cfg): void {
  session_regenerate_id(true);
  $_SESSION['auth'] = ['role'=>$role, 'member_id'=>$memberId, 'exp'=>time()+$cfg['session_ttl']];
}

function auth_current(array $cfg): ?array {
  $a = $_SESSION['auth'] ?? null;
  if (!$a || ($a['exp'] ?? 0) < time()) return null;
  return ['role'=>$a['role'], 'member_id'=>$a['member_id'] ?? null];
}

function auth_logout(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
}

function require_role(string $role, array $cfg): array {
  $a = auth_current($cfg);
  if (!$a || $a['role'] !== $role) json_out(['success'=>false,'message'=>'Non autorisé'], 401);
  return $a;
}
```

- [ ] **Step 3: Vérification de syntaxe (lint) des deux fichiers**

Run:
```bash
C:/php/php.exe -l api/lib/bootstrap.php && C:/php/php.exe -l api/lib/auth.php
```
Expected: `No syntax errors detected` pour chacun.

- [ ] **Step 4: Commit**

```bash
git add api/lib/bootstrap.php api/lib/auth.php
git commit -m "feat(auth): bootstrap session sécurisée + module auth (login/logout/roles)"
```

---

### Task 7: Endpoints d'authentification + `session.php`

**Files:**
- Create: `api/auth/request-otp.php`
- Create: `api/auth/verify-otp.php`
- Create: `api/auth/logout.php`
- Create: `api/session.php`

**Interfaces:**
- Consumes: `app_boot`, `json_out`, `read_body`, `store_load`, `member_find_by_phone`, `otp_*`, `wa_send_otp`, `auth_*`, `require_role`.
- Produces (HTTP JSON) : contrats du §7 du spec. Le défi OTP vit dans `$_SESSION['otp']`.

- [ ] **Step 1: Implémenter `api/auth/request-otp.php`**

```php
<?php
$cfg = require __DIR__.'/../lib/bootstrap.php' ? require __DIR__.'/../lib/bootstrap.php' : null; // placeholder replaced below
```
> Remplacer le contenu ci-dessus par le fichier complet suivant :

```php
<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/store.php';
require_once __DIR__.'/../lib/otp.php';
require_once __DIR__.'/../lib/whatsapp.php';
$cfg = app_boot();

$in = read_body();
$role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'membre';
$now = time();
$sess = $_SESSION['otp'] ?? [];

if (!otp_can_send($sess, $now, $cfg)) {
  json_out(['success'=>false,'message'=>'Veuillez patienter avant un nouvel envoi.','cooldown'=>$cfg['otp_resend']], 429);
}

if ($role === 'admin') {
  $phone = $cfg['admin_phone']; $memberId = null;
} else {
  $phone9 = preg_replace('/\D/', '', (string)($in['telephone'] ?? ''));
  $data = store_load($cfg);
  $m = strlen($phone9)===9 ? member_find_by_phone($data, $phone9) : null;
  // Anti-énumération : réponse identique même si le membre n'existe pas.
  if (!$m) json_out(['success'=>true,'message'=>'Si ce numéro correspond à une fiche, un code a été envoyé.','cooldown'=>$cfg['otp_resend']]);
  $phone = $phone9; $memberId = $m['id'];
}

$code = otp_generate();
otp_set_challenge($sess, $role, $memberId, $phone, $code, $now, $cfg);
$_SESSION['otp'] = $sess;

$res = wa_send_otp($phone, $code, $cfg);
if (!$res['ok']) {
  json_out(['success'=>false,'message'=>'Envoi du code impossible pour le moment. Réessayez.'], 502);
}
$msg = $role==='admin' ? 'Code envoyé au numéro administrateur.' : 'Si ce numéro correspond à une fiche, un code a été envoyé.';
json_out(['success'=>true,'message'=>$msg,'cooldown'=>$cfg['otp_resend']]);
```

- [ ] **Step 2: Implémenter `api/auth/verify-otp.php`**

```php
<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/store.php';
require_once __DIR__.'/../lib/otp.php';
require_once __DIR__.'/../lib/auth.php';
$cfg = app_boot();

$in = read_body();
$code = preg_replace('/\D/', '', (string)($in['code'] ?? ''));
$sess = $_SESSION['otp'] ?? [];
$r = otp_verify($sess, $code, time(), $cfg);
$_SESSION['otp'] = $sess;

if (!$r['ok']) {
  $map = ['expired'=>'Code expiré, redemandez-en un.','locked'=>'Trop de tentatives, redemandez un code.','none'=>'Aucun code en cours.','bad'=>'Code incorrect.'];
  json_out(['success'=>false,'message'=>$map[$r['reason']] ?? 'Code invalide.'], 401);
}

auth_login($r['role'], $r['member_id'], $cfg);
unset($_SESSION['otp']);

$member = null;
if ($r['role']==='membre') {
  $data = store_load($cfg);
  $member = member_find_by_id($data, $r['member_id']);
}
json_out(['success'=>true,'role'=>$r['role'],'member'=>$member]);
```

- [ ] **Step 3: Implémenter `api/auth/logout.php`**

```php
<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/auth.php';
app_boot();
auth_logout();
json_out(['success'=>true]);
```

- [ ] **Step 4: Implémenter `api/session.php`**

```php
<?php
require_once __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/auth.php';
$cfg = app_boot();
$a = auth_current($cfg);
json_out(['authenticated'=>(bool)$a, 'role'=>$a['role'] ?? null, 'member_id'=>$a['member_id'] ?? null]);
```

- [ ] **Step 5: Lint des 4 endpoints**

Run:
```bash
C:/php/php.exe -l api/auth/request-otp.php && C:/php/php.exe -l api/auth/verify-otp.php && C:/php/php.exe -l api/auth/logout.php && C:/php/php.exe -l api/session.php
```
Expected: `No syntax errors detected` × 4.
> Note : le bloc « placeholder » de l'étape 1 doit avoir été **entièrement remplacé** par le fichier complet.

- [ ] **Step 6: Test manuel du parcours admin (envoi réel — consomme 1 quota)**

Démarrer le serveur : `C:/php/php.exe -S localhost:3000` (depuis la racine du projet).
Run (nouvelle console) :
```bash
curl -s -c cj.txt -X POST http://localhost:3000/api/auth/request-otp.php -H "Content-Type: application/json" -d '{"role":"admin"}'
```
Expected: `{"success":true,...}` et un **code WhatsApp reçu sur 777301221**. Puis :
```bash
curl -s -b cj.txt -X POST http://localhost:3000/api/auth/verify-otp.php -H "Content-Type: application/json" -d '{"code":"LE_CODE_RECU"}'
```
Expected: `{"success":true,"role":"admin",...}`. Vérifier aussi `curl -s -b cj.txt http://localhost:3000/api/session.php` → `{"authenticated":true,"role":"admin",...}`.

- [ ] **Step 7: Commit**

```bash
git add api/auth/ api/session.php
git commit -m "feat(auth): endpoints request-otp / verify-otp / logout / session"
```

---

### Task 8: Seed `data.json` (avec `id` + `telephone`)

**Files:**
- Create: `api/tools/seed.js`
- Create: `data.json` (généré)

**Interfaces:**
- Consumes: `data.js` existant. Produces: `data.json` conforme, chaque `jak[]` doté de `id` (slug) et `telephone` (`""`).

- [ ] **Step 1: Écrire `api/tools/seed.js`** (Node est disponible)

```js
// Génère data.json depuis data.js en ajoutant id + telephone aux membres.
const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '..', '..');
const window = {};
eval(fs.readFileSync(path.join(root, 'data.js'), 'utf8')); // définit window.SITE_DATA
const d = window.SITE_DATA;

const slug = (s, i) => 'm-' + (String(s||'').toLowerCase()
  .normalize('NFD').replace(/[̀-ͯ]/g,'')
  .replace(/\[.*?\]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'') || ('membre-'+(i+1)));

d.jak = (d.jak || []).map((m, i) => ({
  id: m.id || slug(m.nom_complet, i),
  telephone: m.telephone || '',
  ...m,
}));

fs.writeFileSync(path.join(root, 'data.json'),
  JSON.stringify(d, null, 2), 'utf8');
console.log('data.json généré :', d.jak.length, 'membres');
```

- [ ] **Step 2: Lancer le seed**

Run: `node api/tools/seed.js`
Expected: `data.json généré : N membres`.

- [ ] **Step 3: Vérifier le JSON et la présence des champs**

Run:
```bash
C:/php/php.exe -r "$d=json_decode(file_get_contents('data.json'),true); echo count($d['jak']),' membres; id0=',$d['jak'][0]['id'],'; tel0=[',$d['jak'][0]['telephone'],']';"
```
Expected: nombre de membres + un `id` non vide + `tel0=[]` (vide).

- [ ] **Step 4: Commit** (data.json est gitignore ; on committe seulement l'outil)

```bash
git add api/tools/seed.js
git commit -m "feat(auth): outil de seed data.json (id + telephone)"
```

---

### Task 9: Endpoint `member/save-fiche.php` (cloisonné)

**Files:**
- Create: `api/member/save-fiche.php`

**Interfaces:**
- Consumes: `require_role('membre')`, `store_load/store_save`, `validate_member_fields`, `member_apply_update`, `member_find_by_id`.
- Produces (HTTP) : `{success, member}` ou `{success:false, errors}`.

- [ ] **Step 1: Implémenter `api/member/save-fiche.php`**

```php
<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/store.php';
require_once __DIR__.'/../lib/validate.php';
$cfg = app_boot();
$a = require_role('membre', $cfg);

$in = read_body();
$data = store_load($cfg);
$v = validate_member_fields($in, $data, $a['member_id']);
if ($v['errors']) json_out(['success'=>false,'message'=>'Champs invalides','errors'=>$v['errors']], 422);

if (!member_apply_update($data, $a['member_id'], $v['fields'])) {
  json_out(['success'=>false,'message'=>'Fiche introuvable'], 404);
}
store_save($cfg, $data);
json_out(['success'=>true, 'member'=>member_find_by_id($data, $a['member_id'])]);
```

- [ ] **Step 2: Lint**

Run: `C:/php/php.exe -l api/member/save-fiche.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Test manuel de bout en bout (cloisonnement)**

Prérequis : dans `data.json`, donner temporairement à un membre le téléphone `777301221` (pour recevoir l'OTP sur votre numéro de test), relancer si besoin. Serveur : `C:/php/php.exe -S localhost:3000`.
```bash
curl -s -c cj.txt -X POST http://localhost:3000/api/auth/request-otp.php -H "Content-Type: application/json" -d '{"role":"membre","telephone":"777301221"}'
# saisir le code reçu :
curl -s -b cj.txt -X POST http://localhost:3000/api/auth/verify-otp.php -H "Content-Type: application/json" -d '{"code":"CODE"}'
curl -s -b cj.txt -X POST http://localhost:3000/api/member/save-fiche.php -H "Content-Type: application/json" -d '{"nom_complet":"Test Membre","adresse":"Dakar","biographie":"Bio test","telephone":"777301221"}'
```
Expected: dernier appel `{"success":true,"member":{..."nom_complet":"Test Membre"...}}` ; vérifier que `data.js` a été régénéré (`grep "Test Membre" data.js`).

- [ ] **Step 4: Commit**

```bash
git add api/member/save-fiche.php
git commit -m "feat(auth): endpoint save-fiche membre (validation + cloisonnement)"
```

---

### Task 10: Garde admin (`admin.php`) + sauvegarde admin

**Files:**
- Create: `admin.php`
- Create: `api/admin/save-data.php`

**Interfaces:**
- Consumes: `auth_current`, `require_role('admin')`, `store_save`.
- Produces: `admin.php` sert la connexion OTP si non-admin, sinon inclut `admin.html` ; `admin/save-data.php` écrit `data.json`.

- [ ] **Step 1: Implémenter `api/admin/save-data.php`**

```php
<?php
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/store.php';
$cfg = app_boot();
require_role('admin', $cfg);

$in = read_body();
$data = $in['data'] ?? null;
if (!is_array($data) || !isset($data['jak']) || !isset($data['settings'])) {
  json_out(['success'=>false,'message'=>'Données invalides'], 422);
}
store_save($cfg, $data);
json_out(['success'=>true]);
```

- [ ] **Step 2: Implémenter `admin.php`** (garde + login OTP admin inline)

```php
<?php
require_once __DIR__.'/api/lib/bootstrap.php';
require_once __DIR__.'/api/lib/auth.php';
$cfg = app_boot();
header('Content-Type: text/html; charset=utf-8');           // écrase le JSON de app_boot()
header('X-Robots-Tag: noindex, nofollow');

if (auth_current($cfg)) {                                     // session admin valide → console
  readfile(__DIR__.'/admin.html');
  exit;
}
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Connexion administration — JAK 2026</title>
<link rel="stylesheet" href="site.css">
</head><body>
<section><div class="wrap" style="max-width:440px;margin-top:12vh">
  <h2 class="sec-label">Espace administrateur</h2>
  <div class="panel">
    <p class="muted" id="adMsg">Recevez un code sur le numéro administrateur pour vous connecter.</p>
    <div id="adStep1">
      <button class="cta" id="adSend" style="width:100%">📲 Recevoir mon code</button>
    </div>
    <div id="adStep2" style="display:none">
      <input id="adCode" inputmode="numeric" maxlength="6" placeholder="Code à 6 chiffres"
        style="width:100%;padding:13px;border-radius:12px;border:1px solid var(--line);background:var(--panel-2);color:var(--ink);font-family:'IBM Plex Mono',monospace;font-size:18px;letter-spacing:.3em;text-align:center">
      <button class="cta" id="adVerify" style="width:100%;margin-top:10px">Valider</button>
    </div>
  </div>
</div></section>
<script>
const msg=t=>document.getElementById('adMsg').textContent=t;
document.getElementById('adSend').addEventListener('click',async e=>{
  e.target.disabled=true;
  const r=await fetch('api/auth/request-otp.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({role:'admin'})}).then(r=>r.json()).catch(()=>({success:false}));
  msg(r.message||'Erreur');
  if(r.success){document.getElementById('adStep1').style.display='none';document.getElementById('adStep2').style.display='';}
  else e.target.disabled=false;
});
document.getElementById('adVerify').addEventListener('click',async ()=>{
  const code=document.getElementById('adCode').value.replace(/\D/g,'');
  const r=await fetch('api/auth/verify-otp.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code})}).then(r=>r.json()).catch(()=>({success:false}));
  if(r.success){location.reload();} else msg(r.message||'Code invalide');
});
</script>
</body></html>
```

- [ ] **Step 3: Lint des deux fichiers**

Run: `C:/php/php.exe -l admin.php && C:/php/php.exe -l api/admin/save-data.php`
Expected: `No syntax errors detected` × 2.

- [ ] **Step 4: Test manuel de la garde**

Serveur : `C:/php/php.exe -S localhost:3000`. Ouvrir `http://localhost:3000/admin.php` sans session → l'écran de connexion s'affiche (pas la console). Confirmer aussi que `http://localhost:3000/admin.html` est **refusé** en production (`.htaccess` `Require all denied` ; en local `php -S` ignore `.htaccess`, donc à revérifier après déploiement).

- [ ] **Step 5: Commit**

```bash
git add admin.php api/admin/save-data.php
git commit -m "feat(auth): garde admin.php (OTP) + endpoint save-data admin"
```

---

### Task 11: `admin.html` — champs `id`/`telephone` + enregistrement en ligne

**Files:**
- Modify: `admin.html` (formulaire membre `personForm` + bouton d'enregistrement)

**Interfaces:**
- Consumes: `api/admin/save-data.php`. Le générateur `personForm(base,i,p,isJak)` existant reçoit déjà `isJak`.

- [ ] **Step 1: Ajouter les champs `id` et `telephone` au formulaire membre**

Dans `admin.html`, dans `personForm(base,i,p,isJak)` (lignes 136-147), à l'intérieur du `<div class="grid2c">`, juste après la ligne du champ « Fondateur » (`${isJak?fld("Fondateur",base+"."+i+".fondateur","select",["OUI","NON"]):''}`), ajouter deux lignes :
```js
    ${isJak?fld("Identifiant (id, unique)",base+"."+i+".id"):''}
    ${isJak?fld("Téléphone (9 chiffres)",base+"."+i+".telephone"):''}
```
> `fld(label,path)` (type texte par défaut) avec chemins en **notation pointée** `base+"."+i+".champ"` — conformes à l'usage du fichier (lignes 83-88, 141-144). Ne pas utiliser de crochets.

- [ ] **Step 2: Ajouter un bouton « Enregistrer en ligne »**

Dans la barre d'outils HTML, à côté du bouton `#btnDraft`, ajouter :
```html
<button id="saveOnline" class="abtn gold">☁ Enregistrer en ligne</button>
```
Puis, **à l'intérieur de l'IIFE**, juste après le gestionnaire `$('#btnExport').addEventListener(...)` (≈ ligne 224), ajouter :
```js
$('#saveOnline').addEventListener('click',async ()=>{
  const r=await fetch('api/admin/save-data.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({data:D})}).then(r=>r.json()).catch(()=>({success:false}));
  msg(r.success?'Enregistré en ligne ✓ — le site public est à jour':'Échec de l’enregistrement en ligne');
});
```
> `D` (objet de données) et `msg()` (afficheur, `admin.html:80`, sélecteur `#savedMsg`) sont dans la portée de l'IIFE. `$` est l'alias de `querySelector` du fichier.

- [ ] **Step 3: Vérifier le rendu du formulaire**

Serveur : `C:/php/php.exe -S localhost:3000`. Se connecter via `admin.php` (OTP admin), puis dans l'onglet Membres, confirmer que chaque membre affiche les champs **Identifiant** et **Téléphone**, et que « ☁ Enregistrer en ligne » est présent.

- [ ] **Step 4: Test manuel d'enregistrement**

Saisir un `telephone` (9 chiffres) pour un membre → « Enregistrer en ligne » → message succès. Vérifier : `grep -o '"telephone":"[0-9]*"' data.json | head` montre le numéro, et `data.js` régénéré le contient aussi.

- [ ] **Step 5: Commit**

```bash
git add admin.html
git commit -m "feat(auth): admin — champs id/telephone + enregistrement en ligne"
```

---

### Task 12: Front `auth.js` — accès membre + éditeur de fiche

**Files:**
- Create: `auth.js`
- Modify: `index.html`, `dignitaires.html`, `jak.html`, `galerie.html` (ajout `<script src="auth.js"></script>` après `site.js`)
- Modify: `site.js` (clés i18n `acces_membre`, `deconnexion`)
- Modify: `site.css` (styles modale auth — réutilise `.panel`/`.cta`/`.lbx`)

**Interfaces:**
- Consumes: `api/session.php`, `api/auth/request-otp.php`, `api/auth/verify-otp.php`, `api/auth/logout.php`, `api/member/save-fiche.php`. Réutilise `window.T`, `esc`, la compression photo canvas (copiée depuis `admin.html`).

- [ ] **Step 1: Ajouter les clés i18n** dans `site.js`

Dans l'objet `I18N`, ajouter à **chaque** langue (au minimum `fr`) les clés :
```js
acces_membre:"Accès membre", deconnexion:"Se déconnecter",
otp_envoi:"Recevez un code par WhatsApp", otp_tel:"Votre téléphone (9 chiffres)",
otp_code:"Code à 6 chiffres", otp_valider:"Valider", enregistrer:"Enregistrer",
ma_fiche:"Ma fiche", saved_ok:"Fiche enregistrée ✓",
```
(pour `en/wo/ff/ar`, fournir la traduction ; à défaut le repli FR s'applique via `T()`.)

- [ ] **Step 2: Créer `auth.js`**

```js
/* Accès membre : connexion OTP WhatsApp + édition de sa fiche. */
(function(){
  'use strict';
  const $=s=>document.querySelector(s);
  const api=(u,b)=>fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})}).then(r=>r.json());

  // Bouton d'accès dans la barre de navigation
  function mountButton(){
    const nav=document.querySelector('nav.site .in'); if(!nav)return;
    const b=document.createElement('button'); b.type='button'; b.className='memberAccess';
    b.textContent='👤 '+T('acces_membre');
    b.addEventListener('click',openLogin);
    nav.appendChild(b);
  }

  function overlay(html){
    const o=document.createElement('div'); o.className='authov';
    o.innerHTML='<div class="authbox panel">'+html+'</div>';
    o.addEventListener('click',e=>{if(e.target===o)o.remove();});
    document.body.appendChild(o); return o;
  }

  function openLogin(){
    const o=overlay(
      '<h3>'+esc(T('acces_membre'))+'</h3>'+
      '<p class="muted" id="aMsg">'+esc(T('otp_envoi'))+'</p>'+
      '<input id="aTel" inputmode="numeric" maxlength="9" placeholder="'+esc(T('otp_tel'))+'" class="authinp">'+
      '<button class="cta" id="aSend" style="width:100%">📲 '+esc(T('otp_valider'))+'</button>'+
      '<div id="aS2" style="display:none;margin-top:10px">'+
        '<input id="aCode" inputmode="numeric" maxlength="6" placeholder="'+esc(T('otp_code'))+'" class="authinp otp">'+
        '<button class="cta" id="aVerify" style="width:100%">'+esc(T('otp_valider'))+'</button></div>');
    const msg=t=>$('#aMsg').textContent=t;
    $('#aSend').addEventListener('click',async e=>{
      const tel=$('#aTel').value.replace(/\D/g,''); if(tel.length!==9){msg(T('otp_tel'));return;}
      e.target.disabled=true;
      const r=await api('api/auth/request-otp.php',{role:'membre',telephone:tel}).catch(()=>({success:false}));
      msg(r.message||''); if(r.success){$('#aS2').style.display='';} else e.target.disabled=false;
    });
    $('#aVerify').addEventListener('click',async ()=>{
      const code=$('#aCode').value.replace(/\D/g,'');
      const r=await api('api/auth/verify-otp.php',{code}).catch(()=>({success:false}));
      if(r.success&&r.member){o.remove();openEditor(r.member);} else msg(r.message||'');
    });
  }

  function openEditor(m){
    const o=overlay(
      '<h3>'+esc(T('ma_fiche'))+'</h3>'+
      '<label class="authlbl">Photo</label>'+
      '<input type="file" id="ePhotoFile" accept="image/*">'+
      '<img id="ePhotoPrev" class="authprev" src="'+esc(m.photo||'')+'" alt="">'+
      '<label class="authlbl">Nom</label><input id="eNom" class="authinp" value="'+esc(m.nom_complet||'')+'">'+
      '<label class="authlbl">Adresse</label><input id="eAdr" class="authinp" value="'+esc(m.adresse||'')+'">'+
      '<label class="authlbl">Téléphone (9 chiffres)</label><input id="eTel" class="authinp" inputmode="numeric" maxlength="9" value="'+esc(m.telephone||'')+'">'+
      '<label class="authlbl">Biographie</label><textarea id="eBio" class="authinp" rows="4">'+esc(m.biographie||'')+'</textarea>'+
      '<p class="muted" id="eMsg"></p>'+
      '<button class="cta" id="eSave" style="width:100%">'+esc(T('enregistrer'))+'</button>'+
      '<button class="cta ghost" id="eOut" style="width:100%;margin-top:8px">'+esc(T('deconnexion'))+'</button>');
    let photoData=m.photo||'';
    $('#ePhotoFile').addEventListener('change',e=>{
      const f=e.target.files[0]; if(!f)return;
      compress(f,700,0.85,d=>{photoData=d;$('#ePhotoPrev').src=d;});
    });
    $('#eSave').addEventListener('click',async ()=>{
      const body={photo:photoData,nom_complet:$('#eNom').value,adresse:$('#eAdr').value,
        telephone:$('#eTel').value.replace(/\D/g,''),biographie:$('#eBio').value};
      const r=await api('api/member/save-fiche.php',body).catch(()=>({success:false}));
      $('#eMsg').textContent=r.success?T('saved_ok'):((r.errors&&r.errors.join(', '))||r.message||'Erreur');
    });
    $('#eOut').addEventListener('click',async ()=>{await api('api/auth/logout.php');o.remove();});
  }

  // Compression photo (reprise de la logique canvas d'admin.html)
  function compress(file,max,q,cb){
    const img=new Image(); const rd=new FileReader();
    rd.onload=()=>{img.onload=()=>{
      let w=img.width,h=img.height; if(w>h&&w>max){h=h*max/w;w=max;} else if(h>max){w=w*max/h;h=max;}
      const c=document.createElement('canvas');c.width=w;c.height=h;
      c.getContext('2d').drawImage(img,0,0,w,h); cb(c.toDataURL('image/jpeg',q));
    };img.src=rd.result;};
    rd.readAsDataURL(file);
  }

  if(document.readyState!=='loading') mountButton();
  else document.addEventListener('DOMContentLoaded',mountButton);
})();
```

- [ ] **Step 3: Ajouter les styles** dans `site.css` (fin de fichier)

```css
/* ===== Accès membre (auth) ===== */
.memberAccess{background:var(--panel-2);color:var(--gold);border:1px solid var(--line);
  border-radius:8px;padding:7px 12px;font-family:'IBM Plex Mono',monospace;font-size:12px;cursor:pointer;min-height:36px}
.authov{position:fixed;inset:0;z-index:130;display:flex;align-items:center;justify-content:center;
  padding:16px;background:rgba(5,12,9,.86);backdrop-filter:blur(8px)}
.authbox{max-width:440px;width:100%;max-height:88dvh;overflow:auto}
.authbox h3{font-family:'Barlow Condensed',sans-serif;text-transform:uppercase;margin:0 0 10px}
.authinp{width:100%;padding:12px;margin:4px 0 10px;border-radius:12px;border:1px solid var(--line);
  background:var(--panel-2);color:var(--ink);font-size:15px}
.authinp.otp{font-family:'IBM Plex Mono',monospace;letter-spacing:.3em;text-align:center;font-size:18px}
.authlbl{display:block;font-family:'IBM Plex Mono',monospace;font-size:10.5px;letter-spacing:.1em;
  text-transform:uppercase;color:var(--muted);margin-top:6px}
.authprev{max-width:120px;border-radius:12px;border:2px solid var(--gold);margin:6px 0}
@media (prefers-reduced-motion: reduce){ .authov{backdrop-filter:none} }
```

- [ ] **Step 4: Inclure `auth.js` sur les 4 pages publiques**

Dans `index.html`, `dignitaires.html`, `jak.html`, `galerie.html`, ajouter après la ligne `<script src="site.js"></script>` :
```html
<script src="auth.js"></script>
```

- [ ] **Step 5: Test manuel bout en bout (membre)**

Serveur : `C:/php/php.exe -S localhost:3000`. Sur `http://localhost:3000/index.html`, cliquer « 👤 Accès membre » → saisir le téléphone d'un membre de test (celui pointant vers `777301221`) → recevoir l'OTP → saisir → l'éditeur s'ouvre prérempli → modifier la bio → Enregistrer → message succès. Recharger la page publique et confirmer que la modification apparaît (via `data.js` régénéré).

- [ ] **Step 6: Commit**

```bash
git add auth.js site.js site.css index.html dignitaires.html jak.html galerie.html
git commit -m "feat(auth): front accès membre (connexion OTP + éditeur de fiche)"
```

---

### Task 13: Non-régression, revue sécurité & déploiement

**Files:**
- Verify: l'ensemble ; Modify si besoin `deploy.ps1`

- [ ] **Step 1: Rejouer toute la suite de tests PHP**

Run:
```bash
for f in api/tests/store_test.php api/tests/validate_test.php api/tests/otp_test.php api/tests/whatsapp_test.php; do C:/php/php.exe "$f" || echo "ÉCHEC $f"; done
```
Expected: chaque fichier finit par `.../... OK` (exit 0), aucun « ÉCHEC ».

- [ ] **Step 2: Non-régression Lighthouse local (SEO/perf inchangés)**

Servir le site (`C:/php/php.exe -S localhost:3000`) et confirmer que les pages publiques se chargent normalement (accueil, galerie), que le carrousel et la lightbox fonctionnent, et que l'ajout d'`auth.js` n'introduit pas d'erreurs console.

- [ ] **Step 3: Revue sécurité (checklist)**

Vérifier et cocher :
- `api/lib/config.php` et `data.json` bien dans `.gitignore` (Run: `git check-ignore api/lib/config.php data.json` → les 2 chemins listés).
- Aucune fuite du numéro admin dans les réponses (`request-otp` admin ne renvoie pas le numéro).
- Réponse anti-énumération identique pour un numéro membre inexistant.
- Cookie de session `HttpOnly` (après déploiement HTTPS : `Secure` présent) — vérifier via `curl -sI` sur l'endpoint verify après login.

- [ ] **Step 4: Confirmer les exclusions de déploiement**

Run: `pwsh -File deploy.ps1 -DryRun` (ou `.\deploy.ps1 -DryRun`) et vérifier que **`data.json` n'apparaît pas** dans la liste, que `api/**`, `admin.php`, `auth.js` et `api/lib/config.php` **apparaissent**, et qu'aucun `*.md` ni `docs/**` n'est listé.

- [ ] **Step 5: Déploiement**

Run: `.\deploy.ps1`
Puis vérifier en ligne :
```bash
curl -s -o /dev/null -w "%{http_code}\n" https://jak.sn/admin.html      # attendu 403 (Require all denied)
curl -s -o /dev/null -w "%{http_code}\n" https://jak.sn/admin.php        # attendu 200 (écran connexion)
curl -s https://jak.sn/api/session.php                                   # {"authenticated":false,...}
```
Expected: `admin.html`→403, `admin.php`→200, `session.php`→JSON non authentifié.

- [ ] **Step 6: Test de bout en bout en production** (admin + un membre réel), puis **retirer le téléphone de test** `777301221` de la fiche membre si utilisé.

- [ ] **Step 7: Commit final**

```bash
git add -A
git commit -m "chore(auth): non-régression, revue sécurité, notes déploiement"
```

---

## Auto-revue du plan (rédacteur)

- **Couverture du spec** : §4 architecture → Tasks 6-12 ; §5 modèle de données → Tasks 2/8 ; §6 flux OTP → Tasks 4/7/10/12 ; §7 contrats API → Tasks 7/9/10 ; §8 WhatsApp → Task 5 ; §9 sécurité → Tasks 1/6/13 ; §10 impacts front → Tasks 10-12 ; §12 tests → Tasks 2-5/13. ✔
- **Placeholders** : le seul bloc « placeholder » est explicitement remplacé à la Task 7 Step 1 (fichier complet fourni juste après). Aucun TODO ailleurs. ✔
- **Cohérence des types** : `store_*`, `member_*`, `otp_*`, `wa_send_otp`, `auth_*`, `validate_member_fields`, `app_boot/json_out/read_body` sont définis avant leur consommation par les endpoints. ✔
- **Dépendances inter-tâches** : Tasks 2→3→4→5→6 (libs) puis 7 (auth), 8 (seed), 9 (membre), 10-11 (admin), 12 (front), 13 (déploiement). ✔
- **Internes `admin.html` vérifiés** (lignes 78-232) : `fld(label,path,type,opts)` en notation pointée, `personForm` en template-literals, `msg()` (`#savedMsg`, l.80), `D` et `$` dans l'IIFE, compression photo 700 px/0,85 (l.174-189) reprise à l'identique dans `auth.js`. La Task 11 est alignée sur ces signatures.
- **Nouveaux membres** : le handler « + Ajouter un membre » (l.195) pousse un objet sans `id`/`telephone` ; l'admin doit renseigner un `id` unique dans le nouveau champ (les champs vides s'affichent, prêts à la saisie).
