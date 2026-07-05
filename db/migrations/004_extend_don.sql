-- ============================================================
--  MIGRATION 004 — Extension table `don` (chantier 2 — JAK 2026)
-- ============================================================
--  Auteur   : dba_master (Expert DBA PostgreSQL)
--  Date     : 2026-07-05
--  Objet    : Étendre la table `don` existante pour le flux V2
--             (page de collecte Wave/Orange Money via pay_services).
--
--  CONTEXTE
--    Le don est créé `en_attente` AVANT l'appel à `pay_services`.
--    L'uuid renvoyé par `add_payement` est ensuite ATTACHÉ au don
--    (don_attacher_uuid). Le `uuid` est donc NULLABLE initialement,
--    mais UNIQUE lorsqu'il est renseigné.
--
--  CARACTÉRISTIQUES
--    • Rejouable : ADD COLUMN IF NOT EXISTS + CREATE INDEX IF NOT EXISTS.
--    • Non-cassant : ne modifie aucune colonne existante, n'altère pas
--      le contenu (table don souvent vide ou avec dons V1 valides).
--
--  PRÉREQUIS : table `don` + enum `don_statut` déjà créés (schema.sql).
-- ============================================================

BEGIN;

-- ------------------------------------------------------------
--  Colonnes ajoutées au flux V2
-- ------------------------------------------------------------

-- uuid pay_services : clé de transaction pour le polling
-- payment_status/{uuid}. NULL jusqu'à l'appel add_payement,
-- UNIQUE quand renseigné (anti-double-attachement).
ALTER TABLE don ADD COLUMN IF NOT EXISTS uuid uuid;

-- Nom/pseudo du donateur affiché publiquement dans le carrousel.
-- NULL → "Anonyme" côté affichage.
ALTER TABLE don ADD COLUMN IF NOT EXISTS nom_affiche text;

-- Référence interne (≤11 car) générée côté PHP par generateReference().
-- Ex. 'JAK7A3X9K2'. Sert de clé d'idempotence pour soutien_recus.
ALTER TABLE don ADD COLUMN IF NOT EXISTS reference_interne text;

-- Téléphone complet E.164 pour AUDIT (jamais exposé publiquement,
-- contrairement à telephone_masque). varchar(15) = max E.164 (+15).
ALTER TABLE don ADD COLUMN IF NOT EXISTS telephone_clair varchar(15);

-- ------------------------------------------------------------
--  Contraintes
-- ------------------------------------------------------------
-- uuid UNIQUE seulement quand renseigné : on garde la NULLabilité,
-- le UNIQUE est partiel (NULL ignoré → plusieurs dons en_attente
-- sans uuid coexistent sans collision, mais 2 dons ne peuvent
-- partager le MÊME uuid pay_services).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'don_uuid_key'
           AND conrelid = 'don'::regclass
    ) THEN
        ALTER TABLE don ADD CONSTRAINT don_uuid_key UNIQUE (uuid);
    END IF;
END $$;

-- ------------------------------------------------------------
--  Index (idempotents)
-- ------------------------------------------------------------
-- Recherche rapide d'un don par uuid (polling de confirmation).
CREATE UNIQUE INDEX IF NOT EXISTS idx_don_uuid
    ON don (uuid)
    WHERE uuid IS NOT NULL;

-- Filtrage par statut (dashboard admin : dons en_attente, etc.).
-- Index partiel sur en_attente (le statut le plus interrogé en
-- polling). Index général sur statut pour les autres cas.
CREATE INDEX IF NOT EXISTS idx_don_statut
    ON don (statut);

COMMIT;

-- ============================================================
--  FIN migration 004
-- ============================================================
