-- ============================================================
--  MIGRATION 006 — Table `journal_organisation` (chantier 2 — JAK 2026)
-- ============================================================
--  Auteur   : dba_master (Expert DBA PostgreSQL)
--  Date     : 2026-07-05
--  Objet    : Journal d'audit opérationnel/financier :
--               dons, réconciliations, actions admin, etc.
--
--  DISTINCTION AVEC `revision`
--    `revision`         : audit de l'ÉDITION DE CONTENU (CRUD cheikh,
--                         evenement, etc.) — comparaison avant/après.
--    `journal_organisation` : audit du FLUX OPÉRATIONNEL (cycle de vie
--                         d'un don : don_cree → don_confirme/don_echoue,
--                         rattrapages, réconciliations batch).
--
--  CARACTÉRISTIQUES
--    • Rejouable : CREATE TABLE IF NOT EXISTS + CREATE INDEX IF NOT EXISTS.
--    • Append-only : on n'insère jamais, on ne modifie jamais (les
--      fonctions ne font que INSERT). Le journal est immuable.
--
--  PRÉREQUIS : aucun (table indépendante).
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS journal_organisation (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    evenement   text        NOT NULL,                  -- 'don_cree'|'don_confirme'|'don_echoue'|'reconciliation'|...
    cible_type  text,                                  -- 'don'|'soutien'|...
    cible_id    bigint,                                -- id du don/soutien concerné
    details     jsonb,                                 -- contexte (uuid, montant, raison, ...)
    cree_le     timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE  journal_organisation IS 'Journal d''audit opérationnel/financier (cycle de vie des dons, réconciliations). Append-only. Distinct de `revision` (édition de contenu).';
COMMENT ON COLUMN journal_organisation.evenement  IS 'Type d''événement : don_cree, don_confirme, don_echoue, don_uuid_attache, reconciliation, ...';
COMMENT ON COLUMN journal_organisation.cible_type IS 'Type de l''objet concerné : don, soutien, ...';
COMMENT ON COLUMN journal_organisation.cible_id   IS 'Identifiant de l''objet concerné (don.id ou soutien_recus.id).';
COMMENT ON COLUMN journal_organisation.details    IS 'Contexte JSON libre : {uuid, montant, canal, raison, source_polling, ...}';

-- ------------------------------------------------------------
--  Index
-- ------------------------------------------------------------
-- Recherche par cible (historique d'un don/soutien précis).
CREATE INDEX IF NOT EXISTS idx_journal_organisation_cible
    ON journal_organisation (cible_type, cible_id)
    WHERE cible_id IS NOT NULL;

-- Filtrage par type d'événement (ex. dashboard des échecs).
CREATE INDEX IF NOT EXISTS idx_journal_organisation_evenement
    ON journal_organisation (evenement);

-- Chronologie (consultation ordonnée, paginée).
CREATE INDEX IF NOT EXISTS idx_journal_organisation_cree_le
    ON journal_organisation (cree_le DESC);

COMMIT;

-- ============================================================
--  FIN migration 006
-- ============================================================
