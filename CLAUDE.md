# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Présentation

Site événementiel pour « Visite de Cheikh Ould Khaiïry — 16·17·18 Juillet 2026, CICES Dakar », organisé par Jeunesse Al Khaïry (JAK). Front **statique** HTML5 / CSS3 / JavaScript vanilla (ES6+), **aucune dépendance** hors Google Fonts, aucun build/bundler/`package.json`. Une **fine couche PHP** (`api/`) a été ajoutée pour l'authentification OTP (admin + membres) et l'édition de fiches en ligne — voir la section « Authentification & données dynamiques ».

Deux documents de référence font autorité et doivent être lus avant toute évolution :
- `RAPPORT_GENERAL.md` — architecture, modèle de données, charte graphique complète.
- `CONCEPT_NOTE.md` — feuille de route (auth, édition en ligne, upload, paiement) et contraintes non négociables.

## Lancer / prévisualiser

Servir le dossier via HTTP local (ne pas ouvrir en `file://` : les chemins relatifs et le carrousel exigent un serveur). **Toujours le port 3000** ; s'il est occupé, demander une capture d'écran plutôt que de changer de port.

```powershell
python -m http.server 3000    # principal, hors-ligne (Python 3.12 installé) → http://localhost:3000
npx serve -l 3000             # alternative (Node 22 installé ; télécharge le paquet la 1re fois)
```

Aucune suite de tests dans le repo. Le harnais jsdom décrit dans `RAPPORT_GENERAL.md §5` est une **recommandation** (à reproduire pour toute évolution), rien n'est exécutable en l'état.

## Déploiement

Site poussé en **FTP** vers l'hébergement de `jak.sn` par `deploy.ps1` (PowerShell + `curl`, aucune installation).

```powershell
.\deploy.ps1 -DryRun    # liste les fichiers, n'envoie rien (à lancer d'abord)
.\deploy.ps1            # téléverse TOUT
.\deploy.ps1 -Ftps      # tente FTPS chiffré, repli FTP clair si indisponible
```

**Déploiement incrémental** (`deploy_lite.ps1`) — n'envoie que les fichiers nouveaux/modifiés via un manifeste de hachages MD5 local (`.deploy-manifest.json`, gitignoré, non déployé) :

```powershell
.\deploy_lite.ps1 -Baseline   # 1x après un deploy.ps1 complet : marque l'état comme déployé
.\deploy_lite.ps1 -DryRun     # aperçu des fichiers changés
.\deploy_lite.ps1             # envoie UNIQUEMENT les fichiers modifiés
.\deploy_lite.ps1 -Full       # force tout + reconstruit le manifeste
```
Il ajoute/met à jour seulement (ne supprime pas les fichiers distants retirés en local).

- Identifiants lus depuis **`.env`** (format `KEY=VALUE` : `FTP_HOST/PORT/USER/PASS`, `REMOTE_DIR` = sous-dossier distant, ici `/public_html`).
- **`.env` est secret** (mot de passe en clair, FTP non chiffré) : jamais committé/téléversé — exclu par les deux scripts et `.gitignore`.
- Les deux scripts **n'envoient pas** : `.env`, les scripts de deploy, le manifeste, `*.md`, `data.json`, ni `.git/`, `node_modules/`, `.superpowers/`, `docs/`, `api/{tests,tools}/`, `api/state/`. **`api/lib/config.php` EST déployé** (le serveur en a besoin ; il est gitignoré mais présent sur disque).
- **Amorçage 1er déploiement** : `data.json` étant exclu, téléverser **une fois manuellement** le `data.json` seedé (ids stables des membres) dans `/public_html/`, ou laisser l'admin le créer via « Enregistrer en ligne ». Ensuite le serveur en est propriétaire.

## Architecture

Pages statiques indépendantes, toutes pilotées par un unique fichier de données et un noyau JS partagé :

- **`data.js` — SOURCE DE VÉRITÉ.** Expose `window.SITE_DATA` = `{ settings, cheikh, dignitaires[], jak[], galerie[] }`. Tout le contenu du site vit ici. Le schéma exact est tabulé dans `RAPPORT_GENERAL.md §2` (ex. `jak[]` porte `fondateur:"OUI"/"NON"` et `fonction`).
- **`site.js` — noyau partagé** (IIFE, tout exposé sur `window`) : dictionnaire `I18N` (5 langues), `renderChrome(active)` (nav + footer + sélecteur de langue), `startCountdown`, `initCarousel`, `openLightbox`, `soutienBlock`, `personCard`, helpers `esc`/`todoWrap`/`T`, et protections images.
- **Pages** : `index.html` (Le Cheikh), `dignitaires.html`, `jak.html`, `galerie.html`, `Mon_Acces_2026.html` (orientation participant, **autonome** avec son propre `I18N`), `admin.html`.

Chaque page charge `data.js` puis `site.js`, appelle `renderChrome('<section>')`, puis peuple le DOM par `id` depuis `window.SITE_DATA` (voir le script inline en bas d'`index.html` comme patron).

### Flux de données et cycle de publication (sans backend)

1. `admin.html` édite une copie de `SITE_DATA` et écrit un brouillon sous `localStorage['admin_draft']`.
2. **`site.js` lit ce brouillon au chargement de CHAQUE page et écrase `window.SITE_DATA`** s'il existe → toute modif admin est visible immédiatement, **mais uniquement sur le même appareil/navigateur**. Le brouillon **prime** sur `data.js`.
3. Publication = « Exporter data.js » depuis l'admin → remplacer le fichier → re-téléverser le dossier. « Réinitialiser » efface le brouillon.

## Authentification & données dynamiques (couche PHP `api/`)

Ajoutée au-dessus du contrat de données existant. **Le front public reste inchangé** (il charge toujours `data.js`) ; seuls la connexion et l'éditeur de fiche sont dynamiques.

- **Datastore** : `data.json` (canonique, avec `id`+`telephone` des membres) est écrit par PHP. À chaque écriture, PHP **régénère `data.js`** — mais **assaini** (sans `id`/`telephone` : jamais de numéros de téléphone dans l'asset public). `data.json` est **bloqué en HTTP** (403 via `.htaccess`).
- **OTP** : `api/auth/request-otp.php` → génère un code 6 chiffres, le stocke **haché** (HMAC) en session PHP, l'envoie via l'API WhatsApp d'ICELABSOFT (`send_otp`, E.164 `+221`+9 chiffres). `verify-otp.php` ouvre la session (`role=admin|membre`). Anti-énumération : réponse/statut/**timing** identiques membre/non-membre (throttle fichier `api/lib/throttle.php` + leurre + « répondre-puis-envoyer » via `fastcgi_finish_request`).
- **Admin** : `admin.php` sert `admin.html` **seulement** si `role==='admin'` (sinon écran OTP). Numéro admin dans `api/lib/config.php` (jamais exposé). `admin.html` est **interdit en accès direct** (403) — servi uniquement par `admin.php`.
- **Membre** : `auth.js` (chargé sur les 4 pages publiques) ajoute « 👤 Accès membre » → OTP → éditeur de sa fiche (photo/nom/adresse/téléphone/bio) → `api/member/save-fiche.php` (cloisonné : ne modifie que la fiche de son `member_id` de session).
- **Secrets** : `api/lib/config.php` (numéro admin, `otp_secret`, plafonds) et `data.json`, `api/state/` sont **gitignorés** ; jamais committés.
- **Tests** : suites PHP CLI dans `api/tests/*` (`C:\php\php.exe api/tests/<suite>_test.php`, exit 0 = OK). Lint : `C:\php\php.exe -l <fichier>`.
- **Spec & plan** : `docs/superpowers/specs/2026-07-03-auth-otp-admin-membre-design.md` et `docs/superpowers/plans/2026-07-03-auth-otp-admin-membre.md`.

**Politique de cache (`.htaccess`)** : images/polices/`mp4` restent `immutable` (cache long) ; **`data.js`, `site.js`, `auth.js`, `site.css` sont en `no-cache, must-revalidate`** (ils évoluent → doivent se propager sans hard-refresh). Ne jamais remettre un fichier de code applicatif sous la règle `immutable`.

## Conventions à respecter impérativement

- **i18n : aucune chaîne en dur.** Tout texte d'UI passe par `T('cle')` (dictionnaire `I18N` de `site.js`, langues `fr` défaut / `en` / `wo` / `ff` / `ar`). L'arabe déclenche `document.documentElement.dir='rtl'`. Langue persistée dans `localStorage['site_lang']`. Pour tout nouveau composant, utiliser **exclusivement les propriétés CSS logiques** (`margin-inline-*`, `inset-inline-*`, `border-inline-start`) pour rester compatible RTL.
- **Contenu provisoire `[À COMPLÉTER]` / `[À CONFIRMER]`** : passer les valeurs par `todoWrap()`, qui les rend en orange italique (`.todo` / `--copper`). C'est l'indicateur de complétude — le conserver.
- **Échappement** : injecter toute donnée utilisateur via `esc()` (les templates de `site.js` le font déjà).
- **Charte graphique (non négociable, `RAPPORT_GENERAL.md §4`)** : palette en variables CSS du `:root` de `site.css` (fond vert sombre `--bg #0d1b16`, accent or `--gold #d4a83a`, `--copper` pour les todo). Typos Google Fonts : Barlow Condensed (titres/CTA, `uppercase`), Inter (corps), IBM Plex Mono (étiquettes/numéros). Réutiliser les classes existantes (`.panel`, `.cta`/`.btn`, `.sec-label`, `.toast`, lightbox `.lbx`).
- **Mobile-first & accessibilité** : cibles tactiles ≥ 44 px, `100dvh` + `safe-area-inset`, contrastes ≥ 4.5:1, **toute animation désactivée sous `prefers-reduced-motion`**.
- **Compatibilité descendante** : `data.js` doit rester exportable et servir de mode dégradé statique (plan de secours du jour J). Toute API future doit servir un JSON strictement conforme au schéma de `window.SITE_DATA`.

## Sécurité — état actuel

`admin.html` est désormais **protégé par OTP** via `admin.php` (voir la section Authentification) et interdit en accès direct. Le numéro admin et `otp_secret` vivent dans `api/lib/config.php` (gitignoré, jamais exposé au client). Wave/Orange Money = simple copie de numéro, pas de paiement intégré. Les 3 numéros WhatsApp de soutien sont dans `settings.whatsapp` (publics). Reporté à une version ultérieure : migration vers PostgreSQL (`sql_jsonpro` d'ICELABSOFT), voir `docs/superpowers/specs/2026-07-03-schema-postgresql.md`.
