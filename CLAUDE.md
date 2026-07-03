# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Présentation

Site événementiel **100 % statique** pour « Visite de Cheikh Ould Khaiïry — 16·17·18 Juillet 2026, CICES Dakar », organisé par Jeunesse Al Khaïry (JAK). HTML5 / CSS3 / JavaScript vanilla (ES6+), **aucune dépendance** hors Google Fonts. Aucun build, aucun bundler, aucun backend, aucun `package.json`.

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
.\deploy.ps1            # téléverse tout
.\deploy.ps1 -Ftps      # tente FTPS chiffré, repli FTP clair si indisponible
```

- Identifiants lus depuis **`.env`** (format `KEY=VALUE` : `FTP_HOST/PORT/USER/PASS`, `REMOTE_DIR` = sous-dossier distant, vide = racine du compte FTP).
- **`.env` est secret** (mot de passe en clair, FTP non chiffré) : jamais committé/téléversé — déjà exclu par `deploy.ps1` et `.gitignore`.
- Le script **n'envoie pas** les fichiers de dev (`.env`, `deploy.ps1`, `*.md`, `.git/`, `node_modules/`) et crée les sous-dossiers distants au besoin.

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

## Conventions à respecter impérativement

- **i18n : aucune chaîne en dur.** Tout texte d'UI passe par `T('cle')` (dictionnaire `I18N` de `site.js`, langues `fr` défaut / `en` / `wo` / `ff` / `ar`). L'arabe déclenche `document.documentElement.dir='rtl'`. Langue persistée dans `localStorage['site_lang']`. Pour tout nouveau composant, utiliser **exclusivement les propriétés CSS logiques** (`margin-inline-*`, `inset-inline-*`, `border-inline-start`) pour rester compatible RTL.
- **Contenu provisoire `[À COMPLÉTER]` / `[À CONFIRMER]`** : passer les valeurs par `todoWrap()`, qui les rend en orange italique (`.todo` / `--copper`). C'est l'indicateur de complétude — le conserver.
- **Échappement** : injecter toute donnée utilisateur via `esc()` (les templates de `site.js` le font déjà).
- **Charte graphique (non négociable, `RAPPORT_GENERAL.md §4`)** : palette en variables CSS du `:root` de `site.css` (fond vert sombre `--bg #0d1b16`, accent or `--gold #d4a83a`, `--copper` pour les todo). Typos Google Fonts : Barlow Condensed (titres/CTA, `uppercase`), Inter (corps), IBM Plex Mono (étiquettes/numéros). Réutiliser les classes existantes (`.panel`, `.cta`/`.btn`, `.sec-label`, `.toast`, lightbox `.lbx`).
- **Mobile-first & accessibilité** : cibles tactiles ≥ 44 px, `100dvh` + `safe-area-inset`, contrastes ≥ 4.5:1, **toute animation désactivée sous `prefers-reduced-motion`**.
- **Compatibilité descendante** : `data.js` doit rester exportable et servir de mode dégradé statique (plan de secours du jour J). Toute API future doit servir un JSON strictement conforme au schéma de `window.SITE_DATA`.

## Sécurité — état actuel

`admin.html` n'a **aucune authentification** : ne pas le téléverser en production tel quel (voir Lot 1 de `CONCEPT_NOTE.md`). Wave/Orange Money = simple copie de numéro, pas de paiement intégré. Les 3 numéros WhatsApp officiels sont dans `settings.whatsapp`.
