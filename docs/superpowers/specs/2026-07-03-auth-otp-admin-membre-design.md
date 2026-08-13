# Design — Authentification OTP (Admin & Membres) — JAK 2026

**Date :** 2026-07-03
**Projet :** Site « Visite de Cheikh Ould Khaiïry » (jak.sn) — actuellement statique
**Auteur :** cadrage collaboratif (brainstorming)
**Statut :** approuvé pour rédaction du plan d'implémentation

---

## 1. Objectif

Sécuriser l'accès à la console `admin.html` et permettre à chaque **membre** d'éditer sa propre fiche, via un **code OTP à usage unique** envoyé sur le téléphone, de la manière la plus simple possible.

- **Admin** : reçoit un OTP sur un numéro fixé côté serveur pour déverrouiller `admin.html`.
- **Membre** : clique sur « Accès membre », saisit son téléphone (9 chiffres), reçoit un OTP, puis modifie **sa** fiche : photo, nom, adresse, téléphone (9 chiffres), bio.

## 2. Contraintes et décisions cadrées

1. **Canal OTP : uniquement l'API WhatsApp** d'ICELABSOFT (`send_otp`). Pas de SMS ni e-mail de repli dans cette version.
2. **Pas de base de données** (Postgres/`sql_jsonpro` reporté à une version future). Le **JSON reste le datastore**.
3. **Une fine couche PHP** sur l'hébergement jak.sn (N0C/LiteSpeed exécute déjà PHP) est nécessaire : un OTP généré/vérifié entièrement dans le navigateur est trivialement contournable (le navigateur de l'attaquant connaît le code qu'il a lui-même généré). Le code doit être **généré et vérifié côté serveur**, hors de portée du navigateur.
4. **Conserver le front existant** et le mode statique de secours (`data.js`) comme repli hors-ligne.
5. **HTTPS déjà forcé** (`.htaccess` en place), charte et i18n existantes respectées.

## 3. Périmètre

**Inclus :**
- Endpoints PHP d'authentification OTP (demande, vérification, session, déconnexion).
- Génération/vérification OTP côté serveur (session PHP, code haché) + envoi via WhatsApp.
- Protection de `admin.html` derrière une session admin.
- Écran de connexion + éditeur de fiche **membre** (front) et persistance de sa fiche.
- Persistance des données via `data.json` (canonique) + régénération de `data.js` (repli statique).
- Ajout des champs **`id`** et **`telephone`** à chaque entrée `jak[]`, saisis par l'admin.
- Sauvegarde en ligne depuis `admin.html` (vers `data.json`).

**Exclu (versions ultérieures) :**
- Migration Postgres / `sql_jsonpro` (application dédiée, tables, fonctions PL/pgSQL).
- Versioning des fiches, rôles fins (éditeur), paiement, pipeline d'images.
- SMS/e-mail de repli, UI membre multilingue complète (on réutilise la compression photo existante).

## 4. Architecture

```
Navigateur (pages statiques existantes + auth.js)
   │  requêtes vers  https://jak.sn/api/*   (même origine, cookie session httpOnly)
   ▼
Couche PHP minimale  (jak.sn/api/)
   ├─ auth/request-otp.php   génère OTP (6 chiffres), stocke le HACHÉ en $_SESSION, envoie via WhatsApp
   ├─ auth/verify-otp.php    vérifie le code → ouvre la session (role=admin | membre+id)
   ├─ auth/logout.php        détruit la session
   ├─ session.php            renvoie l'état d'auth courant (pour adapter l'UI)
   ├─ member/save-fiche.php  (session membre) valide et écrit SA fiche dans data.json
   ├─ admin/save-data.php    (session admin) écrit data.json (depuis la console)
   ├─ admin.php              garde d'accès : login OTP admin, sinon inclut admin.html
   └─ lib/  config.php · whatsapp.php · otp.php · store.php · auth.php
   │
   └─ appelle en HTTPS  →  api.icelabsoft.com/whatsapp_service/api/send_otp
Datastore : data.json (canonique, écrit par PHP) + data.js (régénéré = repli statique)
```

**Principe clé :** à chaque écriture, PHP met à jour `data.json` **et** régénère `data.js` (`window.SITE_DATA={…};`). Les pages publiques restent **inchangées** (elles chargent `data.js` en synchrone) — aucun refactor de rendu asynchrone requis.

## 5. Modèle de données

### 5.1 Entrée `jak[]` (ajout de 2 champs)

```json
{
  "id": "m-ablaye-diop",        // identifiant STABLE, unique, saisi/généré par l'admin
  "telephone": "770000000",      // 9 chiffres (SN), saisi par l'admin — sert d'identifiant de connexion
  "photo": "souvenirs/membres/2.png",
  "nom_complet": "…",
  "biographie": "…",
  "adresse": "…",
  "fondateur": "NON",
  "fonction": ""
}
```

- `id` : requis, unique, immuable (le membre ne peut pas le changer).
- `telephone` : 9 chiffres, unique parmi les membres (validé côté serveur).

### 5.2 `data.json`
Copie pure-JSON de `window.SITE_DATA` (settings/cheikh/dignitaires/jak/galerie). **Source de vérité**, écrite par PHP. Générée une fois par **seeding** depuis `data.js` actuel (en ajoutant `id`/`telephone` vides aux membres).

### 5.3 `lib/config.php` (secrets, hors dépôt public via .gitignore)
```php
return [
  'admin_phone'   => 'XXXXXXXXX',   // 9 chiffres (confirmé) → envoi vers +221XXXXXXXXX
  'whatsapp_url'  => 'https://api.icelabsoft.com/whatsapp_service/api/send_otp',
  'otp_secret'    => '<chaîne aléatoire 32+ octets>',  // HMAC des codes/tokens
  'otp_ttl'       => 300,     // 5 min
  'otp_max_try'   => 5,
  'otp_resend'    => 60,      // s entre deux envois
  'session_ttl'   => 7200,    // 2 h
  'data_path'     => __DIR__.'/../../data.json',
  'datajs_path'   => __DIR__.'/../../data.js',
];
```

## 6. Flux OTP

### 6.1 Admin
1. Bouton discret « ⚙ » (footer) → ouvre `admin.php`.
2. `admin.php` : pas de session admin → affiche un formulaire « Recevoir mon code ».
3. `POST auth/request-otp.php {role:"admin"}` → serveur génère un code, le stocke haché en session, envoie via WhatsApp au **`admin_phone` du config** (jamais transmis au client).
4. Saisie du code → `POST auth/verify-otp.php {code}` → session `role=admin` → `admin.php` inclut la console `admin.html`.

### 6.2 Membre
1. Icône **« Accès membre »** (nav/footer) → modale de connexion (style modale langues).
2. Étape 1 : saisie du téléphone (9 chiffres) → `POST auth/request-otp.php {role:"membre", telephone}`.
   - Le serveur cherche la fiche `jak[]` par `telephone`. **Réponse identique** que le numéro existe ou non (anti-énumération), mais n'envoie l'OTP que s'il correspond à une fiche.
3. Étape 2 : saisie du code → `POST auth/verify-otp.php {code}` → session `role=membre`, `member_id`.
4. Ouverture de l'éditeur de fiche (modale) préremplie → modification photo/nom/adresse/téléphone/bio → `POST member/save-fiche.php` → mise à jour de `data.json` (+ régénération `data.js`).

### 6.3 Règles OTP (communes)
- Code **6 chiffres** ; envoyé via `send_otp {telephone:"+221"+num9, code, langue:"fr"}`.
- Stocké **haché** (HMAC-SHA256 + `otp_secret`) en `$_SESSION` avec `{role, member_id?, expire, attempts, target_phone, last_send}`.
- **Expiration 5 min**, **max 5 tentatives** (au-delà : défi invalidé, nouvel envoi requis), **anti-renvoi 60 s**.
- Vérification en temps constant (`hash_equals`). Sur succès : `session_regenerate_id(true)`, pose de `$_SESSION['auth']`.

## 7. Contrats d'API (jak.sn/api/)

Toutes les réponses sont JSON `{success:boolean, message:string, …}`.

| Endpoint | Méthode | Corps | Auth | Réponse OK |
|---|---|---|---|---|
| `auth/request-otp.php` | POST | `{role, telephone?}` | — | `{success, cooldown}` |
| `auth/verify-otp.php` | POST | `{code}` | défi OTP en session | `{success, role, member?}` |
| `auth/logout.php` | POST | — | session | `{success}` |
| `session.php` | GET | — | — | `{authenticated, role?, member_id?}` |
| `member/save-fiche.php` | POST | `{photo,nom,adresse,telephone,bio}` | membre | `{success, member}` |
| `admin/save-data.php` | POST | `{data}` (SITE_DATA complet) | admin | `{success}` |

**Validation serveur `save-fiche` :** `telephone` = exactement 9 chiffres ; unicité parmi les autres membres ; longueurs bornées (nom ≤ 120, adresse ≤ 160, bio ≤ 2000) ; `photo` = data-URL image (recompressée côté client, ≤ ~1,5 Mo) ou chemin existant ; le membre ne modifie **que** l'entrée de son `member_id` de session.

## 8. Intégration WhatsApp (`lib/whatsapp.php`)
- `POST` JSON `{telephone, code, langue:"fr"}` avec timeout 20 s (cURL).
- Téléphone en **E.164 strict** : `+221` + 9 chiffres.
- Réponse `{success, message, message_id?, error_code?}`. Si `success=false` → message générique à l'utilisateur (« Envoi impossible, réessayez ») ; **pas de repli** SMS/e-mail dans cette version. Log serveur (numéro masqué).
- Quota service 250/j : le cooldown 60 s + l'usage réel restent en-deçà.

## 9. Sécurité
- Sessions PHP : `cookie_httponly=1`, `cookie_secure=1`, `cookie_samesite=Lax` ; régénération de l'ID à la connexion.
- OTP et éventuels tokens **stockés hachés** ; jamais renvoyés au client.
- `admin_phone` et `otp_secret` uniquement dans `config.php` (fichier PHP, non servi en clair) ; `config.php` et `data.json` ajoutés au `.gitignore`.
- Anti-énumération des numéros membres (réponse constante).
- Le membre est cloisonné à sa fiche (autorisation par `member_id` de session, pas par un id fourni par le client).
- `admin.html` inaccessible sans session admin (via `admin.php`) — en plus du `X-Robots-Tag: noindex` déjà posé.

## 10. Impacts front & fichiers
- **Nouveau** `api/` (PHP) : endpoints + `lib/`.
- **Nouveau** `auth.js` (chargé sur les pages publiques) : icône « Accès membre », modale de connexion 2 étapes, éditeur de fiche, appel `session.php` pour adapter l'UI. Réutilise la compression photo canvas de `admin.html`.
- **Modifié** `admin.html` : champs `id` + `telephone` dans le formulaire membre ; bouton « Enregistrer en ligne » → `admin/save-data.php` (l'export `data.js` reste comme sauvegarde).
- **Nouveau** `data.json` (seed) ; `data.js` devient un artefact régénéré.
- **Modifié** `deploy.ps1` : **exclure `data.json`** (et tout état serveur) après le seed initial, pour ne pas écraser les modifications faites en ligne ; `config.php` déployé mais non committé.
- **Style** : login/éditeur réutilisent `.panel`, `.cta`, `.toast`, modale style langues/lightbox ; i18n via `I18N` (clés à ajouter) ; propriétés logiques (RTL) ; cibles ≥ 44 px ; `prefers-reduced-motion`.

## 11. Points ouverts / à confirmer
1. **Numéro admin** : confirmé `XXXXXXXXX` (9 chiffres) → OTP envoyé vers `+221XXXXXXXXX`. Renseigné dans `config.php`.
2. **Test local** : PHP **8.3.32 confirmé installé** (`C:\php\php.exe`, extensions `curl/json/session/openssl/mbstring` présentes) → serveur local via `C:\php\php.exe -S localhost:3000`. Les tests bout-en-bout consomment le quota WhatsApp (envois réels) — prévoir un mode test limité.

## 12. Tests
- **Unitaires PHP (logique pure)** : génération/vérification OTP (hash, expiration, tentatives, cooldown), validation téléphone (9 chiffres, unicité), lecture/écriture `data.json` + régénération `data.js` bien formé. Exécutables si PHP est installé.
- **Bout-en-bout (staging jak.sn)** : parcours admin complet, parcours membre complet, cloisonnement (un membre ne peut pas modifier une autre fiche), anti-énumération, expiration/blocage après 5 essais, non-régression des pages publiques (chargement `data.js` régénéré).

## 13. Séquencement suggéré
1. `lib/` (config, otp, store, whatsapp, auth) + seeding `data.json`.
2. Endpoints auth + `session.php` ; test OTP admin bout-en-bout.
3. Garde `admin.php` + champs `id`/`telephone` + `admin/save-data.php`.
4. `auth.js` : accès membre + éditeur + `member/save-fiche.php`.
5. Ajustement `deploy.ps1` (exclusions) + tests de non-régression + revue sécurité.
