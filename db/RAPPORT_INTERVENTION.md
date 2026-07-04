# Rapport d'intervention DBA — Base `jaksn_db`

**Date :** 2026-07-03
**Intervenant :** Agent DBA Master (PostgreSQL)
**Serveur :** Master `localhost:3253` (PostgreSQL 16.13)
**Base :** `jaksn_db` — propriétaire `admin_icelab`, encodage UTF8
**Projet :** JAK 2026 — Jeunesse Al Khaïry, visite du Cheikh Ould Khaiïry au CICES (Dakar), 16-18 juillet 2026

---

## 1. Objet de l'intervention

Explorer le dossier `/home/Besoins_DBA/jak2017/db`, appliquer les scripts SQL à la base `jaksn_db`,
tester leur bon fonctionnement, puis corriger les bugs identifiés dans les fichiers source.

---

## 2. Contenu du dossier analysé

| Fichier | Type | Rôle |
|---|---|---|
| `README.md` | Documentation | Migration de `data.js` → PostgreSQL. La base devient la source de vérité unique ; `site_data_json()` reconstitue le JSON pour le front. |
| `schema.sql` | DDL | 3 types ENUM, 11 tables, index, 4 triggers, 4 vues, 28 fonctions (CRUD + agrégation). |
| `seed.mjs` | Générateur Node.js | Lit `data.js`, décode les photos base64, génère `seed.sql`. Non exécuté (Node & `data.js` absents). |
| `seed.sql` | Données | Généré par `seed.mjs`. INSERT initiaux, précédé d'un TRUNCATE idempotent. |

**Modèle de données :** 11 tables — `evenement`, `programme_jour`, `numero_contact`, `cheikh`,
`cheikh_citation`, `personne` (dignitaires + membres unifiés), `galerie_photo`, `utilisateur`,
`otp_defi`, `revision`, `don`.

---

## 3. État initial

Base `jaksn_db` **existante mais entièrement vide** — aucun objet, aucune donnée.

---

## 4. Application des scripts

### 4.1 Objets créés (`schema.sql`)

| Type | Nombre | Détail |
|---|---|---|
| Types ENUM | 3 | `personne_categorie`, `utilisateur_role`, `don_statut` |
| Tables | 11 | modèle métier complet |
| Vues | 4 | `v_dignitaire`, `v_membre`, `v_membre_fondateur`, `v_site_data` |
| Fonctions | 28 | CRUD + agrégation + trigger |
| Triggers | 4 | dont `touch_modifie_le` |

### 4.2 Données chargées (`seed.sql`)

| Table | Lignes |
|---|---|
| evenement | 1 |
| programme_jour | 3 |
| numero_contact | 3 |
| cheikh | 1 (singleton) |
| cheikh_citation | 2 |
| personne — dignitaires | 20 |
| personne — membres | 15 (dont 10 fondateurs) |
| galerie_photo | 45 |
| utilisateur / otp_defi / revision / don | 0 (attendu — lots ultérieurs) |

---

## 5. Bugs identifiés et corrigés

### Bug 1 — `programme_jour_creer` (`schema.sql`)
- **Cause :** `p_evenement_id smallint DEFAULT 1` placé **avant** `p_jour text` (sans défaut).
- **Erreur :** `input parameters after one with a default value must also have defaults`.
- **Correction :** réordonnancement — `p_jour` en premier, `p_evenement_id` en dernier.
- **Impact :** nul — aucun appelant n'utilise l'ancien ordre positionnel (vérifié par recherche globale).

### Bug 2 — INSERT `evenement` (`seed.sql`)
- **Cause :** colonne `evenement.id` en `GENERATED ALWAYS AS IDENTITY` ; PostgreSQL refuse une valeur explicite sans `OVERRIDING SYSTEM VALUE`.
- **Erreur :** `cannot insert a non-DEFAULT value into column "id"`.
- **Correction :** ajout de `OVERRIDING SYSTEM VALUE` dans `seed.sql` **et** dans le template générateur `seed.mjs` (pour toute régénération future).

---

## 6. Tests de validation

| Test | Résultat |
|---|---|
| `site_data_json()` — structure | ✅ JSONB, 5 clés : `settings`, `cheikh`, `dignitaires`, `jak`, `galerie` |
| `settings` — cohérence | ✅ nom, lieu CICES Dakar, 3 numéros WhatsApp, 3 jours |
| `cheikh` + citations | ✅ « Cheikh Ould Khaiïry », 2 citations |
| `dignitaires` | ✅ 20 entrées |
| `jak` (membres) | ✅ 15 entrées, fondateurs en tête (ordre correct) |
| `galerie` | ✅ 45 photos |
| Trigger `touch_modifie_le` | ✅ `modifie_le` mis à jour automatiquement |
| Contrainte slug unique | ✅ doublon rejeté |
| Contrainte cheikh singleton (id=1) | ✅ CHECK active |
| Validation téléphone (9 chiffres) | ✅ RAISE EXCEPTION sur format invalide |
| `programme_jour_creer` (corrigée) | ✅ retourne la ligne créée |
| `galerie_lister`, `evenement_lire_courant` | ✅ fonctionnelles |
| Vue `v_site_data` | ✅ retourne un JSONB `object` |

**Résultat global : tous les tests au vert.**

---

## 7. Corrections des fichiers source

| Fichier | Modification |
|---|---|
| `schema.sql` | Paramètres de `programme_jour_creer` réordonnés. |
| `seed.sql` | Ajout de `OVERRIDING SYSTEM VALUE` sur l'INSERT `evenement`. |
| `seed.mjs` | Ajout de `OVERRIDING SYSTEM VALUE` dans le template de génération. |

Le dossier est désormais **propre et rejouable de bout en bout** (schéma + seed) sur une base vierge, sans erreur.

---

## 8. Points d'attention / suite

- Tables `utilisateur`, `otp_defi`, `revision`, `don` vides — **attendu** : relèvent des Lots 1, 2 et 4 (authentification OTP, versioning, paiements) non encore implémentés.
- Colonne `galerie_photo.url_thumb` nullable — **attendu** : Lot 3 (vignettes) à venir.
- Champs `[À COMPLÉTER]` — placeholders intentionnels pour l'administration du contenu.

---

*Fin du rapport.*
