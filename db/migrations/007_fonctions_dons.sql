-- ============================================================
--  MIGRATION 007 — Fonctions PL/pgSQL du chantier 2 (JAK 2026)
-- ============================================================
--  Auteur   : dba_master (Expert DBA PostgreSQL)
--  Date     : 2026-07-05
--  Objet    : 8 fonctions pour le flux de don Wave/Orange Money :
--               numero_mask, journal_ajouter, soutien_upsert,
--               soutien_lister, don_attacher_uuid, don_confirmer,
--               don_echouer, don_creer.
--
--  IDEMPOTENCE — Cœur du chantier 2
--    pay_services ne fournit PAS de webhook : la confirmation se fait
--    par POLLING (3 chemins concurrents : redirect, polling navigateur,
--    job de rattrapage). Plusieurs endpoints peuvent donc tenter de
--    confirmer le MÊME don → il ne faut JAMAIS de double comptage.
--    Garde-fous :
--      • don_confirmer / don_echouer : UPDATE ... WHERE statut='en_attente'
--        → ne matche qu'une fois ; un 2e appel = no_op (no error).
--      • soutien_upsert : INSERT ... ON CONFLICT (reference_don) DO NOTHING
--        → la contrainte UNIQUE sur reference_don garantit l'unicité du
--        soutien même en cas de rattrapage.
--
--  CARACTÉRISTIQUES
--    • Rejouable : CREATE OR REPLACE FUNCTION.
--    • Sécurité   : SECURITY INVOKER + LANGUAGE plpgsql.
--    • Non-cassant : n'altère aucune des 31 fonctions existantes.
--
--  PRÉREQUIS : migrations 004, 005, 006 appliquées.
-- ============================================================

BEGIN;

-- ============================================================
--  4g. numero_mask — Masquage d'un téléphone (helper immuable)
-- ============================================================
--  Rôle        : Masque un numéro pour affichage public.
--                '777306661'      → '77 •• •• 61'
--                '+221777306661'  → '+221 77 •• •• 61'
--                Conserve 2 premiers + 2 derniers chiffres du corps
--                national, remplace le milieu par ' •• •• '.
--
--  Paramètre   : p_tel varchar — n° E.164 ('+221...') ou national ('77...').
--  Retour      : text — n° masqué, ou NULL si entrée NULL/non reconnue.
--
--  SÉCURITÉ    : IMMUTABLE (résultat déterministe, indexable). Utilisée
--                par don_creer pour remplir telephone_masque. Le téléphone
--                clair n'arrive JAMAIS dans soutien_recus.
-- ============================================================
CREATE OR REPLACE FUNCTION numero_mask(p_tel varchar)
RETURNS text
LANGUAGE plpgsql
IMMUTABLE
SECURITY INVOKER
AS $$
DECLARE
    v_clean  text;   -- '+' + chiffres uniquement
    v_digits text;   -- chiffres uniquement
    v_prefix text := '';  -- indicatif éventuel ('+221 ')
    v_body   text;        -- corps national à masquer
BEGIN
    IF p_tel IS NULL OR btrim(p_tel) = '' THEN
        RETURN NULL;
    END IF;
    -- Ne garder que '+' en tête et les chiffres.
    v_clean := regexp_replace(p_tel, '[^0-9+]', '', 'g');
    IF v_clean !~ '^\+?[0-9]+$' THEN
        RETURN NULL;   -- format non reconnu
    END IF;
    v_digits := ltrim(v_clean, '+');

    -- Si E.164 (présence de '+') avec > 9 chiffres : on isole l'indicatif
    -- (tout sauf les 9 derniers chiffres = corps national SN).
    IF v_clean LIKE '+%' AND length(v_digits) > 9 THEN
        v_prefix := '+' || left(v_digits, length(v_digits) - 9) || ' ';
        v_body   := right(v_digits, 9);
    ELSE
        v_prefix := '';
        v_body   := v_digits;
    END IF;

    -- Corps trop court (< 4 chiffres) : on masque intégralement (sécurité).
    IF length(v_body) < 4 THEN
        RETURN v_prefix || repeat('•', GREATEST(length(v_body), 0));
    END IF;

    -- Format final : 2 premiers + ' •• •• ' + 2 derniers.
    RETURN v_prefix || left(v_body, 2) || ' •• •• ' || right(v_body, 2);
END $$;


-- ============================================================
--  4h. journal_ajouter — Insertion d'une entrée d'audit
-- ============================================================
--  Rôle        : Ajoute une ligne au journal opérationnel
--                (append-only). Utilisée par toutes les fonctions
--                de cycle de vie du don.
--
--  Paramètres
--    p_evenement   text    — 'don_cree'|'don_confirme'|'don_echoue'|...
--    p_cible_type  text    — 'don'|'soutien'|... (NULL si non pertinent)
--    p_cible_id    bigint  — id du don/soutien (NULL si non pertinent)
--    p_details     jsonb   — contexte libre {uuid, montant, raison, ...}
--
--  Retour       : bigint — id de l'entrée créée.
-- ============================================================
CREATE OR REPLACE FUNCTION journal_ajouter(
    p_evenement  text,
    p_cible_type text   DEFAULT NULL,
    p_cible_id   bigint DEFAULT NULL,
    p_details    jsonb  DEFAULT NULL
) RETURNS bigint
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    v_id bigint;
BEGIN
    INSERT INTO journal_organisation (evenement, cible_type, cible_id, details)
    VALUES (p_evenement, p_cible_type, p_cible_id, p_details)
    RETURNING id INTO v_id;
    RETURN v_id;
END $$;


-- ============================================================
--  4e. soutien_upsert — Alimentation idempotente du carrousel
-- ============================================================
--  Rôle        : Insère (ou ignore) un soutien dans soutien_recus.
--                IDEMPOTENT : ON CONFLICT (reference_don) DO NOTHING.
--                C'est la garantie anti-double-comptage du carrousel.
--
--  Paramètres  : p_don_id, p_reference_don (clé unique), p_numero_masque
--                (déjà masqué en SQL), p_nom_affiche, p_canal, p_montant,
--                p_confirme_le.
--  Retour      : bigint — id du soutien (nouvellement créé OU déjà
--                existant en cas de conflit).
--
--  NOTE        : p_numero_masque DOIT déjà être masqué (numero_mask).
--                Aucun téléphone clair n'entre dans cette table.
-- ============================================================
CREATE OR REPLACE FUNCTION soutien_upsert(
    p_don_id        bigint,
    p_reference_don text,
    p_numero_masque text,
    p_nom_affiche   text,
    p_canal         text,
    p_montant       integer,
    p_confirme_le   timestamptz
) RETURNS bigint
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    v_id bigint;
BEGIN
    INSERT INTO soutien_recus (don_id, reference_don, numero_masque,
                               nom_affiche, canal, montant, confirme_le)
    VALUES (p_don_id, p_reference_don, p_numero_masque,
            COALESCE(NULLIF(p_nom_affiche, ''), 'Anonyme'),
            p_canal, p_montant, p_confirme_le)
    ON CONFLICT (reference_don) DO NOTHING
    RETURNING id INTO v_id;

    -- En cas de conflit (DO NOTHING) : RETURNING est vide → on récupère
    -- l'id de la ligne existante (transparence pour l'appelant).
    IF v_id IS NULL THEN
        SELECT id INTO v_id FROM soutien_recus WHERE reference_don = p_reference_don;
    END IF;
    RETURN v_id;
END $$;


-- ============================================================
--  4f. soutien_lister — Liste publique paginée du carrousel
-- ============================================================
--  Rôle        : Retourne les soutiens confirmés, triés par date de
--                confirmation décroissante, paginés.
--
--  Paramètres  : p_limit  (déf. 20, max 200), p_offset (déf. 0).
--  Retour      : TABLE(numero_masque, nom_affiche, canal, confirme_le).
--
--  SÉCURITÉ    : Ne retourne JAMAIS le montant (stocké mais non listé —
--                décision utilisateur) ni le téléphone clair (absent de
--                la table). Ne retourne pas non plus don_id ni id.
-- ============================================================
CREATE OR REPLACE FUNCTION soutien_lister(
    p_limit  integer DEFAULT 20,
    p_offset integer DEFAULT 0
) RETURNS TABLE(
    numero_masque text,
    nom_affiche   text,
    canal         text,
    confirme_le   timestamptz
)
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
BEGIN
    -- Bornage défensif des paramètres de pagination.
    IF p_limit IS NULL OR p_limit <= 0 OR p_limit > 200 THEN
        p_limit := 20;
    END IF;
    IF p_offset IS NULL OR p_offset < 0 THEN
        p_offset := 0;
    END IF;

    RETURN QUERY
    SELECT s.numero_masque, s.nom_affiche, s.canal, s.confirme_le
      FROM soutien_recus s
     ORDER BY s.confirme_le DESC
     LIMIT p_limit OFFSET p_offset;
END $$;


-- ============================================================
--  4b. don_attacher_uuid — Attache l'uuid pay_services à un don
-- ============================================================
--  Rôle        : Après add_payement (pay_services), on attache l'uuid
--                reçu au don créé en_attente. Cet uuid sert ensuite au
--                polling de payment_status/{uuid}.
--
--  Paramètres  : p_don_id, p_uuid.
--  Retour      : boolean — true si l'uuid a été attaché (le don était
--                sans uuid), false si déjà présent ou don introuvable.
--
--  IDEMPOTENCE : UPDATE ... WHERE uuid IS NULL → ré-appel sans effet.
-- ============================================================
CREATE OR REPLACE FUNCTION don_attacher_uuid(
    p_don_id bigint,
    p_uuid   uuid
) RETURNS boolean
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
BEGIN
    IF p_don_id IS NULL OR p_uuid IS NULL THEN
        RETURN false;
    END IF;

    UPDATE don
       SET uuid = p_uuid
     WHERE id = p_don_id
       AND uuid IS NULL;   -- idempotent : ne touche pas si déjà renseigné

    IF FOUND THEN
        PERFORM journal_ajouter(
            'don_uuid_attache', 'don', p_don_id,
            jsonb_build_object('uuid', p_uuid));
        RETURN true;
    END IF;
    RETURN false;
END $$;


-- ============================================================
--  4c. don_confirmer — Confirme un don (IDEMPOTENTE, cœur du chantier)
-- ============================================================
--  Rôle        : Marque un don comme 'confirme' et alimente le carrousel
--                (soutien_recus) via soutien_upsert.
--
--  Paramètres
--    p_don_id        bigint  — id du don à confirmer
--    p_uuid          uuid    — uuid pay_services (optionnel, comblé si NULL)
--    p_ref_operateur text    — référence opérateur (optionnelle)
--
--  Retour       : jsonb
--    • {ok:true, action:'confirme', don_id} — le don était en_attente,
--      il vient d'être confirmé + soutien créé.
--    • {ok:true, action:'no_op', raison:'deja_traite', don_id} — le don
--      n'était plus en_attente (déjà confirmé ou échoué) → rien à faire.
--
--  IDEMPOTENCE — CRITIQUE
--    UPDATE ... WHERE statut='en_attente' ne matche qu'UNE fois. Un 2e
--    appel (rattrapage, double polling) → FOUND=false → no_op silencieux.
--    Le soutien_upsert interne est lui aussi idempotent (clé unique).
--    En concurrence : l'UPDATE verrouille la ligne → le 2e appelant voit
--    statut='confirme' au COMMIT → no_op. Aucun double comptage possible.
-- ============================================================
CREATE OR REPLACE FUNCTION don_confirmer(
    p_don_id        bigint,
    p_uuid          uuid  DEFAULT NULL,
    p_ref_operateur text  DEFAULT NULL
) RETURNS jsonb
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    v_row record;
BEGIN
    IF p_don_id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'action', 'erreur',
                                  'raison', 'don_id_null');
    END IF;

    -- UPDATE atomique + idempotent : ne matche QUE si en_attente.
    UPDATE don
       SET statut        = 'confirme',
           confirme_le   = now(),
           uuid          = COALESCE(p_uuid, uuid),
           ref_operateur = COALESCE(p_ref_operateur, ref_operateur)
     WHERE id = p_don_id
       AND statut = 'en_attente'
    RETURNING montant, canal, telephone_masque, nom_affiche,
              reference_interne, confirme_le
        INTO v_row;

    IF FOUND THEN
        -- Alimentation du carrousel (idempotent via clé unique reference_don).
        -- Fallback de référence si reference_interne est NULL (dons anciens).
        PERFORM soutien_upsert(
            p_don_id,
            COALESCE(NULLIF(v_row.reference_interne, ''), 'don-' || p_don_id),
            COALESCE(NULLIF(v_row.telephone_masque, ''), '•• •• •• ••'),
            v_row.nom_affiche,
            v_row.canal,
            v_row.montant,
            v_row.confirme_le
        );
        PERFORM journal_ajouter(
            'don_confirme', 'don', p_don_id,
            jsonb_build_object(
                'uuid', COALESCE(p_uuid, (SELECT uuid FROM don WHERE id = p_don_id)),
                'montant', v_row.montant,
                'canal', v_row.canal,
                'ref_operateur', p_ref_operateur));
        RETURN jsonb_build_object('ok', true, 'action', 'confirme',
                                  'don_id', p_don_id);
    END IF;

    -- Pas de match : déjà confirmé ou échoué → no-op silencieux (pas d'erreur).
    RETURN jsonb_build_object('ok', true, 'action', 'no_op',
                              'raison', 'deja_traite', 'don_id', p_don_id);
END $$;


-- ============================================================
--  4d. don_echouer — Marque un don comme échoué (idempotente)
-- ============================================================
--  Rôle        : Marque un don comme 'echoue' (paiement refusé, timeout,
--                annulation). Ne crée JAMAIS de soutien_recus.
--
--  Paramètres  : p_don_id, p_uuid (optionnel), p_raison (optionnelle).
--  Retour      : jsonb — {ok:true, action:'echoue'|'no_op', ...}
--
--  IDEMPOTENCE : même logique que don_confirmer. Si le don était déjà
--                confirmé, don_echouer est un no_op (on ne peut pas
--                échouer un don confirmé).
-- ============================================================
CREATE OR REPLACE FUNCTION don_echouer(
    p_don_id bigint,
    p_uuid   uuid  DEFAULT NULL,
    p_raison text  DEFAULT NULL
) RETURNS jsonb
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    v_montant integer;
    v_canal   text;
BEGIN
    IF p_don_id IS NULL THEN
        RETURN jsonb_build_object('ok', false, 'action', 'erreur',
                                  'raison', 'don_id_null');
    END IF;

    UPDATE don
       SET statut = 'echoue',
           uuid   = COALESCE(p_uuid, uuid)
     WHERE id = p_don_id
       AND statut = 'en_attente'
    RETURNING montant, canal INTO v_montant, v_canal;

    IF FOUND THEN
        PERFORM journal_ajouter(
            'don_echoue', 'don', p_don_id,
            jsonb_build_object(
                'uuid', p_uuid,
                'montant', v_montant,
                'canal', v_canal,
                'raison', p_raison));
        RETURN jsonb_build_object('ok', true, 'action', 'echoue',
                                  'don_id', p_don_id);
    END IF;

    RETURN jsonb_build_object('ok', true, 'action', 'no_op',
                              'raison', 'deja_traite', 'don_id', p_don_id);
END $$;


-- ============================================================
--  4a. don_creer — Création d'un don en_attente
-- ============================================================
--  Rôle        : Crée un don AVANT l'appel à pay_services. Le don est
--                en_attente, sans uuid (attaché plus tard par
--                don_attacher_uuid). Le téléphone est masqué en SQL et
--                stocké en clair pour audit uniquement.
--
--  Paramètres
--    p_montant           integer  — 100 ≤ montant ≤ 2 000 000
--    p_canal             text     — 'OM' | 'WAVE' (normalisé en majuscules)
--    p_telephone         varchar  — E.164 ('+221...') ou national ('77...')
--    p_nom_affiche       text     — nom/pseudo donateur (NULL → 'Anonyme')
--    p_reference_interne text     — réf ≤11 car générée côté PHP
--
--  Retour       : bigint — id du don créé.
--
--  EFFETS       : INSERT don + journal 'don_cree'.
-- ============================================================
CREATE OR REPLACE FUNCTION don_creer(
    p_montant           integer,
    p_canal             text,
    p_telephone         varchar,
    p_nom_affiche       text  DEFAULT NULL,
    p_reference_interne text  DEFAULT NULL
) RETURNS bigint
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    v_id         bigint;
    v_tel_masque text;
    v_canal      text;
BEGIN
    -- Validation montant (100 – 2 000 000 FCFA)
    IF p_montant IS NULL OR p_montant < 100 OR p_montant > 2000000 THEN
        RAISE EXCEPTION 'montant invalide (100-2000000) : %', p_montant
            USING ERRCODE = '22023';  -- invalid_parameter_value
    END IF;

    -- Validation canal (normalisé en majuscules)
    v_canal := upper(trim(p_canal));
    IF v_canal NOT IN ('OM', 'WAVE') THEN
        RAISE EXCEPTION 'canal invalide (OM|WAVE) : %', p_canal
            USING ERRCODE = '22023';
    END IF;

    -- Validation téléphone (E.164 OU national 6-15 chiffres)
    IF p_telephone IS NULL THEN
        RAISE EXCEPTION 'p_telephone est obligatoire'
            USING ERRCODE = '22004';  -- null_value_not_allowed
    END IF;
    IF p_telephone !~ '^\+[0-9]{6,15}$' AND p_telephone !~ '^[0-9]{6,15}$' THEN
        RAISE EXCEPTION 'telephone invalide : %', p_telephone
            USING ERRCODE = '22023';
    END IF;

    -- Masquage SQL du téléphone
    v_tel_masque := numero_mask(p_telephone);

    INSERT INTO don (montant, canal, telephone_masque, telephone_clair,
                     nom_affiche, reference_interne, statut)
    VALUES (p_montant, v_canal, v_tel_masque, p_telephone,
            p_nom_affiche, p_reference_interne, 'en_attente')
    RETURNING id INTO v_id;

    -- Journalisation
    PERFORM journal_ajouter(
        'don_cree', 'don', v_id,
        jsonb_build_object(
            'montant', p_montant,
            'canal', v_canal,
            'reference_interne', p_reference_interne,
            'telephone_masque', v_tel_masque));

    RETURN v_id;
END $$;

COMMIT;

-- ============================================================
--  FIN migration 007
-- ============================================================
