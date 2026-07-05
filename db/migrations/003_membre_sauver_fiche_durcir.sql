-- ============================================================
--  MIGRATION 003 — Durcissement membre_sauver_fiche + personne_par_id
-- ============================================================
--  Auteur   : dba_master (Expert DBA PostgreSQL)
--  Date     : 2026-07-04
--  Objet    : (T3) Cloisonner membre_sauver_fiche par DOUBLE-CLÉ
--                 (personne_id ET telephone) au lieu du téléphone seul.
--             (T4) Ajouter personne_par_id() pour verify-otp.
--
--  CARACTÉRISTIQUES
--    • Rejouable : CREATE OR REPLACE FUNCTION (idempotent).
--    • Sécurité  : SECURITY INVOKER (défaut).
--    • Non-cassant : les 32 autres fonctions sont intactes.
--
--  ⚠ BREAKING CHANGE — membre_sauver_fiche (signature modifiée)
--    AVANT : membre_sauver_fiche(p_tel varchar, p_nom_complet text,
--                                p_url_photo text, p_adresse text,
--                                p_biographie text)
--            cloisonnement : telephone = p_tel
--    APRÈS : membre_sauver_fiche(p_personne_id bigint, p_telephone varchar,
--                                p_nom_complet text, p_url_photo text,
--                                p_adresse text, p_biographie text)
--            cloisonnement : id = p_personne_id AND telephone = p_telephone
--
--    → Le backend PHP DOIT migrer ses appels : transmettre l'id personne
--      (issu de otp_verifier().personne_id) EN PLUS du téléphone normalisé.
--      L'ancienne signature à 1 paramètre d'identification (p_tel) disparaît.
--
--  RAISON DU DURCISSEMENT
--    Le téléphone est désormais normalisé en base (migration 002) et sert
--    déjà de clé unique. L'id ajouté apporte une défense en profondeur :
--    si jamais un téléphone venait à être réassigné ou qu'une incohérence
--    id/tel survenait (réplica, migration partielle), l'UPDATE échouerait
--    plutôt que de modifier la mauvaise fiche. Double-clé = ceinture +
--    bretelles.
-- ============================================================

BEGIN;

-- ------------------------------------------------------------
-- SUPPRESSION EXPLICITE de l'ancienne signature surchargée.
-- ⚠ IMPORTANT : CREATE OR REPLACE ne remplace une fonction existante
-- QUE si la liste des types de paramètres est identique. Comme la
-- nouvelle signature débute par (bigint, varchar, ...) et l'ancienne
-- par (varchar, ...), PostgreSQL créerait une SURCHARGE sans ce DROP.
-- On retire donc l'ancienne pour garantir la rupture nette.
-- Idempotent : DROP FUNCTION IF EXISTS.
-- ------------------------------------------------------------
DROP FUNCTION IF EXISTS membre_sauver_fiche(varchar, text, text, text, text);

-- ============================================================
--  T3 — membre_sauver_fiche (durcie, double-clé id + telephone)
-- ============================================================
--  Rôle : Met à jour SA fiche membre, mais UNIQUEMENT si l'id ET le
--         téléphone matchent tous les deux (double-clé). Toute autre
--         combinaison (id bon + tel faux, ou inverse) → NOT FOUND →
--         exception. Cela empêche un membre de modifier une fiche
--         dont l'id ne correspond pas à son téléphone authentifié.
--
--  Paramètres
--    p_personne_id  bigint  — id de la fiche (issu de otp_verifier)
--    p_telephone    varchar — téléphone normalisé E.164 (authentifié)
--    p_nom_complet  text (déf. NULL = conserver)
--    p_url_photo    text (déf. NULL = conserver)
--    p_adresse      text (déf. NULL = conserver)
--    p_biographie   text (déf. NULL = conserver)
--
--  Retour : personne — la ligne mise à jour (RETURNING *).
--  Erreur : 'membre introuvable (id/tel incohérents)' si NOT FOUND.
--
--  Note : p_telephone doit déjà être normalisé (E.164) côté PHP avant
--         l'appel, conformément à la migration 002 et au nouveau format
--         de otp_creer. On ne re-normalise PAS ici (la base fait foi).
-- ============================================================
CREATE OR REPLACE FUNCTION membre_sauver_fiche(
    p_personne_id bigint,
    p_telephone   varchar,
    p_nom_complet text DEFAULT NULL,
    p_url_photo   text DEFAULT NULL,
    p_adresse     text DEFAULT NULL,
    p_biographie  text DEFAULT NULL
) RETURNS personne
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    v personne;
BEGIN
    UPDATE personne SET
        nom_complet = COALESCE(p_nom_complet, nom_complet),
        url_photo   = COALESCE(p_url_photo,   url_photo),
        adresse     = COALESCE(p_adresse,     adresse),
        biographie  = COALESCE(p_biographie,  biographie)
    WHERE id = p_personne_id
      AND telephone = p_telephone
      AND categorie = 'membre'
    RETURNING * INTO v;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'membre introuvable (id/tel incohérents)'
            USING ERRCODE = 'P0002';  -- undefined_object (cas fonctionnel)
    END IF;

    RETURN v;
END;
$$;

COMMENT ON FUNCTION membre_sauver_fiche(bigint, varchar, text, text, text, text)
    IS 'J1 — Met a jour SA fiche membre, cloisonnee par DOUBLE-CLE (personne_id ET telephone). Breaking: ancienne signature (p_tel,...) remplacee.';


-- ============================================================
--  T4 — personne_par_id(p_id bigint)
-- ============================================================
--  Rôle : Récupère une fiche personne par son id. Utile côté verify-otp :
--         otp_verifier() retourne personne_id (bigint) ; cette fonction
--         remonte la fiche complète (dont le slug pour le front).
--
--  NOTE : Une fonction équivalente personne_lire(p_id bigint) existe déjà
--         dans db/schema.sql. On crée ici un alias sémantique explicite
--         personne_par_id (cohérent avec personne_par_telephone) pour la
--         lisibilité du flux verify-otp, sans supprimer personne_lire.
--
--  Paramètre : p_id bigint — id de la personne.
--  Retour    : personne — la ligne, ou NULL si introuvable.
-- ============================================================
CREATE OR REPLACE FUNCTION personne_par_id(p_id bigint)
RETURNS personne
LANGUAGE sql
STABLE
SECURITY INVOKER
AS $$
    SELECT * FROM personne WHERE id = p_id;
$$;

COMMENT ON FUNCTION personne_par_id(bigint)
    IS 'J1 — Retourne la fiche personne par id (alias STABLE de personne_lire, pour le flux verify-otp).';

COMMIT;

-- ============================================================
--  FIN — Vérification rapide :
--    -- T3 : créer un membre de test, sauver (ok), sauver faux tel (echec)
--    -- T4 : SELECT personne_par_id(25);
-- ============================================================
