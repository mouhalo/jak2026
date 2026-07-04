#!/usr/bin/env node
/**
 * ============================================================
 *  JAK 2026 — Seed : data.js → PostgreSQL
 * ============================================================
 *  Rôle :
 *   1. Lit ../data.js (source de vérité actuelle).
 *   2. Décode les photos base64 → fichiers dans ../souvenirs/<groupe>/seed/.
 *   3. Génère db/seed.sql (TRUNCATE + INSERT) prêt à charger via psql.
 *
 *  Dépendances : AUCUNE (Node >= 18, modules fs/path/crypto intégrés).
 *
 *  Usage (depuis le dossier jak2026/) :
 *      node db/seed.mjs              # génère seed.sql + décode les images
 *      node db/seed.mjs --reset      # écrase les images déjà décodées
 *      node db/seed.mjs --no-images  # ne génère que seed.sql
 *
 *  Puis :
 *      psql -d jak2026 -f db/schema.sql   # si pas déjà fait
 *      psql -d jak2026 -f db/seed.sql
 *      SELECT jsonb_pretty(site_data_json());
 * ============================================================
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const ROOT       = path.resolve(__dirname, '..');          // dossier jak2026/
const DATA_JS    = path.join(ROOT, 'data.js');
const SOUVENIRS  = path.join(ROOT, 'souvenirs');
const OUT_SQL    = path.join(__dirname, 'seed.sql');

const args = new Set(process.argv.slice(2));
const RESET      = args.has('--reset');
const NO_IMAGES  = args.has('--no-images');

// ----------------- helpers -----------------
/** Charge window.SITE_DATA depuis data.js */
function loadDataJs() {
  let src = fs.readFileSync(DATA_JS, 'utf8');
  const m = src.match(/window\.SITE_DATA\s*=\s*([\s\S]*?);\s*$/);
  if (!m) throw new Error('Impossible de localiser window.SITE_DATA dans data.js');
  return JSON.parse(m[1]);
}

/** Détermine l'extension d'un data-URL base64 (png/jpg/webp/gif...) */
function dataUrlExt(dataUrl) {
  const m = /^data:image\/([a-zA-Z0-9.+-]+);/.exec(dataUrl || '');
  const t = m ? m[1].toLowerCase() : 'jpeg';
  // normalisation : jpeg → jpg
  if (t === 'jpeg') return 'jpg';
  return t;
}

/** Échappe une valeur texte pour SQL (simple quote doublée) */
function sqlStr(s) {
  if (s === null || s === undefined) return 'NULL';
  return "'" + String(s).replace(/'/g, "''") + "'";
}

/** Crée un slug stable à partir d'un nom (pour les membres) */
function slugify(s) {
  return String(s || '')
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')   // accents
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60);
}

/** Garantit l'existence d'un dossier */
function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

/**
 * Résout une photo :
 *  - si chemin déjà (souvenirs/...), le garder tel quel.
 *  - si base64, décoder vers <dirOut>/<name> et retourner le chemin relatif (souvenirs/...).
 */
function resolvePhoto(value, dirOut, nameBase) {
  if (!value) return '';
  if (!/^data:image\//.test(value)) return value;          // déjà un chemin
  if (NO_IMAGES) return '';                                // mode --no-images : on saute

  const ext = dataUrlExt(value);
  const rel = path.relative(ROOT, dirOut).split(path.sep).join('/');
  const fname = `${nameBase}.${ext}`;
  const fpath = path.join(dirOut, fname);
  const relPath = `${rel}/${fname}`;

  if (fs.existsSync(fpath) && !RESET) {
    return relPath;                                        // déjà décodé (idempotent)
  }
  const b64 = value.split(',')[1] || '';
  ensureDir(dirOut);
  fs.writeFileSync(fpath, Buffer.from(b64, 'base64'));
  return relPath;
}

// ----------------- génération SQL -----------------
function buildSql(D) {
  const S = D.settings || {};
  const lines = [];
  let imgCount = 0;

  lines.push('-- ============================================================');
  lines.push('--  JAK 2026 — Seed généré depuis data.js');
  lines.push('--  Généré le ' + new Date().toISOString());
  lines.push('--  À charger après schema.sql : psql -d jak2026 -f db/seed.sql');
  lines.push('-- ============================================================');
  lines.push('');
  lines.push('BEGIN;');

  // ---- TRUNCATE (ordre : respect des FK) ----
  lines.push('-- Nettoyage (idempotent)');
  lines.push('TRUNCATE TABLE');
  lines.push('  revision, don, otp_defi, utilisateur,');
  lines.push('  galerie_photo, personne, cheikh_citation, cheikh,');
  lines.push('  numero_contact, programme_jour, evenement');
  lines.push('  RESTART IDENTITY CASCADE;');
  lines.push('');

  // ---- ÉVÉNEMENT (singleton courant id=1) ----
  const dcr = (S.date_compte_rebours || '').replace('T', ' ').replace(/$/, '');
  lines.push('-- Événement courant');
  lines.push(`INSERT INTO evenement (id, est_courant, event_nom, organisateur, lieu, dates_texte,
                date_compte_rebours, note_soutien, jak_presentation)`);
  lines.push(`VALUES (1, true, ${sqlStr(S.event_nom)}, ${sqlStr(S.organisateur)}, ${sqlStr(S.lieu)},
                ${sqlStr(S.dates_texte)}, ${sqlStr(dcr)},
                ${sqlStr(S.note_soutien || '')}, ${sqlStr(S.jak_presentation || '')})
            OVERRIDING SYSTEM VALUE;`);
  lines.push("SELECT setval(pg_get_serial_sequence('evenement','id'), 1, true);");
  lines.push('');

  // ---- PROGRAMME_JOUR ----
  const prog = S.programme || [];
  lines.push('-- Programme');
  prog.forEach((p, i) => {
    lines.push(`INSERT INTO programme_jour (evenement_id, ordre, jour, titre, description)
                VALUES (1, ${i}, ${sqlStr(p.jour)}, ${sqlStr(p.titre)}, ${sqlStr(p.desc)});`);
  });
  lines.push('');

  // ---- NUMERO_CONTACT ----
  const wapp = S.whatsapp || [];
  lines.push('-- Numéros officiels (Wave · OM · WhatsApp)');
  wapp.forEach((n, i) => {
    lines.push(`INSERT INTO numero_contact (evenement_id, ordre, numero)
                VALUES (1, ${i}, ${sqlStr(n)});`);
  });
  lines.push('');

  // ---- CHEIKH (singleton id=1) ----
  const C = D.cheikh || {};
  const cheikhDir = path.join(SOUVENIRS, 'cheikh', 'seed');
  const cheikhPhoto = C.photo && /^data:image\//.test(C.photo)
    ? (imgCount++, resolvePhoto(C.photo, cheikhDir, 'cheikh'))
    : (C.photo || '');
  lines.push('-- Cheikh');
  lines.push(`INSERT INTO cheikh (id, nom_complet, url_photo, biographie, adresse)
              VALUES (1, ${sqlStr(C.nom_complet)}, ${sqlStr(cheikhPhoto)},
                      ${sqlStr(C.biographie)}, ${sqlStr(C.adresse)});`);
  (C.citations || []).forEach((c, i) => {
    lines.push(`INSERT INTO cheikh_citation (cheikh_id, ordre, texte) VALUES (1, ${i}, ${sqlStr(c)});`);
  });
  lines.push('');

  // ---- PERSONNES : dignitaires ----
  const dignDir = path.join(SOUVENIRS, 'dignitaires', 'seed');
  lines.push('-- Dignitaires');
  (D.dignitaires || []).forEach((p, i) => {
    const photo = /^data:image\//.test(p.photo || '')
      ? (imgCount++, resolvePhoto(p.photo, dignDir, `dig-${String(i + 1).padStart(2, '0')}`))
      : (p.photo || '');
    lines.push(`INSERT INTO personne (evenement_id, categorie, nom_complet, url_photo, biographie, adresse, ordre)
                VALUES (1, 'dignitaire', ${sqlStr(p.nom_complet)}, ${sqlStr(photo)},
                        ${sqlStr(p.biographie)}, ${sqlStr(p.adresse)}, ${i});`);
  });
  lines.push('');

  // ---- PERSONNES : membres JAK ----
  const membDir = path.join(SOUVENIRS, 'membres', 'seed');
  lines.push('-- Membres JAK');
  (D.jak || []).forEach((p, i) => {
    // Entrée totalement vide (index 15) → on saute pour ne pas polluer la base
    if (!p.nom_complet && !p.photo && !p.fonction) return;

    const photo = /^data:image\//.test(p.photo || '')
      ? (imgCount++, resolvePhoto(p.photo, membDir, `mb-${String(i + 1).padStart(2, '0')}`))
      : (p.photo || '');

    // slug stable : dérivé du nom (vide si nom absent → l'admin renseignera)
    const slug = slugify(p.nom_complet) ? 'm-' + slugify(p.nom_complet) : null;
    // telephone : NULL au seed (renseigné par l'admin, sert au login OTP)
    const fondateur = (p.fondateur === 'OUI');

    lines.push(`INSERT INTO personne (evenement_id, categorie, slug, telephone, nom_complet, url_photo,
                  biographie, adresse, fondateur, fonction, ordre)
                VALUES (1, 'membre', ${sqlStr(slug)}, NULL,
                        ${sqlStr(p.nom_complet)}, ${sqlStr(photo)},
                        ${sqlStr(p.biographie)}, ${sqlStr(p.adresse)},
                        ${fondateur}, ${sqlStr(p.fonction)}, ${i});`);
  });
  lines.push('');

  // ---- GALERIE (toutes en chemins, rien à décoder) ----
  lines.push('-- Galerie');
  (D.galerie || []).forEach((g, i) => {
    if (!g.src) return;
    lines.push(`INSERT INTO galerie_photo (evenement_id, ordre, url_photo, legende)
                VALUES (1, ${i}, ${sqlStr(g.src)}, ${sqlStr(g.legende || '')});`);
  });
  lines.push('');

  lines.push('COMMIT;');
  lines.push('');

  // ---- rapports ----
  const report = {
    evenement: 1,
    programme: prog.length,
    numeros: wapp.length,
    cheikh: 1,
    cheikh_citations: (C.citations || []).length,
    dignitaires: (D.dignitaires || []).length,
    membres: (D.jak || []).filter(p => p.nom_complet || p.photo || p.fonction).length,
    galerie: (D.galerie || []).filter(g => g.src).length,
    images_decodees: imgCount,
  };
  return { sql: lines.join('\n'), report };
}

// ----------------- main -----------------
function main() {
  if (!fs.existsSync(DATA_JS)) {
    console.error(`✗ data.js introuvable : ${DATA_JS}`);
    process.exit(1);
  }
  console.log('▸ Lecture de data.js…');
  const D = loadDataJs();

  console.log('▸ Génération de seed.sql…');
  const { sql, report } = buildSql(D);

  fs.writeFileSync(OUT_SQL, sql, 'utf8');
  console.log(`✓ seed.sql écrit : ${OUT_SQL}`);
  console.log('');
  console.log('── Récapitulatif du seed ──────────────────────');
  console.log(`  Événement          : ${report.evenement}`);
  console.log(`  Programme (jours)  : ${report.programme}`);
  console.log(`  Numéros contact    : ${report.numeros}`);
  console.log(`  Cheikh             : ${report.cheikh} + ${report.cheikh_citations} citation(s)`);
  console.log(`  Dignitaires        : ${report.dignitaires}`);
  console.log(`  Membres JAK        : ${report.membres}`);
  console.log(`  Galerie            : ${report.galerie}`);
  console.log(`  Images décodées    : ${report.images_decodees}`);
  console.log('──────────────────────────────────────────────');
  console.log('');
  console.log('Prochaines étapes :');
  console.log('  psql -d jak2026 -f db/schema.sql');
  console.log('  psql -d jak2026 -f db/seed.sql');
  console.log('  psql -d jak2026 -c "SELECT jsonb_pretty(site_data_json());"');
}

try {
  main();
} catch (e) {
  console.error('✗ ' + (e && e.message ? e.message : String(e)));
  process.exit(1);
}
