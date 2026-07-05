-- ============================================================
--  MIGRATION 001 — Fonctions OTP (chantier J1 — JAK 2026)
-- ============================================================
--  Auteur   : dba_master (Expert DBA PostgreSQL)
--  Date     : 2026-07-04
--  Objet    : Création des fonctions PL/pgSQL otp_creer() et
--             otp_verifier() pour la table otp_defi existante.
--  Réf.     : Sémantique reproduite de l'OTP V1 PHP (api/lib/otp.php)
--             + docs/superpowers/specs/2026-07-03-auth-otp-admin-membre-design.md
--
--  CARACTÉRISTIQUES
--    • Rejouable : CREATE OR REPLACE FUNCTION (idempotent).
--    • Sécurité  : SECURITY INVOKER (défaut) + LANGUAGE plpgsql.
--    • Non-cassant : ne touche ni aux 28 fonctions existantes,
--                    ni au schéma de otp_defi.
--
--  PRÉREQUIS : table otp_defi + type enum utilisateur_role déjà créés
--              (cf. db/schema.sql). Cette migration ne les recrée pas.
-- ============================================================

BEGIN;

-- ============================================================
--  Index de recherche (idempotents via CREATE INDEX IF NOT EXISTS)
--  - par téléphone (sélection du défi le plus récent côté PHP)
--  - nettoyage périodique des défis expirés/consommés
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_otp_defi_telephone
    ON otp_defi (telephone, consomme, expire_le DESC)
    WHERE telephone IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_otp_defi_expire_le
    ON otp_defi (expire_le);


-- ============================================================
--  otp_creer — Création d'un défi OTP
-- ============================================================
--  Rôle        : Insère un nouveau défi OTP (hash HMAC-SHA256 du code,
--                JAMAIS le code en clair) dans la table otp_defi.
--
--  Paramètres
--    p_role         utilisateur_role  — 'admin' | 'editeur' | 'membre'
--    p_telephone    varchar           — n° au format E.164 '+<6-15 chiffres>'
--                                       (NULL pour admin/leurre). Ex. '+221REDACTED'.
--    p_personne_id  bigint            — FK personne (NULL pour admin/leurre)
--    p_code_hash    text              — HMAC-SHA256 du code (calculé côté PHP)
--    p_ttl_sec      integer (déf.300) — durée de validité en secondes
--
--  Retour       : bigint — id de la ligne créée.
--
--  CHOIX DE CONCEPTION — Gestion des multi-défis
--    La spécification autorise deux approches : (a) permettre plusieurs
--    défis non consommés simultanés pour un même téléphone, ou (b)
--    invalider les précédents. Nous choisissons (b) — INVALIDATION —
--    pour les raisons suivantes :
--      1. Fidélité à la sémantique OTP V1 (api/lib/otp.php) où
--         otp_set_challenge() écrase la session → un seul défi actif.
--      2. Sécurité : empêche le rejeu d'un ancien code encore valide.
--      3. Propreté : pas d'accumulation de lignes jouables.
--    L'invalidation marque consomme=true sur les défis non consommés
--    du MÊME téléphone (uniquement si p_telephone est non NULL ; les
--    défis admin/leurre à téléphone NULL ne sont pas invalidés, faute
--    de clé de regroupement fiable — ils sont consommés à la vérif).
--    L'invalidation et l'insertion sont atomiques (même transaction).
-- ============================================================
CREATE OR REPLACE FUNCTION otp_creer(
    p_role         utilisateur_role,
    p_telephone    varchar  DEFAULT NULL,
    p_personne_id  bigint   DEFAULT NULL,
    p_code_hash    text     DEFAULT NULL,
    p_ttl_sec      integer  DEFAULT 300
) RETURNS bigint
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    v_id bigint;
BEGIN
    -- p_code_hash est OBLIGATOIRE (logique) : PostgreSQL exige une valeur
    -- par défaut pour tout paramètre situé après un paramètre optionnel.
    -- On garde donc DEFAULT NULL puis on valide ici l'intention réelle.
    IF p_code_hash IS NULL OR p_code_hash = '' THEN
        RAISE EXCEPTION 'p_code_hash est obligatoire'
            USING ERRCODE = '22004';  -- null_value_not_allowed
    END IF;

    -- Validation du téléphone au format international E.164 (+ suivi de 6 à 15
    -- chiffres), ex. '+221REDACTED'. Les leurres anti-énumération passent
    -- toujours un vrai numéro (celui soumis), donc ce format est respecté y
    -- compris pour les non-membres. Le format '9 chiffres nus' est désormais
    -- REJETÉ : les téléphones doivent être normalisés en base (migration 002)
    -- et côté applicatif (PHP) AVANT l'appel.
    IF p_telephone IS NOT NULL AND p_telephone !~ '^\+[0-9]{6,15}$' THEN
        RAISE EXCEPTION 'telephone invalide : format E.164 attendu (+<6-15 chiffres>, ex. +221REDACTED) (>%<)', p_telephone
            USING ERRCODE = '22023';  -- invalid_parameter_value
    END IF;

    -- Garde-fou : TTL strictement positif.
    IF p_ttl_sec IS NULL OR p_ttl_sec <= 0 THEN
        p_ttl_sec := 300;
    END IF;

    -- Invalidation atomique des défis précédents non consommés du même
    -- téléphone (reproduit otp_set_challenge V1 : un seul défi actif).
    IF p_telephone IS NOT NULL THEN
        UPDATE otp_defi
           SET consomme = true
         WHERE telephone = p_telephone
           AND consomme = false;
    END IF;

    -- Insertion du nouveau défi.
    INSERT INTO otp_defi (role, telephone, personne_id, code_hash,
                          expire_le, tentatives, derniere_envoi, consomme)
    VALUES (p_role, p_telephone, p_personne_id, p_code_hash,
            now() + make_interval(secs => p_ttl_sec), 0, now(), false)
    RETURNING id INTO v_id;

    RETURN v_id;
END;
$$;

COMMENT ON FUNCTION otp_creer(utilisateur_role, varchar, bigint, text, integer)
    IS 'J1 — Crée un défi OTP (hash HMAC côté PHP) et invalide les précédents du même téléphone (format E.164 +<6-15 chiffres>). Retourne l''id du défi créé.';


-- ============================================================
--  otp_verifier — Vérification d'un défi OTP
-- ============================================================
--  Rôle        : Vérifie un code OTP soumis contre le hash stocké, gère
--                l'expiration, le verrouillage et l'incrément de tentatives.
--
--  ⚠ SÉCURITÉ — Le hash du code soumis est calculé côté PHP (qui détient
--    le secret HMAC otp_secret dans config.php). La fonction SQL reçoit
--    DONC DÉJÀ LES DEUX HASHES (stocké en base vs soumis en paramètre).
--    NE JAMAIS recevoir le code en clair côté SQL.
--
--  Paramètres
--    p_otp_id          bigint            — id du défi à vérifier
--    p_code_hash       text              — HMAC-SHA256 du code SOUMIS
--    p_max_tentatives  smallint (déf.5)  — seuil de verrouillage
--
--  Retour  : jsonb
--    { "ok": true,  "raison": "ok",                 "role": <r>, "personne_id": <id> }  — succès
--    { "ok": false, "raison": "none"  | "expired" | "locked" | "bad",
--      "role": null, "personne_id": null }                                          — échec
--
--  CHOIX DE CONCEPTION
--    1. Comparaison de hashes : PostgreSQL n'a pas de hash_equals()
--       constant-time natif. On utilise une simple égalité '='. Les
--       hashes HMAC-SHA256 sont des chaînes hexadécimales de longueur
--       FIXE (64 caractères) : la fuite de timing théorique est
--       négligeable (comparaison de buffers de même taille en C, et le
--       branchement final ne dépend que de l'égalité, pas du hash). La
--       comparaison constant-time réelle est assurée côté PHP
--       par hash_equals() dans la logique applicative de repli.
--    2. Borner p_max_tentatives : la table impose CHECK (tentatives <= 5).
--       On borne donc p_max_tentatives à min(p_max_tentatives, 5) afin
--       qu'aucun incrément ne puisse violer la contrainte (tentatives
--       ne dépasse jamais 5, l'incrément n'ayant lieu que sous le seuil).
--    3. Atomicité : l'incrément de tentatives se fait par UPDATE ...
--       RETURNING avec une clause de garde (consomme = false) qui
--       protège contre la concurrence (un défi consommé entre-temps
--       n'est pas incrémenté → raison 'none').
--    4. Invalidation après échec terminal (V1 vide $sess = []) :
--       expiration, verrouillage et succès marquent tous consomme=true
--       pour empêcher tout rejeu. Sur simple code faux sous le seuil,
--       le défi reste vivant (tentatives++ uniquement).
-- ============================================================
CREATE OR REPLACE FUNCTION otp_verifier(
    p_otp_id         bigint,
    p_code_hash      text,
    p_max_tentatives smallint DEFAULT 5
) RETURNS jsonb
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    d                  RECORD;       -- défi courant (lecture)
    v_tent             smallint;     -- tentatives après incrément
    v_hash_stocke      text;
    v_role             utilisateur_role;
    v_pid              bigint;
    v_max              smallint := LEAST(p_max_tentatives, 5);  -- borne CHECK (<=5)
BEGIN
    -- 1. Chargement du défi (lecture).
    SELECT expire_le, tentatives, consomme, code_hash, role, personne_id
      INTO d
      FROM otp_defi
     WHERE id = p_otp_id;

    -- 2. Introuvable ou déjà consommé → 'none'.
    IF NOT FOUND OR d.consomme THEN
        RETURN jsonb_build_object('ok', false, 'raison', 'none',
                                  'role', null, 'personne_id', null);
    END IF;

    -- 3. Expiration (V1 : now > expire → 'expired' + sess=[]).
    IF d.expire_le <= now() THEN
        UPDATE otp_defi SET consomme = true WHERE id = p_otp_id;
        RETURN jsonb_build_object('ok', false, 'raison', 'expired',
                                  'role', null, 'personne_id', null);
    END IF;

    -- 4. Verrouillage déjà atteint (V1 : attempts >= max → 'locked' + sess=[]).
    IF d.tentatives >= v_max THEN
        UPDATE otp_defi SET consomme = true WHERE id = p_otp_id;
        RETURN jsonb_build_object('ok', false, 'raison', 'locked',
                                  'role', null, 'personne_id', null);
    END IF;

    -- 5. Incrément atomique de tentatives avec garde anti-concurrence.
    UPDATE otp_defi
       SET tentatives = tentatives + 1
     WHERE id = p_otp_id
       AND consomme = false
    RETURNING tentatives, code_hash, role, personne_id
       INTO v_tent, v_hash_stocke, v_role, v_pid;

    -- Concurrence : défi consommé entre le SELECT et l'UPDATE.
    IF NOT FOUND THEN
        RETURN jsonb_build_object('ok', false, 'raison', 'none',
                                  'role', null, 'personne_id', null);
    END IF;

    -- 6. Comparaison des hashes (cf. note sur constant-time ci-dessus).
    IF v_hash_stocke = p_code_hash THEN
        UPDATE otp_defi SET consomme = true WHERE id = p_otp_id;
        RETURN jsonb_build_object('ok', true, 'raison', 'ok',
                                  'role', v_role, 'personne_id', v_pid);
    END IF;

    -- 7. Code faux : verrouillage atteint après cet incrément → 'locked'.
    IF v_tent >= v_max THEN
        UPDATE otp_defi SET consomme = true WHERE id = p_otp_id;
        RETURN jsonb_build_object('ok', false, 'raison', 'locked',
                                  'role', null, 'personne_id', null);
    END IF;

    -- 8. Sinon : code faux, défi encore vivant → 'bad'.
    RETURN jsonb_build_object('ok', false, 'raison', 'bad',
                              'role', null, 'personne_id', null);
END;
$$;

COMMENT ON FUNCTION otp_verifier(bigint, text, smallint)
    IS 'J1 — Vérifie un défi OTP (reçoit les deux hashes HMAC, jamais le code clair). Retourne jsonb {ok, raison, role, personne_id}.';

COMMIT;

-- ============================================================
--  FIN — Vérification rapide (format E.164 obligatoire) :
--    SELECT otp_creer('membre','+221770000000',1,'abc',300);  -- OK
--    SELECT otp_creer('membre','770000000',1,'abc',300);      -- REJETE
--    SELECT otp_verifier(<id>, 'abc');
-- ============================================================
