# Design — Sélecteur d'indicatif pays au modal OTP (multi-pays)

**Date :** 2026-07-06
**Branche :** develop
**Statut :** validé (brainstorming) — à implémenter

## 1. Problème

Le modal de connexion membre (OTP WhatsApp) ne saisit qu'un numéro **sénégalais à 9 chiffres**. Les membres viennent de plusieurs pays. Il faut :

1. Ajouter un **sélecteur d'indicatif pays** (drapeau + `+XXX`) au modal de saisie du numéro.
2. **Concaténer indicatif + numéro national** pour l'envoi de l'OTP, car en base les numéros sont stockés concaténés (format E.164, ex. `+221771234567`).

## 2. Contexte technique (existant)

- **Client** `auth.js` :
  - `openLogin()` — un seul `<input>` (9 chiffres) → envoie `{role:'membre', telephone:<9 chiffres>}`.
  - `openEditor(m)` — champ `eTel` (9 chiffres) **éditable en apparence mais ignoré** par le serveur.
- **Serveur** :
  - `request-otp.php` (branche membre) : verrou `strlen($phone9) !== 9`, puis `otp_normalize_phone()`, lookup `member_find_by_phone()`, envoi `wa_send_otp($phone9, …)`.
  - `otp.php` `otp_normalize_phone()` : **gère déjà** deux cas — 9 chiffres → préfixe `default_country_code` (221) ; 10-15 chiffres → `+<digits>` tel quel.
  - `whatsapp.php` `wa_e164($phone9) = '+221'.$phone9` — **+221 codé en dur**.
  - `save-fiche.php` : le téléphone écrit en base vient de la **session** (`$a['telephone']`), le téléphone du body est **ignoré** (cloisonnement double-clé). Le changement de numéro est un flux séparé **hors périmètre**.
  - `member_to_front()` renvoie `telephone` = numéro stocké complet (E.164) à l'éditeur authentifié.
- **Drapeaux** : objet `FLAG` dans `site.js` (SVG inline `viewBox 0 0 6 4`) — déjà `fr`, `sn`, `sa`, `gb`.

## 3. Décisions (validées)

| Sujet | Décision |
|---|---|
| Pays | 8 : Sénégal +221 (**défaut**), Mauritanie +222, Mali +223, Guinée +224, Côte d'Ivoire +225, Gambie +220, Guinée-Bissau +245, France +33 |
| Drapeaux | SVG inline (cohérence + rendu Windows fiable). 6 nouveaux : `mr, ml, gn, ci, gm, gw` |
| Validation | **Longueur nationale exacte par pays** (SN=9, MR=8, ML=8, GN=9, CI=10, GM=7, GW=7, FR=9) |
| Connexion | Sélecteur **éditable** (drapeau + indicatif) + champ national |
| Éditeur | Numéro en **lecture seule** avec drapeau/indicatif dérivés du stocké + note « pour changer, contactez l'admin ». Pas de changement serveur. |
| Anti-énumération | **Préservée à l'identique** (réponse/statut/timing, leurre). Seule la construction du numéro change. |

## 4. Architecture

### 4.1 Données pays (client)

Tableau `COUNTRIES` dans `auth.js` :

```js
const COUNTRIES = [
  {iso:'sn', code:'221', name:'Sénégal',       len:9,  flag:FLAG.sn},
  {iso:'mr', code:'222', name:'Mauritanie',    len:8,  flag:FLAG.mr},
  {iso:'ml', code:'223', name:'Mali',          len:8,  flag:FLAG.ml},
  {iso:'gn', code:'224', name:'Guinée',        len:9,  flag:FLAG.gn},
  {iso:'ci', code:'225', name:"Côte d'Ivoire", len:10, flag:FLAG.ci},
  {iso:'gm', code:'220', name:'Gambie',        len:7,  flag:FLAG.gm},
  {iso:'gw', code:'245', name:'Guinée-Bissau', len:7,  flag:FLAG.gw},
  {iso:'fr', code:'33',  name:'France',        len:9,  flag:FLAG.fr},
];
const DEFAULT_ISO = 'sn';
```

`FLAG` (dans `site.js`) reçoit 6 nouveaux drapeaux SVG (`mr, ml, gn, ci, gm, gw`), même `viewBox="0 0 6 4"` que l'existant. `FLAG` doit être exposé (déjà sur `window` via l'IIFE de `site.js`, à confirmer/exporter au besoin).

### 4.2 Composant `phoneField(defaultIso, {editable})`

Fonction réutilisable dans `auth.js` qui rend une **rangée téléphone** et renvoie un handle.

**Rendu éditable :**
```
[🇸🇳 +221 ▾] [ 77 123 45 67 ]
```
- Bouton gauche `.cc-btn` : drapeau SVG + indicatif + chevron. Au clic → ouvre `.cc-list` (liste de pays, style réutilisant `.langsel`/`.langbtn`, **propriétés logiques** RTL). Sélection → met à jour indicatif, `maxlength`, `placeholder` du champ national, referme.
- Champ national `.cc-num` : `inputmode="numeric"`, `maxlength = pays.len`, filtre non-chiffres à la saisie.
- Cibles tactiles ≥ 44 px ; accessible clavier (Échap ferme, flèches naviguent, focus visible).

**Rendu lecture seule** (`editable:false`, pour l'éditeur) :
- Drapeau + indicatif + numéro national **affichés, non éditables** (pas de `<input>` modifiable), dérivés du numéro stocké (cf. 4.5).

**Handle renvoyé :** `{ el, getCode(), getNational(), getE164Digits(), validate() }`
- `validate()` : `getNational().length === country.len` → `true/false`.
- `getE164Digits()` : `code + national` (chiffres concaténés, sans `+`).

### 4.3 `openLogin()` (connexion — éditable)

- Remplace l'`<input>` unique par `phoneField(DEFAULT_ISO, {editable:true})`.
- Au clic « Valider » :
  1. `if (!pf.validate()) { msg(T('otp_num_invalide')); return; }`
  2. `api('api/auth/request-otp.php', {role:'membre', telephone: pf.getE164Digits()})`.
- Le reste (étape code, verify) inchangé.

### 4.4 `openEditor(m)` (éditeur — lecture seule)

- Remplace le champ `eTel` éditable par `phoneField(<iso dérivé>, {editable:false})` alimenté par `m.telephone`.
- Ajoute une note i18n `T('tel_lecture_seule')`.
- Le body d'enregistrement **ne contient plus** `telephone` (déjà ignoré serveur) → `save-fiche.php` **inchangé**.

### 4.5 Découpage d'un numéro stocké → (pays, national)

Utilitaire client `splitE164(stored)` :
1. `digits = stored.replace(/\D/g,'')` (gère `+221…` comme `221…`).
2. Trouver le `COUNTRIES` dont `digits.startsWith(code)` avec le **code le plus long** qui matche ; `national = digits.slice(code.length)`.
3. Aucun match → afficher le brut (indicatif inconnu), `iso` = fallback affichage neutre.

### 4.6 Serveur — dé-hardcoder `+221`

**`request-otp.php` (branche membre) :**
- Remplacer le verrou `strlen($phone9) !== 9` par :
  ```php
  $raw = preg_replace('/\D/', '', (string)($in['telephone'] ?? ''));
  $phoneIntl = otp_normalize_phone($raw, $cfg);   // gère 9→+221 (legacy) et 10-15→+digits
  if ($phoneIntl === null) { json_out(['success'=>true,'message'=>$GENERIC, …]); }
  ```
- **Throttle** et **envoi** utilisent le numéro complet : clé throttle = `$phoneIntl` (ou ses chiffres) ; `wa_send_otp($phoneIntl, $code, $cfg)`.
- Lookup `member_find_by_phone($cfg, $phoneIntl)` inchangé.
- **Anti-énumération** : structure `respond_then_continue()` + leurre inchangée.

**`whatsapp.php` :**
- `wa_send_otp(string $phoneE164, …)` : le body devient `['telephone'=>$phoneE164, …]` (déjà `+<indicatif><national>`).
- `wa_e164()` : soit supprimée, soit réduite à un no-op de formatage (ne **jamais** préfixer 221). Vérifier ses autres appelants.

**Branche admin (`request-otp.php`) :** construit déjà `$phoneIntl` depuis `default_country_code` → passer `$phoneIntl` à `wa_send_otp` au lieu de `$phone`.

**`otp_normalize_phone()` : inchangée** (déjà correcte). Note : toute concaténation client `code+national` fait ≥ 10 chiffres (min Gambie 220+7=10) → jamais le cas « 9 chiffres → 221 », donc pas de collision.

### 4.7 i18n (5 langues, `site.js`)

Nouvelles clés (fr/en/wo/ff/ar) :
- `otp_indicatif` — label/aria du sélecteur (« Indicatif »).
- `otp_num_invalide` — « Numéro invalide pour ce pays ».
- `tel_lecture_seule` — « Pour modifier votre numéro, contactez l'administrateur. »

Aucune chaîne en dur ; noms de pays affichés depuis `COUNTRIES[].name` (propres, non traduits — acceptable).

## 5. Flux de données

```
[Connexion] pays(sn) + national(771234567)
   → validate len==9 OK
   → telephone = "221771234567"  (concaténé)
   → POST request-otp.php
        normalize → "+221771234567"
        throttle(key=+221771234567)
        member_find_by_phone("+221771234567")
        wa_send_otp("+221771234567", code)   // plus de +221 en dur
   → OTP WhatsApp au bon pays ✓
```

## 6. Gestion d'erreurs

- Longueur nationale ≠ attendue → message i18n, **pas** d'appel réseau.
- `otp_normalize_phone` renvoie `null` (numéro aberrant) → réponse générique (anti-énumération), pas d'envoi.
- Échec DB/WhatsApp → comportement générique existant inchangé.
- Éditeur : numéro stocké non reconnu (indicatif hors liste) → affichage brut, aucune casse.

## 7. Tests (PHP CLI, `api/tests/`)

- `otp_test` : `otp_normalize_phone` sur les 8 indicatifs (concaténés) → E.164 attendu ; legacy 9 chiffres → `+221…` ; entrées aberrantes → `null`.
- `whatsapp_test` : `wa_send_otp` reçoit le **E.164 complet** transmis (mock transport) pour SN, CI, FR ; **jamais** `+221` forcé pour un non-221.
- Branche admin : envoi au numéro admin E.164 inchangé.
- Non-régression : verify-otp, save-fiche inchangés.

## 8. Périmètre / hors-périmètre

**Dans le périmètre :** sélecteur éditable (connexion), affichage lecture seule (éditeur), dé-hardcode serveur, i18n, tests.

**Hors périmètre (différé) :** vrai flux de **changement de numéro** par le membre (nouvel OTP de re-vérification + contrainte d'unicité + re-clé de session) — reste une fonctionnalité séparée.

## 9. Fichiers touchés

- `site.js` — 6 drapeaux SVG dans `FLAG` (+ export si besoin), 3 clés i18n × 5 langues.
- `auth.js` — `COUNTRIES`, `phoneField()`, `splitE164()`, `openLogin()`, `openEditor()`.
- `site.css` — styles `.cc-btn` / `.cc-list` / `.cc-num` (réutilise `.langsel`/`.langbtn`).
- `api/auth/request-otp.php` — validation + numéro complet (membre + admin).
- `api/lib/whatsapp.php` — `wa_send_otp` / `wa_e164` dé-hardcodés.
- `api/tests/otp_test.php`, `api/tests/whatsapp_test.php` — nouveaux cas.
