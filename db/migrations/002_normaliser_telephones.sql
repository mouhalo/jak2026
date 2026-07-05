-- ============================================================
--  MIGRATION 002 — Normalisation des téléphones au format E.164
-- ============================================================
--  Auteur   : dba_master (Expert DBA PostgreSQL)
--  Date     : 2026-07-04
--  Objet    : Standardiser TOUS les téléphones au format international
--             E.164 (+<indicatif><numero>), ex. '+221REDACTED'.
--             Décision J1 : le format '9 chiffres nus' est abandonné au
--             profit du format international complet avec '+'.
--
--  CARACTÉRISTIQUES
--    • Rejouable (idempotent) : un téléphone déjà normalisé n'est pas
--      modifié ; l'UPDATE cible uniquement les lignes hors-format.
--    • Non-cassant : ne touche ni aux fonctions, ni aux contraintes
--      existantes ; préserve NULL.
--    • Transactionnel (BEGIN/COMMIT).
--
--  PRÉALABLE STRUCTUREL
--    Les colonnes telephone de otp_defi et utilisateur sont varchar(9) :
--    trop courtes pour '+221XXXXXXXXX' (13 à 16 caractères). On les
--    élargit à varchar(20) pour accueillir tout format E.164 valide
--    (max 15 chiffres + '+'). La table personne était déjà varchar(15)
--    (portée à 20 par cohérence).
--
--  RÈGLES DE NORMALISATION (par ordre de priorité)
--    1. Déjà au format E.164  ('^\+[0-9]{6,15}$') → inchangé.
--    2. 9 chiffres NUS (SN sans indicatif, 'REDACTED')
--       → '+221' || chiffres.
--    3. Commence par '221' et fait 12 chiffres ('221REDACTED')
--       → '+' || chiffres.
--    4. Sinon : '+' || (chiffres nets après strip \D) — E.164 générique.
--       Gère les cas sales (espaces, tirets, points) comme
--       '+2217706153 61' → '+221770615361'.
--    NB : on strip TOUT ce qui n'est pas un chiffre (REGEXP_REPLACE
--         tel, '\D', '', 'g') avant d'appliquer les préfixes, ce qui
--         élimine espaces parasites, tirets, points, parenthèses.
--
--  SÉCURITÉ DOUBLONS
--    La contrainte unique uniq_personne_telephone (WHERE telephone IS
--    NOT NULL) pourrait rejeter l'UPDATE si deux numéros convergent.
--    Vérification préalable effectuée (cf. _tmp_check.sql) : AUCUN
--    doublon détecté après normalisation → migration sûre. La garde
--    WHERE tel <> tel_norm protège aussi contre une ré-exécution.
-- ============================================================

BEGIN;

-- ------------------------------------------------------------
-- 1. ÉLARGIR LES COLONNES telephone (préalable E.164)
--    ALTER ... TYPE est idempotent (nop si déjà varchar(20)).
--    NB : les vues v_dignitaire / v_membre / v_membre_fondateur sont
--    des SELECT * sur personne et bloquent l'ALTER TYPE. On les
--    dédropp/re-crée autour de l'ALTER ( DROP IF EXISTS ... CREATE
--    OR REPLACE ). Recréées à l'identique du db/schema.sql.
-- ------------------------------------------------------------
DROP VIEW IF EXISTS v_dignitaire;
DROP VIEW IF EXISTS v_membre;
DROP VIEW IF EXISTS v_membre_fondateur;

ALTER TABLE personne    ALTER COLUMN telephone TYPE varchar(20);
ALTER TABLE otp_defi    ALTER COLUMN telephone TYPE varchar(20);
ALTER TABLE utilisateur ALTER COLUMN telephone TYPE varchar(20);

CREATE OR REPLACE VIEW v_dignitaire AS
  SELECT * FROM personne WHERE categorie = 'dignitaire' ORDER BY ordre;

CREATE OR REPLACE VIEW v_membre AS
  SELECT * FROM personne WHERE categorie = 'membre' ORDER BY fondateur DESC, ordre;

CREATE OR REPLACE VIEW v_membre_fondateur AS
  SELECT * FROM personne WHERE categorie = 'membre' AND fondateur ORDER BY ordre;

-- ------------------------------------------------------------
-- 2. NORMALISATION — table personne (membres ET autres catégories)
--    Idempotent : ne touche que les lignes dont le téléphone n'est
--    PAS déjà au format E.164 final calculé.
-- ------------------------------------------------------------
UPDATE personne
   SET telephone =
        CASE
          -- déjà E.164 propre → tel quel
          WHEN telephone ~ '^\+[0-9]{6,15}$' THEN telephone
          -- 9 chiffres nus (SN sans indicatif) → +221...
          WHEN REGEXP_REPLACE(telephone, '\D', '', 'g') ~ '^[0-9]{9}$'
               AND telephone !~ '\+'
               AND REGEXP_REPLACE(telephone, '\D', '', 'g') !~ '^221'
            THEN '+221' || REGEXP_REPLACE(telephone, '\D', '', 'g')
          -- 12 chiffres commençant par 221 (221XXXXXXXXX) → +221...
          WHEN REGEXP_REPLACE(telephone, '\D', '', 'g') ~ '^221[0-9]{9}$'
            THEN '+' || REGEXP_REPLACE(telephone, '\D', '', 'g')
          -- reste : + + chiffres nets (E.164 générique ; gère espaces parasites)
          ELSE '+' || REGEXP_REPLACE(telephone, '\D', '', 'g')
        END
 WHERE telephone IS NOT NULL
   AND telephone !~ '^\+[0-9]{6,15}$';   -- garde d'idempotence

-- ------------------------------------------------------------
-- 3. NORMALISATION — tables otp_defi et utilisateur
--    (actuellement vides mais reste correct pour l'avenir)
-- ------------------------------------------------------------
UPDATE otp_defi
   SET telephone =
        CASE
          WHEN telephone ~ '^\+[0-9]{6,15}$' THEN telephone
          WHEN REGEXP_REPLACE(telephone, '\D', '', 'g') ~ '^[0-9]{9}$'
               AND telephone !~ '\+'
               AND REGEXP_REPLACE(telephone, '\D', '', 'g') !~ '^221'
            THEN '+221' || REGEXP_REPLACE(telephone, '\D', '', 'g')
          WHEN REGEXP_REPLACE(telephone, '\D', '', 'g') ~ '^221[0-9]{9}$'
            THEN '+' || REGEXP_REPLACE(telephone, '\D', '', 'g')
          ELSE '+' || REGEXP_REPLACE(telephone, '\D', '', 'g')
        END
 WHERE telephone IS NOT NULL
   AND telephone !~ '^\+[0-9]{6,15}$';

UPDATE utilisateur
   SET telephone =
        CASE
          WHEN telephone ~ '^\+[0-9]{6,15}$' THEN telephone
          WHEN REGEXP_REPLACE(telephone, '\D', '', 'g') ~ '^[0-9]{9}$'
               AND telephone !~ '\+'
               AND REGEXP_REPLACE(telephone, '\D', '', 'g') !~ '^221'
            THEN '+221' || REGEXP_REPLACE(telephone, '\D', '', 'g')
          WHEN REGEXP_REPLACE(telephone, '\D', '', 'g') ~ '^221[0-9]{9}$'
            THEN '+' || REGEXP_REPLACE(telephone, '\D', '', 'g')
          ELSE '+' || REGEXP_REPLACE(telephone, '\D', '', 'g')
        END
 WHERE telephone IS NOT NULL
   AND telephone !~ '^\+[0-9]{6,15}$';

COMMIT;

-- ============================================================
--  FIN — Vérification rapide :
--    SELECT id, slug, telephone FROM personne
--     WHERE categorie='membre' AND telephone IS NOT NULL ORDER BY id;
-- ============================================================
