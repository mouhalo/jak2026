# Concept Note — Évolution du site JAK 2026
**Destinataires :** Équipe de développement
**Objet :** Cadrage des prochaines fonctionnalités (auth, édition, upload, paiement) dans la continuité du site existant
**Prérequis de lecture :** `RAPPORT_GENERAL.md` (architecture, modèle de données, charte)

---

## 1. Principe directeur

Le site actuel est statique, rapide, et son identité visuelle est validée (sombre vert `#0d1b16` / or `#d4a83a`, Barlow Condensed + Inter + IBM Plex Mono, 5 langues dont arabe RTL). **L'évolution doit conserver le front-end existant** et introduire un backend léger *derrière* le contrat de données actuel : toute API doit servir un JSON strictement conforme au schéma de `window.SITE_DATA` (settings / cheikh / dignitaires / jak / galerie). Ainsi les pages publiques évoluent sans refonte : remplacer `<script src="data.js">` par un fetch avec repli sur `data.js`.

```js
// cible : chargement des données avec repli statique
fetch('/api/site-data').then(r=>r.json())
  .then(d=>{window.SITE_DATA=d; boot();})
  .catch(()=>boot()); // data.js reste le fallback hors-ligne
```

**Backend recommandé :** API REST légère (Node/Express ou PHP 8 selon l'hébergement mo221.net) + PostgreSQL (des instances existent déjà chez l'organisation) ou SQLite pour démarrer. Alternative low-ops : Supabase (Auth + storage + Postgres managés).

---

## 2. Lot 1 — Authentification Membre / Admin

| | |
|---|---|
| **Objectif** | Protéger `admin.html` et ouvrir un espace membre |
| **Rôles** | `admin` (tout), `editeur` (fiches + galerie, pas les réglages), `membre` (sa propre fiche uniquement) |
| **Implémentation** | JWT httpOnly + refresh, bcrypt/argon2, table `users(id, email, tel, role, membre_id→jak.id, statut)` ; première connexion par lien/OTP envoyé sur WhatsApp (les 3 numéros officiels existent déjà) |
| **UX** | Page `connexion.html` reprenant le style du modal de langues (fond flouté, panneau `--panel` arrondi 20 px, CTA doré, erreurs en `--red`) ; bouton discret « ⚙ » dans le footer pour accéder à l'admin |

Sécurité minimale exigée : rate-limiting sur /login, verrouillage après 5 échecs, HTTPS obligatoire, `admin.html` retiré du dossier public tant que ce lot n'est pas livré.

## 3. Lot 2 — Édition des fiches en ligne

- Reprendre **tel quel** le générateur de formulaires de `admin.html` (fonctions `fld/photoFld/personForm`) en remplaçant brouillon/export par `GET/PUT /api/{cheikh|dignitaires|jak|galerie}`.
- Versionner chaque sauvegarde (table `revisions` : qui, quand, diff JSON) avec restauration — remplace la sécurité qu'offrait l'export manuel.
- Le membre connecté (`role=membre`) accède à un mini-formulaire « Ma fiche » : photo, biographie, adresse (nom et fonction verrouillés, modifiables par admin).
- Conserver la convention `[À COMPLÉTER]` → rendu orange `--copper` : elle sert d'indicateur de complétude ; ajouter un badge « fiche incomplète » dans la liste admin.

## 4. Lot 3 — Upload de photos

- `POST /api/upload` (multipart) → stockage dans `souvenirs/<groupe>/` (respecter la structure existante : `cheikh/`, `membres/`, `dignitaires/`, racine = galerie).
- Pipeline serveur : contrôle MIME/poids (≤ 8 Mo), ré-encodage (strip EXIF), génération de 3 tailles — vignette 320 px, écran 1080 px, original privé. Le carrousel/lightbox consommera `src` (1080) + `thumb` (320) : ajouter le champ `thumb` au schéma galerie (rétro-compatible, repli sur `src`).
- Chantier associé : **optimiser les 45 photos existantes (~59 Mo)** vers WebP ≤ 300 Ko/écran ; gain majeur de performance mobile.
- Conserver la protection front (clic droit/drag bloqués) et ajouter en option un filigrane discret « JAK 2026 » appliqué côté serveur sur les tailles publiques.

## 5. Lot 4 — Paiement (Soutien en ligne)

- **Cible :** transformer le bloc « Soutenir l'événement » (copie de numéro) en paiement intégré, sans casser l'existant qui reste le repli.
- **Agrégateurs recommandés au Sénégal** (Wave + Orange Money en une intégration) : PayDunya, Intouch, Bictorys ou API Wave Business directe — à arbitrer selon frais/délais d'onboarding. L'équipe dispose déjà d'une expérience Intouch (audits existants côté organisation).
- Parcours : montant libre ou paliers (5 000 / 10 000 / 25 000 / … FCFA) → `POST /api/dons` → redirection/QR agrégateur → webhook de confirmation → table `dons(id, montant, canal, ref_operateur, statut, tel_masqué, date)`.
- UX : panneau au style lightbox (cadre doré, fond flouté), toast de remerciement doré, messages traduits dans les 5 langues (clés à ajouter au dictionnaire `I18N`).
- Back-office : onglet « Dons » (total collecté, export Excel — reprendre le format du classeur `Soutien_Visite_Cheikh_2025.xlsx`). **Ne jamais publier de liste nominative sans consentement.**

## 6. Lots complémentaires proposés

1. **PWA / hors-ligne** : manifest + service worker (cache des pages et de `data.js`) — précieux au CICES où le réseau saturera le jour J.
2. **Notifications** : bannière d'annonces pilotée par `settings.annonce` + canal WhatsApp.
3. **Comptes à rebours multiples / programme dynamique** pour les 3 jours (16-17-18).
4. **Statistiques** simples (Plausible/Matomo, RGPD-friendly, sans cookies tiers).
5. **Relecture linguistique** WO / FF / AR par locuteurs natifs (bloquant avant impression des QR codes).

---

## 7. Contraintes non négociables (design & qualité)

1. **Charte** : palette, typos et composants du §4 du Rapport Général. Tout nouvel écran (login, paiement, upload) se construit avec les classes existantes (`.panel`, `.btn/.cta`, `.sec-label`, `.toast`, modales style `.lmbox`/`.lbx`).
2. **i18n** : aucune chaîne en dur — tout passe par `I18N` (5 langues) ; propriétés CSS logiques pour compatibilité **RTL arabe**.
3. **Mobile-first** : cibles tactiles ≥ 44 px, `dvh` + `safe-area-inset`, test systématique ≤ 380 px de large.
4. **Accessibilité & sobriété** : `prefers-reduced-motion` respecté sur toute nouvelle animation ; contrastes ≥ 4.5:1 (l'ivoire `#ede6d6` sur `#0d1b16` y satisfait).
5. **Compatibilité descendante** : `data.js` reste généré/exportable à tout moment (mode dégradé statique = plan de secours du jour J).
6. **Tests** : reproduire le harnais jsdom existant + tests API ; aucune mise en production sans suite verte.

## 8. Ordre de marche suggéré

| Phase | Contenu | Jalon |
|---|---|---|
| S1–S2 | Backend socle + Lot 1 (auth) + retrait d'admin.html public | Admin sécurisé en ligne |
| S3–S4 | Lot 2 (édition) + Lot 3 (upload + optimisation des 45 photos) | Fiches gérées 100 % en ligne |
| S5–S6 | Lot 4 (paiement) en sandbox puis production | Premier don test Wave & OM |
| S7 | PWA, annonces, stats, relectures linguistiques | Gel fonctionnel J-15 (≈ 1er juillet) |

**Rappel calendrier : l'événement est les 16-17-18 juillet 2026 — prévoir un gel fonctionnel à J-15 et un mode statique de secours prêt à re-déployer en 5 minutes.**
