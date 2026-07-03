# Rapport Général — Site JAK 2026
**Projet :** Site événementiel « Visite de Cheikh Ould Khaiïry — 16·17·18 Juillet 2026, CICES Dakar »
**Organisateur :** Jeunesse Al Khaïry (JAK)
**Version :** 1.0 — Juillet 2026
**Stack :** Site 100 % statique — HTML5 / CSS3 / JavaScript vanilla (ES6+), aucune dépendance externe hors Google Fonts.

---

## 1. Vue d'ensemble

Le site est un ensemble de pages statiques pilotées par un **fichier de données unique (`data.js`)**, modifiable via une **page d'administration sans backend** (`admin.html`). Il est déployable tel quel sur n'importe quel hébergement statique (Apache/Nginx, Netlify, GitHub Pages…) : il suffit de téléverser le dossier `jak2026/` complet.

### Arborescence

```
jak2026/
├── index.html              Page d'accueil — Le Cheikh (hero, bio, compte à rebours, programme, carrousel, soutien)
├── dignitaires.html        Page Dignitaires (carrousel groupe + fiches + protocole)
├── jak.html                Page Jeunesse Al Khaïry (présentation, membres, fondateurs, soutien)
├── galerie.html            Galerie (carrousel plein format + grille complète)
├── Mon_Acces_2026.html     Orientation participant (autonome : splash, 5 langues, parcours animé par carte d'accès)
├── admin.html              Administration (onglets, brouillon localStorage, export data.js)
├── data.js                 ⭐ SOURCE DE VÉRITÉ — tout le contenu du site
├── site.js                 Noyau partagé : i18n (5 langues), nav/footer, carrousel, lightbox, contacts, protections
├── site.css                Thème global + composants (voir §4)
├── logo.png / image_cheikh.png
├── icone/                  wave.png · om.png · whatsapp.png
└── souvenirs/
    ├── cheikh/             Photos du Cheikh
    ├── membres/            Photos des membres JAK (11 photos en place)
    ├── dignitaires/        Photos des dignitaires
    └── image*.jpg          45 photos « galerie » (racine du dossier)
```

---

## 2. Modèle de données (`data.js`)

`data.js` expose `window.SITE_DATA` :

| Clé | Contenu |
|---|---|
| `settings` | event_nom, organisateur, lieu, dates_texte, date_compte_rebours (ISO), whatsapp[3] (chaque numéro = compte Wave + OM + WhatsApp), note_soutien, jak_presentation, programme[3] {jour, titre, desc} |
| `cheikh` | photo, nom_complet, biographie, adresse, citations[] |
| `dignitaires[]` | photo, nom_complet, biographie, adresse |
| `jak[]` | photo, nom_complet, biographie, adresse, **fondateur** ("OUI"/"NON"), **fonction** |
| `galerie[]` | src, legende |

Conventions : les textes contenant `[À COMPLÉTER]` ou `[À CONFIRMER]` sont automatiquement affichés **en orange italique** sur le site (fonction `todoWrap()`), signalant un contenu provisoire. Les photos sont soit un chemin relatif (`souvenirs/membres/2.png`), soit un data-URL base64 (import via l'admin, compressé à 700 px / JPEG 85 %).

### Cycle de publication actuel (sans backend)

1. **Brouillon local** : `admin.html` → « 💾 Enregistrer le brouillon » écrit `admin_draft` dans `localStorage`. `site.js` lit ce brouillon **au chargement de chaque page** et remplace `SITE_DATA` : la modification est visible immédiatement *sur le même appareil/navigateur*.
2. **Publication** : « ⬇ Exporter data.js » télécharge un `data.js` régénéré (JSON strict) → remplacer le fichier du dossier → re-téléverser.

⚠️ Point d'attention pour l'équipe : le brouillon localStorage **prime** sur `data.js`. Le bouton « Réinitialiser » de l'admin efface le brouillon.

---

## 3. Fonctionnalités livrées

- **Internationalisation 5 langues** : FR (défaut), Wolof, Pulaar, English, **العربية avec bascule RTL automatique** (`document.documentElement.dir`). Dictionnaire `I18N` dans `site.js` (UI) et dans `Mon_Acces_2026.html` (autonome, contenus des 6 parcours inclus). Choix persistant (`localStorage: site_lang` / `monacces_lang`). *Les traductions WO/FF/AR doivent être relues par des locuteurs natifs.*
- **Carrousel partagé** `initCarousel(root, items, opts)` : coverflow (centre net, voisines assombries), autoplay 3,6–4 s, flèches, points, balayage tactile, pause au survol. Instancié sur : accueil (12 photos), galerie (45), dignitaires et jak (photos du groupe — masqué si aucune).
- **Lightbox plein écran** `openLightbox(items, index)` : fond flouté, entrée élastique, **cadre conique doré tournant** (halo animé), 2 anneaux pulsants, 4 points scintillants aux coins, navigation flèches/clavier/balayage, fermeture ✕/Échap/fond. **Mode « riche »** : si l'élément porte bio/fonction/adresse/fondateur, une fiche s'affiche sous la photo (badge ★ fondateur, nom, fonction dorée, bio, 📍 adresse). Déclenchée par : slides de carrousel, vignettes de grille, photos des cartes personnes.
- **Bloc Soutien/Contact** : 3 numéros × 3 canaux — Wave et OM (clic = copie du numéro + toast doré traduit), WhatsApp (lien `wa.me/221…`). Icônes dans `icone/`.
- **Compte à rebours** vers le 16/07/2026 09:00, unités traduites.
- **Protection des images** : clic droit bloqué sur les images uniquement, drag désactivé, `user-select`/`touch-callout` neutralisés. *Dissuasif, non absolu (captures d'écran possibles).*
- **Admin** : 5 onglets (Réglages / Cheikh / Dignitaires / Membres JAK / Galerie), champs conformes au modèle, ajout/suppression d'entrées, **import photo avec compression canvas** + indication du dossier cible par groupe, brouillon, export.
- **Mon Accès** : splash screen photo du Cheikh, modal 5 langues, avatar « VOUS » animé porte → contrôle → place pour chacune des 6 cartes d'accès, étapes synchronisées, consignes par carte.

---

## 4. Charte graphique & UX (à respecter impérativement)

### Palette (variables CSS — `:root` de `site.css`)

| Variable | Hex | Usage |
|---|---|---|
| `--bg` | `#0d1b16` | Fond général (vert très sombre) |
| `--panel` | `#142a22` | Cartes, panneaux |
| `--panel-2` | `#183227` | Surfaces secondaires, boutons |
| `--line` | `#2b463b` | Bordures |
| `--ink` | `#ede6d6` | Texte principal (ivoire) |
| `--muted` | `#9db3a6` | Texte secondaire |
| `--gold` | `#d4a83a` | ⭐ Accent principal : titres de section, CTA, actifs, halo lightbox |
| `--copper` | `#c77b4e` | Accent secondaire (rappel du logo JAK), textes `[À COMPLÉTER]` |
| `--green` | `#3fa06e` | Succès, validation |
| `--red` | `#d0463d` | Danger, cordon sécurité, fermeture |
| `--orange` `--blue` `--grey` | `#e0913c` `#5a92cf` `#8d9c94` | Codes des cartes d'accès (Orange/Bleue/Public) |

Le fond utilise systématiquement : `radial-gradient(1000px 520px at 50% -10%, #17342a 0%, transparent 60%), var(--bg)`.

### Typographies (Google Fonts)

- **Barlow Condensed 600/700** — titres, boutons CTA (toujours en `uppercase`, letter-spacing ≈ .03–.06em)
- **Inter 400/500/600** — corps de texte
- **IBM Plex Mono 500/600** — étiquettes techniques, numéros, horaires, eyebrows (letter-spacing large .1–.22em, uppercase)

### Signature UX

- Ambiance **sombre, solennelle et dorée** ; lumière = or (`--gold`) réservée aux éléments importants.
- **Eyebrow** mono doré au-dessus de chaque titre H1.
- Titres de section précédés d'un **tiret doré** (`.sec-label::before`, 26×2 px).
- Rayons : 14–20 px ; ombres profondes `0 24px 60px -18px rgba(0,0,0,.65)` ; cadres photo blancs cassés (`#fbf9f3`, 6 px) dans les carrousels.
- Animations : cubic-bezier « élastique » `(.2,.8,.3,1)`, pulsations douces, jamais agressives ; **toutes désactivées sous `prefers-reduced-motion`**.
- Mobile-first : `max-width` 640–1120 px selon page, gros boutons tactiles (min 44 px), `100dvh` + `safe-area-inset` pour la lightbox.
- RTL : utiliser exclusivement les propriétés logiques (`margin-inline-*`, `inset-inline-*`, `border-inline-start`) pour tout nouveau composant.

---

## 5. Qualité & tests

Chaque fonctionnalité a été validée par des suites de tests automatisées (jsdom/Node) : rendu des pages, i18n/RTL, compteurs, carrousels (11/12/45 slides), lightbox (ouverture, navigation, mode riche, fermeture clavier), admin (édition, ajout/suppression, brouillon, export JSON valide), protections images. Reproduire ce harnais est recommandé pour toute évolution (servir le dossier via HTTP local, `JSDOM.fromURL`, `runScripts:'dangerously'`).

## 6. Limites connues (points d'entrée pour l'équipe)

1. **Pas de backend** : publication manuelle du `data.js` ; brouillon limité à un appareil.
2. **Pas d'authentification** : `admin.html` est accessible à quiconque a l'URL — **ne pas le téléverser en production** en l'état, ou le protéger (voir Concept Note).
3. Upload photo = base64 dans `data.js` (fichier grossit vite) ou dépôt manuel dans `souvenirs/<groupe>/`.
4. Wave/OM = simple copie du numéro, pas de paiement intégré.
5. Galerie non paginée (45 images ≈ 59 Mo non optimisées — prévoir compression/vignettes).
6. Traductions WO/FF/AR à faire relire.

→ La feuille de route détaillée est dans **CONCEPT_NOTE.md**.
