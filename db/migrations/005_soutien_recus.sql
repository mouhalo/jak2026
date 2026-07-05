-- ============================================================
--  MIGRATION 005 — Table `soutien_recus` (chantier 2 — JAK 2026)
-- ============================================================
--  Auteur   : dba_master (Expert DBA PostgreSQL)
--  Date     : 2026-07-05
--  Objet    : Projection PUBLIQUE prête à afficher dans le carrousel
--             des soutiens, alimentée depuis `don` confirmés.
--
--  RAISON D'ÊTRE (vue matérialisée vs table)
--    On choisit une TABLE (et non une vue) car :
--      • Le carrousel doit être rapide à servir (pas de JOIN sur don).
--      • On ne stocke JAMAIS le téléphone clair ici : seule la version
--        MASQUÉE y figure (calculée en SQL par numero_mask()).
--      • Le montant est stocké (audit) mais NON retourné par
--        soutien_lister() (décision utilisateur).
--      • La contrainte UNIQUE sur reference_don est la garantie
--        d'idempotence : un 2e soutien_upsert sur la même réf est
--        un no-op (ON CONFLICT DO NOTHING).
--
--  CARACTÉRISTIQUES
--    • Rejouable : CREATE TABLE IF NOT EXISTS + CREATE INDEX IF NOT EXISTS.
--    • Non-cassant : table nouvelle, ne touche pas à `don`.
--
--  PRÉREQUIS : table `don` étendue (migration 004).
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS soutien_recus (
    id              bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    don_id          bigint      NOT NULL,                      -- FK vers don.id
    reference_don   text        NOT NULL,                      -- réf interne (anti-double)
    numero_masque   text        NOT NULL,                      -- '77 •• •• 61' (déjà masqué)
    nom_affiche     text        NOT NULL DEFAULT 'Anonyme',
    canal           text        NOT NULL,                      -- 'OM' | 'WAVE'
    montant         integer     NOT NULL,                      -- stocké mais NON affiché
    confirme_le     timestamptz NOT NULL,
    -- Clé d'idempotence : un don ne peut générer qu'un seul soutien.
    CONSTRAINT soutien_recus_reference_don_key UNIQUE (reference_don),
    -- Lien vers le don source (ON DELETE CASCADE : si le don est purgé,
    -- le soutien disparaît — cohérence référentielle).
    CONSTRAINT soutien_recus_don_id_fkey
        FOREIGN KEY (don_id) REFERENCES don(id) ON DELETE CASCADE
);

COMMENT ON TABLE  soutien_recus IS 'Projection publique des dons confirmés, prête à afficher dans le carrousel. Téléphone déjà masqué, montant stocké mais non listé.';
COMMENT ON COLUMN soutien_recus.reference_don IS 'Référence interne du don (≤11 car). Clé d''idempotence : un 2e upsert sur la même réf = no-op.';
COMMENT ON COLUMN soutien_recus.numero_masque IS 'Téléphone déjà masqué en SQL (numero_mask). JAMAIS de téléphone clair dans cette table.';
COMMENT ON COLUMN soutien_recus.montant IS 'Montant stocké pour audit, mais NON retourné par soutien_lister (décision utilisateur).';

-- ------------------------------------------------------------
--  Index
-- ------------------------------------------------------------
-- Liste paginée chronologique (carrousel : derniers soutiens d'abord).
CREATE INDEX IF NOT EXISTS idx_soutien_recus_confirme_le
    ON soutien_recus (confirme_le DESC);

COMMIT;

-- ============================================================
--  FIN migration 005
-- ============================================================
