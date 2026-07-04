# Design — Schéma PostgreSQL (remplacement du JSON)

**Date :** 2026-07-03
**Projet :** Site « Visite de Cheikh Ould Khaiïry » (jak.sn)
**Auteur :** cadrage validé
**Statut :** schéma validé, prêt à l'exécution

---

## 1. Objectif

Remplacer la gestion actuelle par fichier JSON (`data.js` / `data.json`) par une base **PostgreSQL** qui devient l'unique source de vérité du contenu (événement, Cheikh, dignitaires, membres, galerie). Une fonction d'agrégation `site_data_json()` reconstitue à la demande le JSON **strictement conforme** au contrat `window.SITE_DATA` que consomme le front existant — aucune modification du rendu des pages publiques.

Ce schéma prépare en outre les lots futurs :
- **Lot 1 (auth OTP)** : `utilisateur`, `otp_defi`, et les champs `slug` + `telephone` sur les membres.
- **Lot 3 (upload)** : `url_thumb` sur la galerie.
- **Lot 2 (versioning)** : table `revision`.
- **Lot 4 (paiement)** : table `don`.

## 2. Mapping JSON actuel → relationnel

| Bloc JSON (`window.SITE_DATA`) | Table | Cardinalité |
|---|---|---|
| `settings` | `evenement` | 1 ligne par édition (singleton courant) |
| `settings.programme[]` | `programme_jour` | 3 lignes (16/17/18 juillet) |
| `settings.whatsapp[]` | `numero_contact` | 3 lignes |
| `cheikh` | `cheikh` | singleton (id = 1) |
| `cheikh.citations[]` | `cheikh_citation` | N lignes |
| `dignitaires[]` | `personne` (categorie='dignitaire') | 20 lignes |
| `jak[]` | `personne` (categorie='membre') | 16 lignes |
| `galerie[]` | `galerie_photo` | 45 lignes |

### Décisions clés

1. **Table `personne` unifiée** pour dignitaires et membres : champs cœur communs (`nom_complet`, `url_photo`, `biographie`, `adresse`) + discriminateur `categorie`. Les champs spécifiques membres (`fondateur`, `fonction`, `slug`, `telephone`) sont `NULL` pour les dignitaires. → une seule API CRUD, extension future à un seul endroit.
2. **`url_photo` partout** (chemin relatif type `souvenirs/membres/2.png`). **Jamais de base64 en base.** Le seed décode les ~14 photos base64 actuelles vers des fichiers sur disque et stocke le chemin. Résout le problème du fichier `data.js` de 1,5 Mo.
3. **`slug` + `telephone` (uniques)** sur les membres : `slug` = identifiant stable immuable, `telephone` = identifiant de connexion OTP du Lot 1.
4. **Scoping multi-annuel** via `evenement_id` (défaut = 1) : l'organisation reconduit l'événement chaque année (classeur 2025 existe). Prépare la réutilisation sans casser v1.
5. **`fondateur` en `boolean`** en base, mais `site_data_json()` le rend en `"OUI"/"NON"` pour respecter strictement le contrat front (compatibilité descendante).
6. **`id` numérique (`bigserial`)** interne (clés étrangères, jointures) ; **`slug`** textuel exposé dans le JSON `jak[].id` pour la persistance membre.

## 3. Arborescence des fichiers livrés

```
jak2026/
├── db/
│   ├── schema.sql        DDL complet (types, tables, index, vues, fonctions, triggers)
│   ├── seed.mjs          Script Node (ESM) : base64 → fichiers + INSERT SQL
│   ├── seed.sql          (généré par seed.mjs) INSERT prêts à exécuter
│   └── README.md         Mode d'emploi d'exécution
└── docs/superpowers/specs/2026-07-03-schema-postgresql.md   (ce document)
```

## 4. Tables — résumé

| Table | Rôle | Clé |
|---|---|---|
| `evenement` | Réglages généraux (singleton courant) | `id` |
| `programme_jour` | Jours du programme | FK `evenement` |
| `numero_contact` | Numéros officiels (Wave/OM/WA) | FK `evenement` |
| `cheikh` | Fiche du Cheikh (singleton) | `id=1` |
| `cheikh_citation` | Citations / enseignements | FK `cheikh` |
| `personne` | Dignitaires + membres unifiés | `id` ; `slug`/`telephone` uniques |
| `galerie_photo` | Photos de galerie | FK `evenement` |
| `utilisateur` | Comptes (admin/editeur/membre) — Lot 1 | FK optionnelle `personne` |
| `otp_defi` | Défis OTP (audit, multi-instance) — Lot 1 | — |
| `revision` | Versioning des fiches — Lot 2 | — |
| `don` | Dons / paiements — Lot 4 | — |

## 5. Fonctions fournies (`db/schema.sql`)

### 5.1 Agrégation (le pont vers `window.SITE_DATA`)

```sql
site_data_json(p_evenement_id smallint DEFAULT 1) → jsonb   -- STABLE
```

Restitue exactement `{ settings, cheikh, dignitaires[], jak[], galerie[] }` au format actuel. L'API `/api/site-data` ne fait qu'appeler `SELECT site_data_json();`.

### 5.2 CRUD `personne` (patron reproduit pour les autres entités)

| Fonction | Action | Notes |
|---|---|---|
| `personne_creer(...)` | CREATE | Valide `slug` requis pour membre, `telephone` 9 chiffres ; `ordre` auto |
| `personne_lire(p_id)` | READ | `STABLE` |
| `personne_par_telephone(p_tel)` | READ | utilisé par l'auth membre |
| `personne_lister(p_cat?, p_evt?)` | READ (liste) | trié `fondateur DESC, ordre` |
| `personne_modifier(p_id, ...)` | UPDATE partiel | via `COALESCE` (NULL = inchangé) |
| `personne_supprimer(p_id)` | DELETE | retourne `boolean` |
| `membre_sauver_fiche(p_tel, ...)` | UPDATE cloisonné | ne touche QUE la fiche du numéro — Lot 1 |

### 5.3 CRUD `galerie_photo`

| Fonction | Action |
|---|---|
| `galerie_creer(p_url_photo, p_legende?, p_url_thumb?)` | CREATE |
| `galerie_lire(p_id)` | READ |
| `galerie_lister(p_evt?)` | READ (liste) |
| `galerie_modifier(p_id, p_url_photo?, p_legende?, p_url_thumb?, p_ordre?)` | UPDATE partiel |
| `galerie_supprimer(p_id)` | DELETE |

### 5.4 CRUD `cheikh` / `cheikh_citation`

| Fonction | Action |
|---|---|
| `cheikh_lire()` | READ singleton |
| `cheikh_modifier(p_nom?, p_url_photo?, p_bio?, p_adresse?)` | UPDATE partiel |
| `cheikh_citation_ajouter(p_texte)` | CREATE |
| `cheikh_citation_modifier(p_id, p_texte)` | UPDATE |
| `cheikh_citation_supprimer(p_id)` | DELETE |

### 5.5 CRUD `evenement` / `programme_jour` / `numero_contact`

| Fonction | Action |
|---|---|
| `evenement_lire(p_id?)` | READ |
| `evenement_lire_courant()` | READ (est_courant=true) |
| `evenement_modifier(p_id, ...)` | UPDATE partiel |
| `programme_jour_creer / _modifier / _supprimer` | CRUD programme |
| `numero_contact_creer / _modifier / _supprimer` | CRUD numéros |

### 5.6 Triggers

- `touch_modifie_le()` : met à jour `modifie_le = now()` sur `UPDATE` de `evenement`, `cheikh`, `personne`, `galerie_photo`.

## 6. Vues utilitaires

```sql
v_dignitaire          -- dignitaires triés par ordre
v_membre              -- membres triés (fondateurs d'abord)
v_membre_fondateur    -- fondateurs seuls
v_site_data           -- SELECT site_data_json() AS donnees
```

## 7. Seed — stratégie (`db/seed.mjs`)

Le script Node (ESM, dépendance **zéro**) :

1. **Lit** `data.js` (strip du préfixe `window.SITE_DATA=`).
2. **Décode les photos base64** → fichiers dans `souvenirs/<groupe>/` :
   - Dignitaires : `souvenirs/dignitaires/seed/dig-<i>.jpg`
   - Membres : `souvenirs/membres/seed/mb-<i>.jpg`
   - (Cheikh et galerie sont déjà en chemins : rien à décoder.)
3. **Génère `slug` + `telephone` vides** pour les membres (à remplir par l'admin via Lot 1).
4. **Génère deux fichiers** :
   - les fichiers images décodés ;
   - `db/seed.sql` : `TRUNCATE` + `INSERT` complets, prêt à `psql -f`.
5. **Idempotent** : `--reset` pour forcer l'écrasement des images déjà écrites.

### État réel du `data.js` analysé (2026-07-03)

| Bloc | Entrées | Photos base64 | Photos chemin | Vide |
|---|---|---|---|---|
| `cheikh` | 1 | 0 | 1 (chemin) | — |
| `dignitaires` | 20 | 20 (6 placeholders + 14 réels) | 0 | — |
| `jak` | 16 | 4 | 11 chemins | 1 entrée vide (index 15) |
| `galerie` | 45 | 0 | 45 | 0 |

→ ~24 photos base64 à décoder vers des fichiers. Gain attendu : `data.js` de **1,5 Mo → ~5–8 Ko**.

## 8. Contrats respectés (compatibilité descendante)

- `site_data_json()` produit une structure **identique** au `window.SITE_DATA` actuel → les pages publiques (`index.html`, `dignitaires.html`, `jak.html`, `galerie.html`) **ne changent pas**.
- `data.js` reste le **mode dégradé statique** de secours (régénéré depuis la base à tout moment : `SELECT site_data_json()` → écriture du fichier).
- Convention `[À COMPLÉTER]` conservée : ces textes sont stockés tels quels et toujours rendus en orange via `todoWrap()` côté front.

## 9. Plan d'exécution

1. Créer la base : `createdb jak2026` (ou réutiliser une instance existante de l'organisation).
2. Appliquer le schéma : `psql -d jak2026 -f db/schema.sql`.
3. Lancer le seed : `node db/seed.mjs` → génère `db/seed.sql` + décode les images.
4. Charger les données : `psql -d jak2026 -f db/seed.sql`.
5. **Vérifier** : `SELECT jsonb_pretty(site_data_json());` doit reproduire la structure du `data.js` nettoyé.
6. (Futur) Brancher l'API : `GET /api/site-data` → `SELECT site_data_json();`.
