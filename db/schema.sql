-- ============================================================
--  JAK 2026 — Schéma PostgreSQL
--  Remplace la gestion JSON (data.js / data.json) de jak.sn
--  Cible : servir un JSON strictement conforme à window.SITE_DATA
--
--  Application :  psql -d jak2026 -f db/schema.sql
--  Puis seed :    psql -d jak2026 -f db/seed.sql
--  Vérification : SELECT jsonb_pretty(site_data_json());
-- ============================================================

BEGIN;

-- ---------- Types énumérés ----------
CREATE TYPE personne_categorie AS ENUM ('dignitaire', 'membre');
CREATE TYPE utilisateur_role    AS ENUM ('admin', 'editeur', 'membre');
CREATE TYPE don_statut          AS ENUM ('en_attente', 'confirme', 'echoue', 'rembourse');

-- ============================================================
--  ÉVÉNEMENT / RÉGLAGES (singleton courant)
-- ============================================================
CREATE TABLE evenement (
  id                   smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  est_courant          boolean     NOT NULL DEFAULT true,
  event_nom            text        NOT NULL,
  organisateur         text        NOT NULL,
  lieu                 text        NOT NULL,
  dates_texte          text        NOT NULL,
  date_compte_rebours  timestamptz NOT NULL,
  note_soutien         text        NOT NULL DEFAULT '',
  jak_presentation     text        NOT NULL DEFAULT '',
  cree_le              timestamptz NOT NULL DEFAULT now(),
  modifie_le           timestamptz NOT NULL DEFAULT now()
);
-- Un seul événement courant à la fois
CREATE UNIQUE INDEX uniq_evenement_courant ON evenement (est_courant) WHERE est_courant;

-- Jours du programme (ex : Mercredi 16, Jeudi 17, Vendredi 18)
CREATE TABLE programme_jour (
  id            smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  evenement_id  smallint    NOT NULL REFERENCES evenement(id) ON DELETE CASCADE,
  ordre         smallint    NOT NULL DEFAULT 0,
  jour          text        NOT NULL,        -- "Mercredi 16 Juillet"
  titre         text        NOT NULL DEFAULT '',
  description   text        NOT NULL DEFAULT '',   -- rendu dans le JSON sous la clé "desc"
  UNIQUE (evenement_id, ordre)
);

-- Numéros officiels (Wave + OM + WhatsApp partagent le même numéro)
CREATE TABLE numero_contact (
  id            smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  evenement_id  smallint    NOT NULL REFERENCES evenement(id) ON DELETE CASCADE,
  ordre         smallint    NOT NULL DEFAULT 0,
  numero        text        NOT NULL,        -- "77 458 52 61"
  UNIQUE (evenement_id, ordre)
);

-- ============================================================
--  LE CHEIKH (singleton global, id = 1)
-- ============================================================
CREATE TABLE cheikh (
  id            smallint    PRIMARY KEY DEFAULT 1 CHECK (id = 1),
  nom_complet   text        NOT NULL,
  url_photo     text        NOT NULL DEFAULT '',
  biographie    text        NOT NULL DEFAULT '',
  adresse       text        NOT NULL DEFAULT '',
  cree_le       timestamptz NOT NULL DEFAULT now(),
  modifie_le    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE cheikh_citation (
  id          integer     GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  cheikh_id   smallint    NOT NULL DEFAULT 1 REFERENCES cheikh(id) ON DELETE CASCADE,
  ordre       integer     NOT NULL DEFAULT 0,
  texte       text        NOT NULL,
  UNIQUE (cheikh_id, ordre)
);

-- ============================================================
--  PERSONNES (dignitaires + membres JAK unifiés)
-- ============================================================
CREATE TABLE personne (
  id            bigserial   PRIMARY KEY,
  evenement_id  smallint    NOT NULL DEFAULT 1 REFERENCES evenement(id) ON DELETE CASCADE,
  categorie     personne_categorie NOT NULL,
  -- Membres seulement : identifiant stable (URL / login) + téléphone de connexion
  slug          text,                          -- ex "m-ablaye-diop"
  telephone     varchar(9),                    -- 9 chiffres SN
  nom_complet   text        NOT NULL DEFAULT '',
  url_photo     text        NOT NULL DEFAULT '',
  biographie    text        NOT NULL DEFAULT '',
  adresse       text        NOT NULL DEFAULT '',
  -- Membres seulement
  fondateur     boolean     NOT NULL DEFAULT false,
  fonction      text        NOT NULL DEFAULT '',
  ordre         integer     NOT NULL DEFAULT 0,
  cree_le       timestamptz NOT NULL DEFAULT now(),
  modifie_le    timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uniq_personne_slug       ON personne (slug)      WHERE slug IS NOT NULL;
CREATE UNIQUE INDEX uniq_personne_telephone  ON personne (telephone) WHERE telephone IS NOT NULL;
CREATE INDEX idx_personne_cat_evt            ON personne (categorie, evenement_id);

-- ============================================================
--  GALERIE
-- ============================================================
CREATE TABLE galerie_photo (
  id            bigserial   PRIMARY KEY,
  evenement_id  smallint    NOT NULL DEFAULT 1 REFERENCES evenement(id) ON DELETE CASCADE,
  ordre         integer     NOT NULL DEFAULT 0,
  url_photo     text        NOT NULL,          -- écran 1080px
  url_thumb     text,                           -- vignette 320px (Lot 3, rétro-compatible)
  legende       text        NOT NULL DEFAULT '',
  cree_le       timestamptz NOT NULL DEFAULT now(),
  modifie_le    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (evenement_id, ordre)
);

-- ============================================================
--  AUTHENTIFICATION (Lot 1 — OTP)
-- ============================================================
CREATE TABLE utilisateur (
  id            bigserial   PRIMARY KEY,
  role          utilisateur_role NOT NULL DEFAULT 'membre',
  telephone     varchar(9)  UNIQUE,            -- identifiant de connexion
  email         text        UNIQUE,
  personne_id   bigint      REFERENCES personne(id) ON DELETE SET NULL,  -- membre → SA fiche
  actif         boolean     NOT NULL DEFAULT true,
  cree_le       timestamptz NOT NULL DEFAULT now(),
  derniere_connexion timestamptz
);

-- Défis OTP (audit + multi-instance ; relai/alternative des sessions PHP)
CREATE TABLE otp_defi (
  id            bigserial   PRIMARY KEY,
  role          utilisateur_role NOT NULL,
  telephone     varchar(9),
  personne_id   bigint      REFERENCES personne(id) ON DELETE CASCADE,
  code_hash     text        NOT NULL,           -- HMAC-SHA256 du code (jamais en clair)
  expire_le     timestamptz NOT NULL,
  tentatives    smallint    NOT NULL DEFAULT 0 CHECK (tentatives <= 5),
  derniere_envoi timestamptz NOT NULL DEFAULT now(),
  consomme      boolean     NOT NULL DEFAULT false
);

-- ============================================================
--  VERSIONING (Lot 2) + DONS (Lot 4)
-- ============================================================
CREATE TABLE revision (
  id              bigserial   PRIMARY KEY,
  table_cible     text        NOT NULL,
  enregistrement_id bigint    NOT NULL,
  auteur_id       bigint      REFERENCES utilisateur(id) ON DELETE SET NULL,
  avant           jsonb,
  apres           jsonb,
  modifie_le      timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE don (
  id              bigserial   PRIMARY KEY,
  montant         integer     NOT NULL CHECK (montant > 0),
  canal           text        NOT NULL,         -- 'wave' | 'orange_money'
  ref_operateur   text,
  telephone_masque text,
  statut          don_statut  NOT NULL DEFAULT 'en_attente',
  cree_le         timestamptz NOT NULL DEFAULT now(),
  confirme_le     timestamptz
);

COMMIT;

-- ============================================================
--  TRIGGER : modifie_le automatique
-- ============================================================
CREATE OR REPLACE FUNCTION touch_modifie_le() RETURNS trigger AS $$
BEGIN NEW.modifie_le := now(); RETURN NEW; END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_evenement   BEFORE UPDATE ON evenement
  FOR EACH ROW EXECUTE FUNCTION touch_modifie_le();
CREATE TRIGGER trg_cheikh      BEFORE UPDATE ON cheikh
  FOR EACH ROW EXECUTE FUNCTION touch_modifie_le();
CREATE TRIGGER trg_personne    BEFORE UPDATE ON personne
  FOR EACH ROW EXECUTE FUNCTION touch_modifie_le();
CREATE TRIGGER trg_galerie     BEFORE UPDATE ON galerie_photo
  FOR EACH ROW EXECUTE FUNCTION touch_modifie_le();

-- ============================================================
--  FONCTION D'AGRÉGATION — pont vers window.SITE_DATA
--  SELECT jsonb_pretty(site_data_json());
-- ============================================================
CREATE OR REPLACE FUNCTION site_data_json(p_evenement_id smallint DEFAULT 1)
RETURNS jsonb
LANGUAGE sql STABLE AS $$
  SELECT jsonb_build_object(
    'settings', (
      SELECT jsonb_build_object(
        'event_nom',          e.event_nom,
        'organisateur',       e.organisateur,
        'lieu',               e.lieu,
        'dates_texte',        e.dates_texte,
        'date_compte_rebours', to_char(e.date_compte_rebours AT TIME ZONE 'Africa/Dakar',
                                        'YYYY-MM-DD"T"HH24:MI:SS'),
        'whatsapp',  COALESCE((SELECT jsonb_agg(n.numero ORDER BY n.ordre)
                               FROM numero_contact n WHERE n.evenement_id = e.id), '[]'::jsonb),
        'note_soutien',       e.note_soutien,
        'jak_presentation',   e.jak_presentation,
        'programme', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                                  'jour', p.jour, 'titre', p.titre, 'desc', p.description)
                                ORDER BY p.ordre)
                               FROM programme_jour p WHERE p.evenement_id = e.id), '[]'::jsonb)
      ) FROM evenement e WHERE e.id = p_evenement_id),
    'cheikh', (
      SELECT jsonb_build_object(
        'photo',       c.url_photo,
        'nom_complet', c.nom_complet,
        'biographie',  c.biographie,
        'adresse',     c.adresse,
        'citations',   COALESCE((SELECT jsonb_agg(ci.texte ORDER BY ci.ordre)
                                 FROM cheikh_citation ci WHERE ci.cheikh_id = c.id), '[]'::jsonb)
      ) FROM cheikh c WHERE c.id = 1),
    'dignitaires', COALESCE((
      SELECT jsonb_agg(jsonb_build_object(
        'photo', p.url_photo, 'nom_complet', p.nom_complet,
        'biographie', p.biographie, 'adresse', p.adresse)
        ORDER BY p.ordre)
      FROM personne p
      WHERE p.categorie = 'dignitaire' AND p.evenement_id = p_evenement_id), '[]'::jsonb),
    'jak', COALESCE((
      SELECT jsonb_agg(jsonb_build_object(
        'photo', p.url_photo, 'nom_complet', p.nom_complet,
        'biographie', p.biographie, 'adresse', p.adresse,
        'fondateur', CASE WHEN p.fondateur THEN 'OUI' ELSE 'NON' END,
        'fonction',  p.fonction,
        'id',        COALESCE(p.slug, ''),
        'telephone', COALESCE(p.telephone, ''))
        ORDER BY p.fondateur DESC, p.ordre)
      FROM personne p
      WHERE p.categorie = 'membre' AND p.evenement_id = p_evenement_id), '[]'::jsonb),
    'galerie', COALESCE((
      SELECT jsonb_agg(jsonb_build_object(
        'src', g.url_photo, 'legende', g.legende)
        ORDER BY g.ordre)
      FROM galerie_photo g WHERE g.evenement_id = p_evenement_id), '[]'::jsonb)
  );
$$;

-- ============================================================
--  VUES UTILITAIRES
-- ============================================================
CREATE VIEW v_dignitaire AS
  SELECT * FROM personne WHERE categorie = 'dignitaire' ORDER BY ordre;

CREATE VIEW v_membre AS
  SELECT * FROM personne WHERE categorie = 'membre' ORDER BY fondateur DESC, ordre;

CREATE VIEW v_membre_fondateur AS
  SELECT * FROM personne WHERE categorie = 'membre' AND fondateur ORDER BY ordre;

CREATE VIEW v_site_data AS
  SELECT site_data_json() AS donnees;

-- ============================================================
--  CRUD — PERSONNE
-- ============================================================

-- ---------- CREATE ----------
CREATE OR REPLACE FUNCTION personne_creer(
  p_categorie    personne_categorie,
  p_nom_complet  text,
  p_url_photo    text     DEFAULT '',
  p_biographie   text     DEFAULT '',
  p_adresse      text     DEFAULT '',
  p_fondateur    boolean  DEFAULT false,
  p_fonction     text     DEFAULT '',
  p_slug         text     DEFAULT NULL,
  p_telephone    varchar  DEFAULT NULL,
  p_evenement_id smallint DEFAULT 1
) RETURNS personne LANGUAGE plpgsql AS $$
DECLARE v personne; v_ordre integer;
BEGIN
  IF p_categorie = 'membre' AND p_slug IS NULL THEN
    RAISE EXCEPTION 'slug requis pour un membre';
  END IF;
  IF p_telephone IS NOT NULL AND p_telephone !~ '^[0-9]{9}$' THEN
    RAISE EXCEPTION 'telephone invalide : 9 chiffres attendus';
  END IF;
  SELECT COALESCE(MAX(ordre), 0) + 1 INTO v_ordre FROM personne
   WHERE categorie = p_categorie AND evenement_id = p_evenement_id;
  INSERT INTO personne (categorie, slug, telephone, nom_complet, url_photo, biographie,
                        adresse, fondateur, fonction, ordre, evenement_id)
  VALUES (p_categorie, p_slug, p_telephone, p_nom_complet, p_url_photo, p_biographie,
          p_adresse, p_fondateur, p_fonction, v_ordre, p_evenement_id)
  RETURNING * INTO v;
  RETURN v;
END $$;

-- ---------- READ ----------
CREATE OR REPLACE FUNCTION personne_lire(p_id bigint)
RETURNS personne LANGUAGE sql STABLE AS $$
  SELECT * FROM personne WHERE id = p_id;
$$;

CREATE OR REPLACE FUNCTION personne_par_telephone(p_tel varchar)
RETURNS personne LANGUAGE sql STABLE AS $$
  SELECT * FROM personne WHERE categorie = 'membre' AND telephone = p_tel;
$$;

CREATE OR REPLACE FUNCTION personne_lister(
  p_cat          personne_categorie DEFAULT NULL,
  p_evenement_id smallint            DEFAULT 1
) RETURNS SETOF personne LANGUAGE sql STABLE AS $$
  SELECT * FROM personne
   WHERE (p_cat IS NULL OR categorie = p_cat)
     AND evenement_id = p_evenement_id
   ORDER BY fondateur DESC, ordre;
$$;

-- ---------- UPDATE (partielle : NULL = conserver) ----------
CREATE OR REPLACE FUNCTION personne_modifier(
  p_id           bigint,
  p_nom_complet  text     DEFAULT NULL,
  p_url_photo    text     DEFAULT NULL,
  p_biographie   text     DEFAULT NULL,
  p_adresse      text     DEFAULT NULL,
  p_fondateur    boolean  DEFAULT NULL,
  p_fonction     text     DEFAULT NULL,
  p_slug         text     DEFAULT NULL,
  p_telephone    varchar  DEFAULT NULL,
  p_ordre        integer  DEFAULT NULL
) RETURNS personne LANGUAGE plpgsql AS $$
DECLARE v personne;
BEGIN
  IF p_telephone IS NOT NULL AND p_telephone !~ '^[0-9]{9}$' THEN
    RAISE EXCEPTION 'telephone invalide : 9 chiffres attendus';
  END IF;
  UPDATE personne SET
    nom_complet = COALESCE(p_nom_complet, nom_complet),
    url_photo   = COALESCE(p_url_photo,   url_photo),
    biographie  = COALESCE(p_biographie,  biographie),
    adresse     = COALESCE(p_adresse,     adresse),
    fondateur   = COALESCE(p_fondateur,   fondateur),
    fonction    = COALESCE(p_fonction,    fonction),
    slug        = COALESCE(p_slug,        slug),
    telephone   = COALESCE(p_telephone,   telephone),
    ordre       = COALESCE(p_ordre,       ordre)
  WHERE id = p_id
  RETURNING * INTO v;
  IF NOT FOUND THEN RAISE EXCEPTION 'personne % introuvable', p_id; END IF;
  RETURN v;
END $$;

-- ---------- DELETE ----------
CREATE OR REPLACE FUNCTION personne_supprimer(p_id bigint)
RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  DELETE FROM personne WHERE id = p_id;
  RETURN FOUND;
END $$;

-- ---------- UPDATE cloisonné (membre édite SA fiche — Lot 1) ----------
CREATE OR REPLACE FUNCTION membre_sauver_fiche(
  p_tel         varchar,
  p_nom_complet text DEFAULT NULL,
  p_url_photo   text DEFAULT NULL,
  p_adresse     text DEFAULT NULL,
  p_biographie  text DEFAULT NULL
) RETURNS personne LANGUAGE plpgsql AS $$
DECLARE v personne;
BEGIN
  UPDATE personne SET
    nom_complet = COALESCE(p_nom_complet, nom_complet),
    url_photo   = COALESCE(p_url_photo,   url_photo),
    adresse     = COALESCE(p_adresse,     adresse),
    biographie  = COALESCE(p_biographie,  biographie)
  WHERE categorie = 'membre' AND telephone = p_tel
  RETURNING * INTO v;        -- cloisonné : ne touche QUE la fiche du numéro
  IF NOT FOUND THEN RAISE EXCEPTION 'membre introuvable pour ce telephone'; END IF;
  RETURN v;
END $$;

-- ============================================================
--  CRUD — GALERIE_PHOTO
-- ============================================================
CREATE OR REPLACE FUNCTION galerie_creer(
  p_url_photo    text,
  p_legende      text     DEFAULT '',
  p_url_thumb    text     DEFAULT NULL,
  p_ordre        integer  DEFAULT NULL,
  p_evenement_id smallint DEFAULT 1
) RETURNS galerie_photo LANGUAGE plpgsql AS $$
DECLARE v galerie_photo; v_ordre integer;
BEGIN
  IF p_ordre IS NULL THEN
    SELECT COALESCE(MAX(ordre), 0) + 1 INTO v_ordre FROM galerie_photo WHERE evenement_id = p_evenement_id;
  ELSE v_ordre := p_ordre; END IF;
  INSERT INTO galerie_photo (url_photo, url_thumb, legende, ordre, evenement_id)
  VALUES (p_url_photo, p_url_thumb, p_legende, v_ordre, p_evenement_id)
  RETURNING * INTO v;
  RETURN v;
END $$;

CREATE OR REPLACE FUNCTION galerie_lire(p_id bigint)
RETURNS galerie_photo LANGUAGE sql STABLE AS $$
  SELECT * FROM galerie_photo WHERE id = p_id;
$$;

CREATE OR REPLACE FUNCTION galerie_lister(p_evenement_id smallint DEFAULT 1)
RETURNS SETOF galerie_photo LANGUAGE sql STABLE AS $$
  SELECT * FROM galerie_photo WHERE evenement_id = p_evenement_id ORDER BY ordre;
$$;

CREATE OR REPLACE FUNCTION galerie_modifier(
  p_id        bigint,
  p_url_photo text    DEFAULT NULL,
  p_legende   text    DEFAULT NULL,
  p_url_thumb text    DEFAULT NULL,
  p_ordre     integer DEFAULT NULL
) RETURNS galerie_photo LANGUAGE plpgsql AS $$
DECLARE v galerie_photo;
BEGIN
  UPDATE galerie_photo SET
    url_photo = COALESCE(p_url_photo, url_photo),
    legende   = COALESCE(p_legende,   legende),
    url_thumb = COALESCE(p_url_thumb, url_thumb),
    ordre     = COALESCE(p_ordre,     ordre)
  WHERE id = p_id
  RETURNING * INTO v;
  IF NOT FOUND THEN RAISE EXCEPTION 'galerie_photo % introuvable', p_id; END IF;
  RETURN v;
END $$;

CREATE OR REPLACE FUNCTION galerie_supprimer(p_id bigint)
RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  DELETE FROM galerie_photo WHERE id = p_id;
  RETURN FOUND;
END $$;

-- ============================================================
--  CRUD — CHEIKH / CHEIKH_CITATION
-- ============================================================
CREATE OR REPLACE FUNCTION cheikh_lire()
RETURNS cheikh LANGUAGE sql STABLE AS $$
  SELECT * FROM cheikh WHERE id = 1;
$$;

CREATE OR REPLACE FUNCTION cheikh_modifier(
  p_nom_complet text DEFAULT NULL,
  p_url_photo   text DEFAULT NULL,
  p_biographie  text DEFAULT NULL,
  p_adresse     text DEFAULT NULL
) RETURNS cheikh LANGUAGE plpgsql AS $$
DECLARE v cheikh;
BEGIN
  UPDATE cheikh SET
    nom_complet = COALESCE(p_nom_complet, nom_complet),
    url_photo   = COALESCE(p_url_photo,   url_photo),
    biographie  = COALESCE(p_biographie,  biographie),
    adresse     = COALESCE(p_adresse,     adresse)
  WHERE id = 1
  RETURNING * INTO v;
  IF NOT FOUND THEN RAISE EXCEPTION 'cheikh singleton introuvable'; END IF;
  RETURN v;
END $$;

CREATE OR REPLACE FUNCTION cheikh_citation_ajouter(p_texte text)
RETURNS cheikh_citation LANGUAGE plpgsql AS $$
DECLARE v cheikh_citation; v_ordre integer;
BEGIN
  SELECT COALESCE(MAX(ordre), 0) + 1 INTO v_ordre FROM cheikh_citation WHERE cheikh_id = 1;
  INSERT INTO cheikh_citation (cheikh_id, ordre, texte) VALUES (1, v_ordre, p_texte)
  RETURNING * INTO v;
  RETURN v;
END $$;

CREATE OR REPLACE FUNCTION cheikh_citation_modifier(p_id integer, p_texte text)
RETURNS cheikh_citation LANGUAGE plpgsql AS $$
DECLARE v cheikh_citation;
BEGIN
  UPDATE cheikh_citation SET texte = p_texte WHERE id = p_id
  RETURNING * INTO v;
  IF NOT FOUND THEN RAISE EXCEPTION 'citation % introuvable', p_id; END IF;
  RETURN v;
END $$;

CREATE OR REPLACE FUNCTION cheikh_citation_supprimer(p_id integer)
RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  DELETE FROM cheikh_citation WHERE id = p_id;
  RETURN FOUND;
END $$;

-- ============================================================
--  CRUD — ÉVÉNEMENT / PROGRAMME_JOUR / NUMERO_CONTACT
-- ============================================================
CREATE OR REPLACE FUNCTION evenement_lire(p_id smallint DEFAULT 1)
RETURNS evenement LANGUAGE sql STABLE AS $$
  SELECT * FROM evenement WHERE id = p_id;
$$;

CREATE OR REPLACE FUNCTION evenement_lire_courant()
RETURNS evenement LANGUAGE sql STABLE AS $$
  SELECT * FROM evenement WHERE est_courant ORDER BY id LIMIT 1;
$$;

CREATE OR REPLACE FUNCTION evenement_modifier(
  p_id                   smallint DEFAULT 1,
  p_event_nom            text DEFAULT NULL,
  p_organisateur         text DEFAULT NULL,
  p_lieu                 text DEFAULT NULL,
  p_dates_texte          text DEFAULT NULL,
  p_date_compte_rebours  timestamptz DEFAULT NULL,
  p_note_soutien         text DEFAULT NULL,
  p_jak_presentation     text DEFAULT NULL,
  p_est_courant          boolean DEFAULT NULL
) RETURNS evenement LANGUAGE plpgsql AS $$
DECLARE v evenement;
BEGIN
  UPDATE evenement SET
    event_nom           = COALESCE(p_event_nom,           event_nom),
    organisateur        = COALESCE(p_organisateur,        organisateur),
    lieu                = COALESCE(p_lieu,                lieu),
    dates_texte         = COALESCE(p_dates_texte,         dates_texte),
    date_compte_rebours = COALESCE(p_date_compte_rebours, date_compte_rebours),
    note_soutien        = COALESCE(p_note_soutien,        note_soutien),
    jak_presentation    = COALESCE(p_jak_presentation,    jak_presentation),
    est_courant         = COALESCE(p_est_courant,         est_courant)
  WHERE id = p_id
  RETURNING * INTO v;
  IF NOT FOUND THEN RAISE EXCEPTION 'evenement % introuvable', p_id; END IF;
  RETURN v;
END $$;

CREATE OR REPLACE FUNCTION programme_jour_creer(
  p_jour         text,
  p_titre        text DEFAULT '',
  p_description  text DEFAULT '',
  p_evenement_id smallint DEFAULT 1
) RETURNS programme_jour LANGUAGE plpgsql AS $$
DECLARE v programme_jour; v_ordre integer;
BEGIN
  SELECT COALESCE(MAX(ordre), 0) + 1 INTO v_ordre FROM programme_jour WHERE evenement_id = p_evenement_id;
  INSERT INTO programme_jour (evenement_id, ordre, jour, titre, description)
  VALUES (p_evenement_id, v_ordre, p_jour, p_titre, p_description)
  RETURNING * INTO v;
  RETURN v;
END $$;

CREATE OR REPLACE FUNCTION programme_jour_modifier(
  p_id          smallint,
  p_jour        text DEFAULT NULL,
  p_titre       text DEFAULT NULL,
  p_description text DEFAULT NULL
) RETURNS programme_jour LANGUAGE plpgsql AS $$
DECLARE v programme_jour;
BEGIN
  UPDATE programme_jour SET
    jour        = COALESCE(p_jour, jour),
    titre       = COALESCE(p_titre, titre),
    description = COALESCE(p_description, description)
  WHERE id = p_id
  RETURNING * INTO v;
  IF NOT FOUND THEN RAISE EXCEPTION 'programme_jour % introuvable', p_id; END IF;
  RETURN v;
END $$;

CREATE OR REPLACE FUNCTION programme_jour_supprimer(p_id smallint)
RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  DELETE FROM programme_jour WHERE id = p_id;
  RETURN FOUND;
END $$;

CREATE OR REPLACE FUNCTION numero_contact_creer(
  p_numero       text,
  p_evenement_id smallint DEFAULT 1
) RETURNS numero_contact LANGUAGE plpgsql AS $$
DECLARE v numero_contact; v_ordre integer;
BEGIN
  SELECT COALESCE(MAX(ordre), 0) + 1 INTO v_ordre FROM numero_contact WHERE evenement_id = p_evenement_id;
  INSERT INTO numero_contact (evenement_id, ordre, numero) VALUES (p_evenement_id, v_ordre, p_numero)
  RETURNING * INTO v;
  RETURN v;
END $$;

CREATE OR REPLACE FUNCTION numero_contact_modifier(p_id smallint, p_numero text)
RETURNS numero_contact LANGUAGE plpgsql AS $$
DECLARE v numero_contact;
BEGIN
  UPDATE numero_contact SET numero = p_numero WHERE id = p_id
  RETURNING * INTO v;
  IF NOT FOUND THEN RAISE EXCEPTION 'numero_contact % introuvable', p_id; END IF;
  RETURN v;
END $$;

CREATE OR REPLACE FUNCTION numero_contact_supprimer(p_id smallint)
RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  DELETE FROM numero_contact WHERE id = p_id;
  RETURN FOUND;
END $$;

-- ============================================================
--  FIN — vérifier avec :
--    SELECT jsonb_pretty(site_data_json());
-- ============================================================
