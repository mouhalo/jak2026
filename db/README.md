# Base de données — JAK 2026 (PostgreSQL)

Migration du contenu du site (précédemment géré par `data.js` / `data.json`) vers **PostgreSQL**. La base devient l'unique source de vérité ; la fonction `site_data_json()` reconstitue à la demande le JSON que consomme le front (`window.SITE_DATA`).

> Spécification complète : `docs/superpowers/specs/2026-07-03-schema-postgresql.md`

## Fichiers

| Fichier | Rôle |
|---|---|
| `schema.sql` | DDL complet : types, 11 tables, index, vues, fonctions CRUD, trigger, fonction d'agrégation `site_data_json()` |
| `seed.mjs` | Script Node (ESM, **zéro dépendance**) — lit `data.js`, décode les photos base64 vers des fichiers, génère `seed.sql` |
| `seed.sql` | INSERT prêts à charger (**généré** par `seed.mjs` — ne pas éditer à la main) |

## Exécution rapide

Prérequis : PostgreSQL ≥ 13, Node ≥ 18.

```bash
# 1. Créer la base
createdb jak2026

# 2. Appliquer le schéma (depuis le dossier jak2026/)
psql -d jak2026 -f db/schema.sql

# 3. Générer le seed (décodage des photos base64 + écriture de seed.sql)
node db/seed.mjs

# 4. Charger les données
psql -d jak2026 -f db/seed.sql

# 5. Vérifier : doit reproduire la structure de data.js
psql -d jak2026 -c "SELECT jsonb_pretty(site_data_json());"
```

## Options de `seed.mjs`

| Option | Effet |
|---|---|
| `--reset` | Écrase les images déjà décodées (sinon : idempotent, n'écrase rien) |
| `--no-images` | Génère seulement `seed.sql` sans décoder/écrire les images |

## Tables (résumé)

```
evenement (singleton courant) ─┬─ programme_jour
                               ├─ numero_contact
                               └─ personne (dignitaire | membre) ── utilisateur (Lot 1)
cheikh (singleton) ─── cheikh_citation
galerie_photo
otp_defi (Lot 1) · revision (Lot 2) · don (Lot 4)
```

## Décisions de conception

1. **`personne` unifiée** (dignitaires + membres, discriminateur `categorie`).
2. **`url_photo` partout** (chemin relatif, jamais de base64 en base) → `data.js` passe de 1,5 Mo à ~5 Ko.
3. **`slug` + `telephone` uniques** sur les membres (login OTP du Lot 1).
4. **`evenement_id`** (défaut 1) pour le multi-annuel (2025, 2026…).
5. **`fondateur`** stocké `boolean`, rendu `"OUI"/"NON"` par `site_data_json()` (compat front).

## Après le seed

- Les **slugs** (`m-<nom>`) et **téléphones** des membres sont à renseigner par l'admin (via les fonctions CRUD `personne_modifier` ou l'écran admin à venir) — base pour l'auth OTP.
- Les entrées vides de `data.js` sont ignorées.
- `data.js` reste le **mode dégradé statique** de secours, régénérable à tout moment depuis la base.
