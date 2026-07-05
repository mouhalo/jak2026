// Tests des fonctions otp_creer / otp_verifier — J1 — JAK 2026
// Connexion directe Node.js (pg). Lancé depuis la racine du projet.
const fs = require('fs');
const { Client } = require('pg');

const raw = fs.readFileSync('.env', 'utf8');
const env = {};
raw.split(/\r?\n/).forEach(l => {
  l = l.trim();
  if (l && !l.startsWith('#') && l.includes('=')) {
    const [k, ...r] = l.split('=');
    env[k.trim()] = r.join('=').trim();
  }
});

const OK = 'a'.repeat(64);   // hash "bon" (64 hex = HMAC-SHA256)
const KO = 'b'.repeat(64);   // hash "mauvais"

let errored = null;

let pass = 0, fail = 0;
function check(label, cond, extra='') {
  if (cond) { pass++; console.log(`  ✅ ${label} ${extra}`); }
  else      { fail++; console.log(`  ❌ ${label} ${extra}`); }
}

async function run() {
  const client = new Client({
    host: env.DATABASE_HOST, port: 3253,
    database: env.DATABASE_NAME, user: env.DATABASE_USER,
    password: env.DATABASE_PASS
  });
  await client.connect();

  // IDs de test créés, à nettoyer à la fin
  const created = [];

  try {
    // =========================================================
    console.log('\n[1] otp_creer — création basique');
    // =========================================================
    const id1 = await client.query(
      `SELECT otp_creer('membre', '770000001', NULL, $1, $2) AS id`, [OK, 300]);
    check('retourne un bigint > 0', Number(id1.rows[0].id) > 0, `(id=${id1.rows[0].id})`);
    created.push(id1.rows[0].id);

    const row = (await client.query(
      `SELECT role, telephone, personne_id, code_hash, expire_le,
              tentatives, consomme,
              extract(epoch FROM (expire_le - now()))::int AS ttl_reel
         FROM otp_defi WHERE id = $1`, [id1.rows[0].id])).rows[0];
    check('role enregistré', row.role === 'membre');
    check('telephone enregistré', row.telephone === '770000001');
    check('personne_id NULL accepté', row.personne_id === null);
    check('code_hash stocké tel quel', row.code_hash === OK);
    check('tentatives = 0', row.tentatives === 0);
    check('consomme = false', row.consomme === false);
    check('expire_le ~ now()+300s', Math.abs(row.ttl_reel - 300) <= 3, `(ttl=${row.ttl_reel}s)`);

    // =========================================================
    console.log('\n[2] otp_verifier — SUCCÈS');
    // =========================================================
    const r2 = await client.query(`SELECT otp_verifier($1, $2) AS r`, [id1.rows[0].id, OK]);
    const j2 = r2.rows[0].r;
    check('ok=true', j2.ok === true);
    check("raison='ok'", j2.raison === 'ok');
    check('role retourné', j2.role === 'membre');
    check('personne_id null', j2.personne_id === null);

    const cons = (await client.query(
      `SELECT consomme FROM otp_defi WHERE id=$1`, [id1.rows[0].id])).rows[0].consomme;
    check('consommé après succès', cons === true);

    // resoumission → 'none' (déjà consommé)
    const r2b = await client.query(`SELECT otp_verifier($1, $2) AS r`, [id1.rows[0].id, OK]);
    check("rejeu après consommation → 'none'", r2b.rows[0].r.raison === 'none');

    // =========================================================
    console.log('\n[3] otp_verifier — CODE FAUX (bad)');
    // =========================================================
    const id3 = (await client.query(
      `SELECT otp_creer('membre','770000002',NULL,$1) AS id`, [OK])).rows[0].id;
    created.push(id3);
    const r3 = await client.query(`SELECT otp_verifier($1, $2) AS r`, [id3, KO]);
    const j3 = r3.rows[0].r;
    check("ok=false", j3.ok === false);
    check("raison='bad'", j3.raison === 'bad');
    const t3 = (await client.query(`SELECT tentatives, consomme FROM otp_defi WHERE id=$1`,[id3])).rows[0];
    check('tentatives incrémentée à 1', t3.tentatives === 1);
    check('défi encore vivant (consomme=false)', t3.consomme === false);

    // =========================================================
    console.log('\n[4] otp_verifier — VERROUILLAGE (locked) après 5 essais');
    // =========================================================
    const id4 = (await client.query(
      `SELECT otp_creer('membre','770000003',NULL,$1) AS id`, [OK])).rows[0].id;
    created.push(id4);
    // 4 échecs 'bad'
    for (let i = 1; i <= 4; i++) {
      const r = (await client.query(`SELECT otp_verifier($1, $2) AS r`, [id4, KO])).rows[0].r;
      check(`essai ${i} → bad`, r.raison === 'bad');
    }
    // 5e essai → locked (dépasse max=5), défi consommé
    const r4 = (await client.query(`SELECT otp_verifier($1, $2) AS r`, [id4, KO])).rows[0].r;
    check("5e essai → 'locked'", r4.raison === 'locked');
    const t4 = (await client.query(`SELECT tentatives, consomme FROM otp_defi WHERE id=$1`,[id4])).rows[0];
    check('tentatives = 5 (borne CHECK respectée)', t4.tentatives === 5);
    check('défi consommé après verrouillage', t4.consomme === true);
    // rejeu après verrouillage → none
    const r4b = (await client.query(`SELECT otp_verifier($1, $2) AS r`, [id4, KO])).rows[0].r;
    check("rejeu après lock → 'none'", r4b.raison === 'none');

    // =========================================================
    console.log('\n[5] otp_verifier — EXPIRÉ (expired)');
    // =========================================================
    const id5 = (await client.query(
      `SELECT otp_creer('membre','770000004',NULL,$1,1) AS id`, [OK])).rows[0].id; // TTL=1s
    created.push(id5);
    await new Promise(res => setTimeout(res, 1800)); // attendre l'expiration
    const r5 = await client.query(`SELECT otp_verifier($1, $2) AS r`, [id5, OK]);
    check("défi expiré → 'expired'", r5.rows[0].r.raison === 'expired');
    const t5 = (await client.query(`SELECT consomme FROM otp_defi WHERE id=$1`,[id5])).rows[0];
    check('défi consommé après expiration', t5.consomme === true);

    // =========================================================
    console.log('\n[6] otp_verifier — ID INEXISTANT (none)');
    // =========================================================
    const r6 = await client.query(`SELECT otp_verifier(999999999, $1) AS r`, [OK]);
    check("id inexistant → 'none'", r6.rows[0].r.raison === 'none');

    // =========================================================
    console.log('\n[7] otp_creer — INVALIDATION multi-défis');
    // =========================================================
    const idA = (await client.query(
      `SELECT otp_creer('membre','770000005',NULL,$1) AS id`, [OK])).rows[0].id;
    created.push(idA);
    const idB = (await client.query(
      `SELECT otp_creer('membre','770000005',NULL,$1) AS id`, [OK])).rows[0].id; // même tél
    created.push(idB);
    check('nouveau défi créé avec id différent', idA !== idB);
    const oldRow = (await client.query(
      `SELECT consomme FROM otp_defi WHERE id=$1`, [idA])).rows[0];
    check('ancien défi invalidé (consomme=true)', oldRow.consomme === true);
    const newRow = (await client.query(
      `SELECT consomme FROM otp_defi WHERE id=$1`, [idB])).rows[0];
    check('nouveau défi actif (consomme=false)', newRow.consomme === false);
    // l'ancien ne peut plus être vérifié
    const rA = await client.query(`SELECT otp_verifier($1, $2) AS r`, [idA, OK]);
    check("ancien défi invalidé → 'none'", rA.rows[0].r.raison === 'none');
    // le nouveau marche
    const rB = await client.query(`SELECT otp_verifier($1, $2) AS r`, [idB, OK]);
    check("nouveau défi → 'ok'", rB.rows[0].r.raison === 'ok');

    // =========================================================
    console.log('\n[8] otp_creer — rôles admin/editeur + téléphone NULL');
    // =========================================================
    const idAdmin = (await client.query(
      `SELECT otp_creer('admin', NULL, NULL, $1) AS id`, [OK])).rows[0].id;
    created.push(idAdmin);
    const rAdmin = (await client.query(`SELECT otp_verifier($1, $2) AS r`, [idAdmin, OK])).rows[0].r;
    check("admin → ok + role='admin'", rAdmin.ok === true && rAdmin.role === 'admin');

    // =========================================================
    console.log('\n[9] otp_creer — validation téléphone invalide');
    // =========================================================
    try {
      await client.query(`SELECT otp_creer('membre','BAD',NULL,$1) AS id`, [OK]);
      check('téléphone invalide → exception', false, '(pas d exception levée)');
    } catch (e) {
      check('téléphone invalide → exception', /invalide/.test(e.message), `(${e.message})`);
    }

    // =========================================================
    console.log('\n[10] p_code_hash NULL → exception');
    // =========================================================
    try {
      await client.query(`SELECT otp_creer('membre','770000009',NULL,NULL) AS id`);
      check('p_code_hash NULL → exception', false, '(pas d exception levée)');
    } catch (e) {
      check('p_code_hash NULL → exception', /obligatoire/.test(e.message), `(${e.message})`);
    }

    // =========================================================
    console.log('\n[11] Borne p_max_tentatives (CHECK <= 5)');
    // =========================================================
    const idMax = (await client.query(
      `SELECT otp_creer('membre','770000007',NULL,$1) AS id`, [OK])).rows[0].id;
    created.push(idMax);
    // max=10 demandé → borné à 5 par la fonction (cast explicite : la signature
    // attend smallint et les paramètres $1/$2 'unknown' empêchent la résolution)
    let last;
    for (let i = 0; i < 6; i++) {
      last = (await client.query(
        `SELECT otp_verifier($1::bigint, $2::text, 10::smallint) AS r`, [idMax, KO])).rows[0].r;
    }
    check("max=10 borné à 5 → locked au 5e (pas 10)", last.raison === 'locked' || last.raison === 'none',
          `(dernier=${last.raison})`);
    const tMax = (await client.query(`SELECT tentatives FROM otp_defi WHERE id=$1`,[idMax])).rows[0];
    check('tentatives jamais > 5 (CHECK respectée)', tMax.tentatives <= 5, `(tentatives=${tMax.tentatives})`);

  } catch (e) {
    errored = e;
    console.error('\n⚠️  EXCEPTION pendant les tests :', e.message);
  } finally {
    // =========================================================
    // NETTOYAGE — supprimer les lignes de test
    // =========================================================
    console.log('\n[NETTOYAGE]');
    try {
      if (created.length) {
        const del = await client.query(
          `DELETE FROM otp_defi WHERE id = ANY($1::bigint[]) RETURNING id`, [created]);
        check(`${del.rows.length}/${created.length} lignes de test supprimées`,
              del.rows.length === created.length);
      } else {
        console.log('  (rien à nettoyer)');
      }
      // compte final
      const cnt = (await client.query(`SELECT count(*) AS n FROM otp_defi`)).rows[0].n;
      check('otp_defi laissée vide', Number(cnt) === 0, `(reste ${cnt})`);
    } catch (e2) {
      console.error('  Erreur pendant le nettoyage :', e2.message);
    }
    await client.end();
    console.log(`\n================ RÉCAPITULATIF ================`);
    console.log(`  ${pass} succès, ${fail} échecs` + (errored ? ' + 1 exception fatale' : ''));
    process.exit(fail || errored ? 1 : 0);
  }
}

run().catch(async e => {
  console.error('ERREUR FATALE:', e);
  process.exit(2);
});
