# Sélecteur d'indicatif pays au modal OTP — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre à un membre de choisir son indicatif pays (drapeau + `+XXX`) au modal OTP, concaténer indicatif+numéro pour l'envoi, et dé-hardcoder le `+221` serveur.

**Architecture:** Front statique (auth.js/site.js/site.css) + fine couche PHP (`api/`). Le client concatène indicatif+national et envoie un numéro complet ; le serveur normalise déjà les numéros ≥10 chiffres en E.164 — il suffit de relâcher le verrou 9-chiffres et de dé-hardcoder le préfixe d'envoi WhatsApp. L'éditeur affiche le numéro stocké en lecture seule.

**Tech Stack:** JavaScript vanilla ES6+ (IIFE, tout sur `window`), PHP 8 CLI + sql_jsonpro, tests PHP CLI maison (`api/tests/_assert.php` : `ok()`, `eq()`, `done()`).

## Global Constraints

- **i18n : aucune chaîne UI en dur** — tout texte via `T('cle')` (dictionnaire `I18N` de `site.js`, langues fr/en/wo/ff/ar). Arabe → `dir='rtl'`.
- **CSS logique uniquement** pour tout nouveau composant (`margin-inline-*`, `inset-inline-*`, `border-inline-start`) — compat RTL.
- **Échappement** : toute donnée injectée via `esc()`.
- **Mobile-first & a11y** : cibles tactiles ≥ 44 px, contrastes ≥ 4.5:1, animations désactivées sous `prefers-reduced-motion`.
- **Anti-énumération OTP** (réponse/statut/**timing** identiques membre/non-membre + leurre) : **préservée à l'identique** — ne modifier QUE la construction du numéro.
- **Serveur port 3000** pour la prévisualisation (`C:\php\php.exe -S localhost:3000 -t .`). S'il est occupé, demander une capture.
- **Tests PHP** : `C:\php\php.exe api\tests\<suite>_test.php` (exit 0 = OK). Lint : `C:\php\php.exe -l <fichier>`. Numéros de test FACTICES en plage réservée `7000000xx`.
- **Cache** : `site.js`/`auth.js`/`site.css` restent en `no-cache` (ne jamais les remettre en `immutable`).

## File Structure

- `api/lib/whatsapp.php` — **modifié** : `wa_send_otp()` reçoit un E.164 complet ; `wa_e164()` supprimée (ne plus préfixer 221).
- `api/auth/request-otp.php` — **modifié** : branche membre accepte le numéro concaténé (validation via `otp_normalize_phone`) ; throttle + envoi sur le numéro complet ; branche admin passe son E.164.
- `api/tests/whatsapp_test.php` — **modifié** : nouvelle signature + cas multi-pays.
- `api/tests/otp_test.php` — **modifié** : cas de normalisation concaténés multi-indicatifs.
- `site.js` — **modifié** : 6 drapeaux SVG dans `FLAG` + `FLAG` exposé sur `window` ; 3 clés i18n × 5 langues.
- `auth.js` — **modifié** : `COUNTRIES`, `splitE164()`, `phoneReadOnlyHTML()` (exposés sur `window.jakPhone` pour testabilité), `phoneField()`, `openLogin()`, `openEditor()`.
- `site.css` — **modifié** : styles `.cc-btn` / `.cc-list` / `.cc-num` / `.cc-ro` (réutilise `.langsel`/`.langbtn`).

---

### Task 1 : Serveur — dé-hardcoder `+221` (envoi WhatsApp + endpoint OTP)

**Files:**
- Modify: `api/lib/whatsapp.php:2` (supprimer `wa_e164`), `api/lib/whatsapp.php:42-46` (`wa_send_otp`)
- Modify: `api/auth/request-otp.php:43-64` (admin), `api/auth/request-otp.php:66-104` (membre)
- Test: `api/tests/whatsapp_test.php`, `api/tests/otp_test.php`

**Interfaces:**
- Produces: `wa_send_otp(string $phoneE164, string $code, array $cfg, ?callable $transport=null): array` — le body envoyé porte `telephone => $phoneE164` **tel quel** (déjà `+<indicatif><national>`).
- Consumes: `otp_normalize_phone(string, array): ?string` (inchangée — 9 chiffres → `+221…` ; 10-15 → `+<digits>`).

- [ ] **Step 1: Confirmer que `wa_e164` n'a pas d'autre appelant**

Run: `grep -rn "wa_e164" api/ --include=*.php`
Expected: uniquement sa définition (`whatsapp.php:2`) et `whatsapp_test.php`. Si un autre appelant existe → l'adapter dans cette tâche.

- [ ] **Step 2: Réécrire les tests WhatsApp (échec attendu)**

Remplacer dans `api/tests/whatsapp_test.php` le bloc actuel (lignes ~6-16) par :

```php
// wa_send_otp reçoit désormais un E.164 COMPLET et l'envoie tel quel.
$captured = null;
$t = function($url,$body) use (&$captured){ $captured=['url'=>$url,'body'=>$body]; return ['status'=>200,'json'=>['success'=>true,'message'=>'ok','message_id'=>'abc']]; };

$r = wa_send_otp('+221700000000','123456',$cfg,$t);
ok($r['ok']===true, 'succès WhatsApp');
eq($captured['body']['telephone'], '+221700000000', 'E.164 SN transmis tel quel');
eq($captured['body']['code'], '123456', 'code envoyé');
eq($captured['body']['langue'], 'fr', 'langue fr envoyée');

// Multi-pays : jamais de +221 forcé pour un non-221.
wa_send_otp('+2250700000000','123456',$cfg,$t);
eq($captured['body']['telephone'], '+2250700000000', 'E.164 CI transmis (pas de +221)');
wa_send_otp('+33700000000','123456',$cfg,$t);
eq($captured['body']['telephone'], '+33700000000', 'E.164 FR transmis (pas de +221)');
```

- [ ] **Step 3: Lancer les tests → échec attendu**

Run: `C:\php\php.exe api\tests\whatsapp_test.php`
Expected: FAIL (`wa_send_otp('+221700000000',…)` produit aujourd'hui `+221+221700000000` via `wa_e164`).

- [ ] **Step 4: Implémenter la nouvelle signature dans `whatsapp.php`**

Supprimer la ligne 2 (`function wa_e164…`). Dans `wa_send_otp`, remplacer la signature et le body :

```php
function wa_send_otp(string $phoneE164, string $code, array $cfg, ?callable $transport=null): array {
  $transport = $transport ?? fn($url, $body) => _wa_curl($url, $body, $cfg);
  $body = ['telephone'=>$phoneE164, 'code'=>$code, 'langue'=>$cfg['langue'] ?? 'fr'];
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

- [ ] **Step 5: Lancer les tests WhatsApp → succès attendu**

Run: `C:\php\php.exe api\tests\whatsapp_test.php`
Expected: PASS (tous les `ok/eq`, `done()`).

- [ ] **Step 6: Adapter l'endpoint `request-otp.php` — branche membre**

Remplacer les lignes 66-104 (à partir du commentaire « Rôle membre ») par :

```php
// Rôle membre — anti-énumération : réponse, statut ET temps de réponse identiques
// que le numéro corresponde ou non à une fiche.
// Le client concatène indicatif+national (ex. "221771234567") ; on normalise en
// E.164. Legacy : 9 chiffres seuls → +221 (via otp_normalize_phone).
$raw       = preg_replace('/\D/', '', (string)($in['telephone'] ?? ''));
$phoneIntl = otp_normalize_phone($raw, $cfg);
if ($phoneIntl === null) {
  json_out(['success'=>true,'message'=>$GENERIC,'cooldown'=>$cfg['otp_resend']]);
}
// Clé de throttle = chiffres de l'E.164 (numéro complet, pas seulement national).
$throttleKey = preg_replace('/\D/', '', $phoneIntl);
$th = throttle_check_and_touch($throttleKey, $now, $cfg);
if (!$th['allowed']) {
  json_out(['success'=>false,'message'=>'Veuillez patienter avant un nouvel envoi.','cooldown'=>$cfg['otp_resend']], 429);
}

// Lookup membre en base (E.164). Échec DB → silencieux (leurre sans personne_id).
$m = null;
$personneId = null;
try { $m = member_find_by_phone($cfg, $phoneIntl); } catch (Throwable $e) { $m = null; }
$personneId = ($m && isset($m['id'])) ? (int)$m['id'] : null;

$code = otp_generate();
$hash = otp_hash($code, $cfg);
try {
  $otpId = db_call_function('otp_creer', ['membre', $phoneIntl, $personneId, $hash, (int)($cfg['otp_ttl'] ?? 300)], $cfg);
} catch (Throwable $e) {
  error_log('[jak-otp] otp_creer membre échec : '.$e->getMessage());
  $otpId = null;
}
$_SESSION['otp'] = ['otp_id'=>$otpId, 'last_send'=>$now];

respond_then_continue(['success'=>true,'message'=>$GENERIC,'cooldown'=>$cfg['otp_resend']]);
if ($m && $otpId !== null) {
  $res = wa_send_otp($phoneIntl, $code, $cfg);
  if (!$res['ok']) { error_log('[jak-otp] echec envoi WhatsApp membre'); }
}
exit;
```

- [ ] **Step 7: Adapter `request-otp.php` — branche admin (envoi E.164)**

Ligne 61, remplacer `$res = wa_send_otp($phone, $code, $cfg);` par :

```php
  $res = wa_send_otp($phoneIntl, $code, $cfg);
```

(`$phoneIntl` est déjà construit ligne 46 depuis `default_country_code`.)

- [ ] **Step 8: Ajouter des cas de normalisation concaténés multi-indicatifs**

Dans `api/tests/otp_test.php`, après la ligne `eq(otp_normalize_phone('+33600000000', …))`, ajouter :

```php
// Concaténé indicatif+national (ce que le client envoie) → E.164.
eq(otp_normalize_phone('221700000000', $cfg), '+221700000000', 'concat SN → +221…');
eq(otp_normalize_phone('2250700000000', $cfg), '+2250700000000', 'concat CI → +225…');
eq(otp_normalize_phone('2207000000', $cfg), '+2207000000', 'concat GM (10 chiffres) → +220…');
eq(otp_normalize_phone('33700000000', $cfg), '+33700000000', 'concat FR → +33…');
```

- [ ] **Step 9: Lancer les suites + lint endpoint → succès attendu**

Run:
```
C:\php\php.exe api\tests\whatsapp_test.php
C:\php\php.exe api\tests\otp_test.php
C:\php\php.exe -l api\auth\request-otp.php
```
Expected: les deux suites PASS (`done()`), lint « No syntax errors ».

- [ ] **Step 10: Commit**

```bash
git add api/lib/whatsapp.php api/auth/request-otp.php api/tests/whatsapp_test.php api/tests/otp_test.php
git commit -m "feat(otp): serveur multi-pays — dé-hardcode +221 (envoi WhatsApp + endpoint)"
```

---

### Task 2 : `site.js` — drapeaux SVG + exposition `FLAG` + clés i18n

**Files:**
- Modify: `site.js:269-272` (objet `FLAG`), `site.js` fin d'IIFE (exposition `window.FLAG`), `site.js:50/93/136/179/222` zones i18n (ajout de 3 clés × 5 langues)

**Interfaces:**
- Produces: `window.FLAG.{mr,ml,gn,ci,gm,gw}` (chaînes SVG) ; clés i18n `otp_indicatif`, `otp_num_invalide`, `tel_lecture_seule` dans les 5 langues.

- [ ] **Step 1: Ajouter les 6 drapeaux SVG à l'objet `FLAG`**

Dans `site.js`, à la suite des drapeaux existants (après `gb:` ~ligne 272), ajouter (mêmes `viewBox="0 0 6 4"`, approximations lisibles à 20×14) :

```js
  ,mr:'<svg class="flag" viewBox="0 0 6 4" aria-hidden="true"><rect width="6" height="4" fill="#006233"/><rect width="6" height="0.5" fill="#cd2a3e"/><rect y="3.5" width="6" height="0.5" fill="#cd2a3e"/><circle cx="3" cy="2.05" r="0.62" fill="#ffc400"/><circle cx="3.18" cy="2.05" r="0.52" fill="#006233"/></svg>'
  ,ml:'<svg class="flag" viewBox="0 0 6 4" aria-hidden="true"><rect width="2" height="4" fill="#14b53a"/><rect x="2" width="2" height="4" fill="#fcd116"/><rect x="4" width="2" height="4" fill="#ce1126"/></svg>'
  ,gn:'<svg class="flag" viewBox="0 0 6 4" aria-hidden="true"><rect width="2" height="4" fill="#ce1126"/><rect x="2" width="2" height="4" fill="#fcd116"/><rect x="4" width="2" height="4" fill="#009460"/></svg>'
  ,ci:'<svg class="flag" viewBox="0 0 6 4" aria-hidden="true"><rect width="2" height="4" fill="#f77f00"/><rect x="2" width="2" height="4" fill="#fff"/><rect x="4" width="2" height="4" fill="#009e60"/></svg>'
  ,gm:'<svg class="flag" viewBox="0 0 6 4" aria-hidden="true"><rect width="6" height="1.28" fill="#ce1126"/><rect y="1.28" width="6" height="0.16" fill="#fff"/><rect y="1.44" width="6" height="1.12" fill="#0c1c8c"/><rect y="2.56" width="6" height="0.16" fill="#fff"/><rect y="2.72" width="6" height="1.28" fill="#3a7728"/></svg>'
  ,gw:'<svg class="flag" viewBox="0 0 6 4" aria-hidden="true"><rect width="6" height="2" fill="#fcd116"/><rect y="2" width="6" height="2" fill="#009e49"/><rect width="2" height="4" fill="#ce1126"/><path d="M1 1.35l.221.68h.716l-.579.42.221.681L1 2.61l-.579.42.221-.68-.579-.42h.716z" fill="#000"/></svg>'
```

(Si `FLAG` est déclaré en objet littéral fermé, insérer ces entrées **à l'intérieur** des accolades, en respectant les virgules.)

- [ ] **Step 2: Exposer `FLAG` sur `window`**

Vérifier si `FLAG` est déjà exposé. Run: `grep -n "window.FLAG\|window\['FLAG'\]" site.js`
Si absent, ajouter près des autres expositions `window.*` de l'IIFE :

```js
  window.FLAG = FLAG;
```

- [ ] **Step 3: Ajouter les 3 clés i18n dans les 5 langues**

Ajouter dans chaque bloc de langue (juste après `soutiens_recents`/`soutien_anonyme` de chaque langue) :

fr (après ligne ~51) :
```js
   otp_indicatif:"Indicatif",
   otp_num_invalide:"Numéro invalide pour ce pays",
   tel_lecture_seule:"Pour modifier votre numéro, contactez l'administrateur.",
```
en :
```js
   otp_indicatif:"Country code",
   otp_num_invalide:"Invalid number for this country",
   tel_lecture_seule:"To change your number, contact the administrator.",
```
wo :
```js
   otp_indicatif:"Indicatif réew",
   otp_num_invalide:"Numero baaxul ci réew mi",
   tel_lecture_seule:"Ngir soppi sa numero, jokkool ak admin bi.",
```
ff :
```js
   otp_indicatif:"Tonngoode leydi",
   otp_num_invalide:"Limngal moƴƴaani ngal leydi",
   tel_lecture_seule:"Ngam waylude limngal maa, jokkondir e admin on.",
```
ar :
```js
   otp_indicatif:"رمز الاتصال",
   otp_num_invalide:"رقم غير صالح لهذا البلد",
   tel_lecture_seule:"لتغيير رقمك، تواصل مع المسؤول.",
```

(Attention : respecter la virgule de fin de la clé précédente / suivante dans chaque objet.)

- [ ] **Step 4: Vérifier en navigateur (lint + i18n + flags)**

Démarrer le serveur si besoin (`C:\php\php.exe -S localhost:3000 -t .`), charger `http://localhost:3000/dons.html`, puis via la console / evaluate_script :

```js
() => ({
  flags: ['mr','ml','gn','ci','gm','gw'].every(k => typeof window.FLAG[k] === 'string' && window.FLAG[k].includes('<svg')),
  i18n_fr: (window.setLang && setLang('fr'), typeof T === 'function' ? T('otp_num_invalide') : 'no T'),
})
```
Expected: `flags:true`, `i18n_fr:"Numéro invalide pour ce pays"`.

- [ ] **Step 5: Commit**

```bash
git add site.js
git commit -m "feat(otp): drapeaux SVG 6 pays + i18n indicatif (5 langues)"
```

---

### Task 3 : `auth.js` — table `COUNTRIES`, `splitE164`, helpers exposés + CSS

**Files:**
- Modify: `auth.js` (haut de l'IIFE), `site.css` (nouveau bloc styles)

**Interfaces:**
- Consumes: `window.FLAG.*` (Task 2).
- Produces (sur `window.jakPhone`) : `COUNTRIES` (array de 8 `{iso,code,name,len,flag}`), `splitE164(stored:string): {iso,code,national}`, `phoneReadOnlyHTML(stored:string): string`.

- [ ] **Step 1: Déclarer `COUNTRIES` et les helpers en haut de l'IIFE d'`auth.js`**

Après `const $=…` / `const api=…` (vers ligne 5), ajouter :

```js
  const FLAG = window.FLAG || {};
  const COUNTRIES = [
    {iso:'sn', code:'221', name:'Sénégal',       len:9,  flag:FLAG.sn||''},
    {iso:'mr', code:'222', name:'Mauritanie',    len:8,  flag:FLAG.mr||''},
    {iso:'ml', code:'223', name:'Mali',          len:8,  flag:FLAG.ml||''},
    {iso:'gn', code:'224', name:'Guinée',        len:9,  flag:FLAG.gn||''},
    {iso:'ci', code:'225', name:"Côte d'Ivoire", len:10, flag:FLAG.ci||''},
    {iso:'gm', code:'220', name:'Gambie',        len:7,  flag:FLAG.gm||''},
    {iso:'gw', code:'245', name:'Guinée-Bissau', len:7,  flag:FLAG.gw||''},
    {iso:'fr', code:'33',  name:'France',        len:9,  flag:FLAG.fr||''},
  ];
  const DEFAULT_ISO = 'sn';
  const byIso = iso => COUNTRIES.find(c=>c.iso===iso) || COUNTRIES[0];

  // Découpe un numéro stocké (E.164 avec ou sans '+') en pays + national.
  // Choisit le code correspondant le plus LONG (245 avant 22, etc.).
  function splitE164(stored){
    const d = String(stored||'').replace(/\D/g,'');
    let best = null;
    for (const c of COUNTRIES){
      if (d.startsWith(c.code) && (!best || c.code.length>best.code.length)) best = c;
    }
    if (!best) return {iso:'', code:'', national:d};
    return {iso:best.iso, code:best.code, national:d.slice(best.code.length)};
  }
```

- [ ] **Step 2: Ajouter `phoneReadOnlyHTML` (rendu lecture seule éditeur)**

Toujours en haut de l'IIFE, après `splitE164` :

```js
  // Rendu lecture seule : drapeau + indicatif + national, non éditable.
  function phoneReadOnlyHTML(stored){
    const s = splitE164(stored);
    const c = s.iso ? byIso(s.iso) : null;
    const flag = c ? c.flag : '';
    const code = s.code ? ('+'+s.code+' ') : '';
    return '<div class="cc-ro">'+flag+'<span>'+esc(code+s.national)+'</span></div>';
  }
```

- [ ] **Step 3: Exposer les helpers sur `window.jakPhone` (testabilité)**

Avant la fermeture de l'IIFE (`})();`), ajouter :

```js
  window.jakPhone = { COUNTRIES, splitE164, phoneReadOnlyHTML };
```

- [ ] **Step 4: Ajouter les styles CSS**

Dans `site.css`, à la fin du bloc auth (après `.authprev`, ~ligne 296), ajouter (propriétés logiques, cibles ≥ 44 px) :

```css
/* Champ téléphone international (modal OTP) */
.tel-row{display:flex;gap:8px;align-items:stretch;margin:4px 0 10px}
.cc-wrap{position:relative;flex:none}
.cc-btn{display:inline-flex;align-items:center;gap:6px;min-block-size:44px;
  padding:0 10px;background:var(--panel-2);border:1px solid var(--line);
  border-radius:12px;color:var(--ink);font-family:'IBM Plex Mono',monospace;
  font-size:13px;cursor:pointer}
.cc-btn .flag{width:20px;height:14px;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.25)}
.cc-btn .chev{color:var(--muted);font-size:10px}
.cc-num{flex:1;min-inline-size:0;min-block-size:44px}
.cc-list{position:absolute;inset-inline-start:0;inset-block-start:calc(100% + 4px);
  z-index:5;max-height:260px;overflow:auto;background:var(--panel);
  border:1px solid var(--line);border-radius:12px;padding:6px;min-inline-size:220px;
  box-shadow:0 18px 40px -16px rgba(0,0,0,.7)}
.cc-list[hidden]{display:none}
.cc-opt{display:flex;align-items:center;gap:8px;inline-size:100%;min-block-size:44px;
  padding:6px 8px;background:transparent;border:none;border-radius:8px;cursor:pointer;
  color:var(--ink);font-size:14px;text-align:start}
.cc-opt:hover,.cc-opt.on{background:var(--panel-2)}
.cc-opt .flag{width:20px;height:14px;border-radius:2px;flex:none}
.cc-opt .code{margin-inline-start:auto;font-family:'IBM Plex Mono',monospace;
  font-size:12px;color:var(--muted)}
/* Lecture seule (éditeur) */
.cc-ro{display:inline-flex;align-items:center;gap:8px;padding:12px;border-radius:12px;
  border:1px dashed var(--line);background:var(--panel-2);color:var(--ink);
  font-family:'IBM Plex Mono',monospace;font-size:15px}
.cc-ro .flag{width:22px;height:15px;border-radius:2px}
```

- [ ] **Step 5: Vérifier `splitE164` en navigateur**

Recharger `http://localhost:3000/dons.html` (ignoreCache) puis evaluate_script :

```js
() => {
  const j = window.jakPhone;
  return {
    n: j.COUNTRIES.length,
    sn: j.splitE164('+221771234567'),
    ci: j.splitE164('2250701020304'),
    gw: j.splitE164('245701234'),
    ro: j.phoneReadOnlyHTML('+221771234567').includes('+221'),
  };
}
```
Expected: `n:8`, `sn:{iso:'sn',code:'221',national:'771234567'}`, `ci:{iso:'ci',code:'225',national:'0701020304'}`, `gw:{iso:'gw',code:'245',national:'701234'}`, `ro:true`.

- [ ] **Step 6: Commit**

```bash
git add auth.js site.css
git commit -m "feat(otp): table pays + splitE164 + styles champ téléphone international"
```

---

### Task 4 : `auth.js` — composant `phoneField()` + `openLogin()` éditable

**Files:**
- Modify: `auth.js` (nouvelle fonction `phoneField`), `auth.js:23-44` (`openLogin`)

**Interfaces:**
- Consumes: `COUNTRIES`, `byIso`, `DEFAULT_ISO`, `T`, `esc` (Task 3 / site.js).
- Produces: `phoneField(defaultIso)` → `{ el, getCode(), getNational(), getE164Digits(), validate() }`.

- [ ] **Step 1: Écrire `phoneField()` (composant éditable)**

Dans `auth.js`, ajouter une fonction (avant `openLogin`) :

```js
  // Champ téléphone international éditable. Renvoie un handle.
  function phoneField(defaultIso){
    let cur = byIso(defaultIso||DEFAULT_ISO);
    const wrap = document.createElement('div'); wrap.className='tel-row';
    wrap.innerHTML =
      '<div class="cc-wrap">'+
        '<button type="button" class="cc-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="'+esc(T('otp_indicatif'))+'">'+
          '<span class="ccf">'+cur.flag+'</span><span class="ccc">+'+cur.code+'</span><span class="chev">▾</span>'+
        '</button>'+
        '<div class="cc-list" role="listbox" hidden>'+
          COUNTRIES.map(c=>'<button type="button" class="cc-opt" role="option" data-iso="'+c.iso+'">'+
            '<span class="flag">'+c.flag+'</span><span>'+esc(c.name)+'</span><span class="code">+'+c.code+'</span></button>').join('')+
        '</div>'+
      '</div>'+
      '<input class="authinp cc-num" inputmode="numeric" maxlength="'+cur.len+'" '+
        'placeholder="'+esc(T('otp_tel'))+'">';
    const btn=wrap.querySelector('.cc-btn'), list=wrap.querySelector('.cc-list'),
          num=wrap.querySelector('.cc-num');
    const setCur=c=>{ cur=c;
      wrap.querySelector('.ccf').innerHTML=c.flag;
      wrap.querySelector('.ccc').textContent='+'+c.code;
      num.maxLength=c.len; num.value=num.value.slice(0,c.len);
    };
    const close=()=>{ list.hidden=true; btn.setAttribute('aria-expanded','false'); };
    btn.addEventListener('click',()=>{ const open=list.hidden; list.hidden=!open;
      btn.setAttribute('aria-expanded', open?'true':'false'); });
    list.querySelectorAll('.cc-opt').forEach(o=>o.addEventListener('click',()=>{
      setCur(byIso(o.dataset.iso)); close(); num.focus(); }));
    num.addEventListener('input',()=>{ num.value=num.value.replace(/\D/g,'').slice(0,cur.len); });
    document.addEventListener('click',e=>{ if(!wrap.contains(e.target)) close(); });
    wrap.addEventListener('keydown',e=>{ if(e.key==='Escape') close(); });
    return {
      el: wrap,
      getCode: ()=>cur.code,
      getNational: ()=>num.value.replace(/\D/g,''),
      getE164Digits: ()=>cur.code+num.value.replace(/\D/g,''),
      validate: ()=>num.value.replace(/\D/g,'').length===cur.len,
      focus: ()=>num.focus(),
    };
  }
```

- [ ] **Step 2: Câbler `openLogin()` sur `phoneField`**

Dans `openLogin` : retirer l'`<input id="aTel" …>` du template et insérer le composant après création de l'overlay. Remplacer la construction actuelle (lignes ~24-38) par :

```js
  function openLogin(){
    const o=overlay(
      '<h3>'+esc(T('acces_membre'))+'</h3>'+
      '<p class="muted" id="aMsg">'+esc(T('otp_envoi'))+'</p>'+
      '<div id="aTelMount"></div>'+
      '<button class="cta" id="aSend" style="width:100%">📲 '+esc(T('otp_valider'))+'</button>'+
      '<div id="aS2" style="display:none;margin-top:10px">'+
        '<input id="aCode" inputmode="numeric" maxlength="6" placeholder="'+esc(T('otp_code'))+'" class="authinp otp">'+
        '<button class="cta" id="aVerify" style="width:100%">'+esc(T('otp_valider'))+'</button></div>');
    const msg=t=>$('#aMsg').textContent=t;
    const pf=phoneField(DEFAULT_ISO);
    o.querySelector('#aTelMount').appendChild(pf.el);
    $('#aSend').addEventListener('click',async e=>{
      if(!pf.validate()){ msg(T('otp_num_invalide')); return; }
      e.target.disabled=true;
      const r=await api('api/auth/request-otp.php',{role:'membre',telephone:pf.getE164Digits()}).catch(()=>({success:false}));
      msg(r.message||''); if(r.success){$('#aS2').style.display='';} else e.target.disabled=false;
    });
    $('#aVerify').addEventListener('click',async ()=>{
      const code=$('#aCode').value.replace(/\D/g,'');
      const r=await api('api/auth/verify-otp.php',{code}).catch(()=>({success:false}));
      if(r.success&&r.member){o.remove();openEditor(r.member);} else msg(r.message||'');
    });
  }
```

- [ ] **Step 3: Vérifier le modal de connexion en navigateur**

Recharger la page (ignoreCache). Ouvrir la console et déclencher le modal, puis inspecter :

```js
() => {
  document.querySelector('.memberAccess').click();      // ouvre le modal
  const row = document.querySelector('.tel-row');
  const btn = row.querySelector('.cc-btn');
  btn.click();                                          // ouvre la liste
  const opts = row.querySelectorAll('.cc-opt').length;
  row.querySelector('.cc-opt[data-iso="ci"]').click();  // choisit Côte d'Ivoire
  const num = row.querySelector('.cc-num');
  return { opts, code: row.querySelector('.ccc').textContent, maxlen: num.maxLength };
}
```
Expected: `opts:8`, `code:"+225"`, `maxlen:10`.

- [ ] **Step 4: Vérifier validation + payload concaténé**

Avec le modal ouvert (indicatif SN par défaut), evaluate_script :

```js
() => {
  const row=document.querySelector('.tel-row');
  row.querySelector('.cc-opt[data-iso="sn"]').click();
  const num=row.querySelector('.cc-num'); num.value='77123456';   // 8 chiffres (invalide SN=9)
  document.querySelector('#aSend').click();
  const errShort=document.querySelector('#aMsg').textContent;
  num.value='771234567';                                          // 9 chiffres (valide)
  // Intercepter le fetch pour lire le payload sans dépendre du réseau WhatsApp
  const orig=window.fetch; let sent=null;
  window.fetch=(u,o)=>{ if(String(u).includes('request-otp')) sent=JSON.parse(o.body); window.fetch=orig; return Promise.resolve({json:()=>Promise.resolve({success:true,message:'ok'})}); };
  document.querySelector('#aSend').click();
  return { errShort, sentTelephone: sent && sent.telephone };
}
```
Expected: `errShort:"Numéro invalide pour ce pays"`, `sentTelephone:"221771234567"` (indicatif+national concaténés).

- [ ] **Step 5: Commit**

```bash
git add auth.js
git commit -m "feat(otp): sélecteur d'indicatif éditable au modal de connexion membre"
```

---

### Task 5 : `auth.js` — `openEditor()` téléphone en lecture seule

**Files:**
- Modify: `auth.js:46-58` (`openEditor` — champ téléphone), `auth.js:67-69` (body d'enregistrement)

**Interfaces:**
- Consumes: `phoneReadOnlyHTML` (Task 3), `T` (`tel_lecture_seule`).

- [ ] **Step 1: Remplacer le champ `eTel` éditable par un affichage lecture seule**

Dans `openEditor`, remplacer la ligne du label/input Téléphone (ligne ~54) par :

```js
      '<label class="authlbl">'+esc(T('otp_tel'))+'</label>'+
      phoneReadOnlyHTML(m.telephone||'')+
      '<p class="muted" style="font-size:12px;margin-top:3px">'+esc(T('tel_lecture_seule'))+'</p>'+
```

- [ ] **Step 2: Retirer `telephone` du body d'enregistrement (déjà ignoré serveur)**

Dans le handler `#eSave` (lignes ~67-69), retirer la clé `telephone` :

```js
      const body={photo:photoData,nom_complet:$('#eNom').value,
        adresse:$('#eAdr').value,biographie:$('#eBio').value};
```

- [ ] **Step 3: Vérifier le rendu lecture seule en navigateur**

`openEditor` est privé ; on vérifie le **rendu** via le helper exposé (Task 3) + un contrôle DOM ciblé. evaluate_script :

```js
() => {
  const html = window.jakPhone.phoneReadOnlyHTML('+2250701020304');
  const d=document.createElement('div'); d.innerHTML=html;
  const ro=d.querySelector('.cc-ro');
  return { hasFlag: !!ro.querySelector('svg'), text: ro.querySelector('span').textContent };
}
```
Expected: `hasFlag:true`, `text:"+225 0701020304"`.

- [ ] **Step 4: Lint JS (charge sans erreur)**

Recharger `http://localhost:3000/dons.html` (ignoreCache) et lire la console : aucune erreur JS. evaluate_script :
```js
() => ({ authLoaded: typeof window.jakPhone === 'object' })
```
Expected: `authLoaded:true` et pas d'exception au chargement.

- [ ] **Step 5: Commit**

```bash
git add auth.js
git commit -m "feat(otp): éditeur — téléphone en lecture seule avec drapeau/indicatif"
```

---

### Task 6 : Vérification bout-en-bout + suites de tests + déploiement

**Files:** aucun changement de code (vérification).

- [ ] **Step 1: Relancer toutes les suites PHP**

Run:
```
C:\php\php.exe api\tests\otp_test.php
C:\php\php.exe api\tests\whatsapp_test.php
C:\php\php.exe api\tests\store_test.php
```
Expected: `done()`/PASS pour chaque, exit 0. (Si `store_test`/`otp_test` nécessitent la base et qu'elle est injoignable en local, le noter ; sinon PASS.)

- [ ] **Step 2: Parcours navigateur multi-pays**

Sur `http://localhost:3000/dons.html` (ou toute page publique) : ouvrir « 👤 Accès membre », vérifier :
- La rangée téléphone s'affiche (drapeau 🇸🇳 + `+221` + champ), cibles ≥ 44 px.
- Changer d'indicatif met à jour drapeau, `+code`, `maxlength`.
- Un numéro de mauvaise longueur affiche « Numéro invalide pour ce pays » sans appel réseau.
- Basculer en arabe (sélecteur de langue) : le modal passe en RTL sans casse (liste `.cc-list` alignée via `inset-inline-*`).

- [ ] **Step 3: Vérifier l'absence de régression du bouton d'accès + i18n**

Charger `index.html`, `jak.html`, `galerie.html`, `dignitaires.html` : le bouton « Accès membre » se monte, aucune erreur console. Le titre de la page de dons (« Ils nous ont soutenu ») reste correct.

- [ ] **Step 4: Déploiement incrémental**

Run:
```
.\deploy_lite.ps1 -DryRun
.\deploy_lite.ps1
```
Expected DryRun : liste `site.js`, `auth.js`, `site.css`, `api/lib/whatsapp.php`, `api/auth/request-otp.php` (les tests `api/tests/*` **ne sont pas** déployés — exclus). Déploiement : 0 échec.

- [ ] **Step 5: Vérifier la prod**

Run:
```
curl -s "https://jak.sn/auth.js?cb=$(date +%s)" | grep -c "jakPhone"
curl -s "https://jak.sn/site.js?cb=$(date +%s)" | grep -o "otp_num_invalide" | head -1
```
Expected: `jakPhone` présent (≥1), `otp_num_invalide` présent.

---

## Self-Review

**Couverture spec :**
- §4.1 données pays → Task 3 Step 1 ✓ ; §4.1 drapeaux SVG → Task 2 Step 1 ✓
- §4.2 phoneField (éditable + handle) → Task 4 ✓ ; lecture seule → Task 3 Step 2 + Task 5 ✓
- §4.3 openLogin → Task 4 Step 2 ✓ ; §4.4 openEditor → Task 5 ✓ ; §4.5 splitE164 → Task 3 Step 1 ✓
- §4.6 serveur (request-otp membre+admin, wa_send_otp) → Task 1 ✓ ; §4.7 i18n → Task 2 Step 3 ✓
- §7 tests → Task 1 (php) + vérifs navigateur Tasks 3-5 + Task 6 ✓
- §8 hors-périmètre (changement de numéro) → non implémenté (voulu) ✓

**Placeholders :** aucun « TBD/TODO » ; tout code fourni.

**Cohérence des types :** `phoneField` renvoie `{el,getCode,getNational,getE164Digits,validate,focus}` — consommé tel quel dans openLogin (Task 4). `splitE164` renvoie `{iso,code,national}` — consommé par `phoneReadOnlyHTML` (Task 3) et la vérif Task 5. `wa_send_otp(phoneE164,…)` — signature unique utilisée par request-otp membre + admin (Task 1). `window.jakPhone = {COUNTRIES,splitE164,phoneReadOnlyHTML}` cohérent entre Task 3 (définition) et Tasks 4-6 (usage).

**Note d'exécution :** les numéros de test PHP restent en plage factice `7000000xx` ; aucun vrai numéro committé.
