// Tests des fonctions du chantier 2 (dons) — JAK 2026
// Connexion directe Node.js (pg). Lancé depuis la racine du projet.
// Couvre : numero_mask, journal_ajouter, don_creer, don_attacher_uuid,
//          don_confirmer (IDEMPOTENCE), don_echouer (IDEMPOTENCE),
//          soutien_upsert (IDEMPOTENCE), soutien_lister.
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

let pass = 0, fail = 0;
function check(label, cond, extra = '') {
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
  const createdDonIds = [];
  const createdJournalIds = [];

  try {
    // Nettoyage préalable (au cas où une exécution précédente aurait échoué)
    await client.query("DELETE FROM soutien_recus WHERE reference_don LIKE 'TEST-%'");
    await client.query("DELETE FROM don WHERE reference_interne LIKE 'TEST-%'");
    await client.query("DELETE FROM journal_organisation WHERE cible_type='don' AND details->>'test' = 'chantier2'");

    // =========================================================
    console.log('\n[1] numero_mask — masquage téléphone');
    // =========================================================
    check('national 9 chiffres', (await client.query("SELECT numero_mask('777306661') AS r")).rows[0].r === '77 •• •• 61');
    check('E.164 +221', (await client.query("SELECT numero_mask('+221777306661') AS r")).rows[0].r === '+221 77 •• •• 61');
    check('E.164 +33', (await client.query("SELECT numero_mask('+33612345678') AS r")).rows[0].r === '+33 61 •• •• 78');
    check('NULL → NULL', (await client.query("SELECT numero_mask(NULL) AS r")).rows[0].r === null);
    check('vide → NULL', (await client.query("SELECT numero_mask('') AS r")).rows[0].r === null);
    check('avec espaces/typos nettoyés', (await client.query("SELECT numero_mask('77 73 06 66 1') AS r")).rows[0].r === '77 •• •• 61');

    // =========================================================
    console.log('\n[2] journal_ajouter — insertion audit');
    // =========================================================
    const jid = (await client.query("SELECT journal_ajouter('reconciliation', NULL, NULL, jsonb_build_object('source','test')) AS id")).rows[0].id;
    check('retourne un id > 0', Number(jid) > 0, `(id=${jid})`);
    createdJournalIds.push(jid);
    const jrow = (await client.query("SELECT evenement, cible_type, details->>'source' AS src FROM journal_organisation WHERE id=$1", [jid])).rows[0];
    check('evenement stocké', jrow.evenement === 'reconciliation');
    check('details jsonb stocké', jrow.src === 'test');

    // =========================================================
    console.log('\n[3] don_creer — création basique');
    // =========================================================
    const did = (await client.query("SELECT don_creer(5000,'wave','+221777306661','Fatou D.','TEST-REF001') AS id")).rows[0].id;
    check('retourne un id > 0', Number(did) > 0, `(id=${did})`);
    createdDonIds.push(did);
    const drow = (await client.query("SELECT montant, canal, telephone_masque, telephone_clair, nom_affiche, reference_interne, statut, uuid, confirme_le FROM don WHERE id=$1", [did])).rows[0];
    check('montant stocké', drow.montant === 5000);
    check('canal normalisé WAVE', drow.canal === 'WAVE');
    check('telephone_masque masqué', drow.telephone_masque === '+221 77 •• •• 61');
    check('telephone_clair en clair (audit)', drow.telephone_clair === '+221777306661');
    check('nom_affiche stocké', drow.nom_affiche === 'Fatou D.');
    check('reference_interne stockée', drow.reference_interne === 'TEST-REF001');
    check('statut en_attente', drow.statut === 'en_attente');
    check('uuid NULL initialement', drow.uuid === null);
    check('confirme_le NULL', drow.confirme_le === null);
    // journal don_cree créé
    const jc = (await client.query("SELECT COUNT(*) AS n FROM journal_organisation WHERE cible_type='don' AND cible_id=$1 AND evenement='don_cree'", [did])).rows[0].n;
    check('journal don_cree créé', Number(jc) === 1);

    // =========================================================
    console.log('\n[4] don_creer — validations (doivent lever une exception)');
    // =========================================================
    async function expectThrow(label, sql) {
      try { await client.query(sql); check(label, false, '(aucune exception)'); }
      catch (e) { check(label, /22023|22004/.test(e.code || ''), `(${e.code})`); }
    }
    await expectThrow('montant < 100 rejeté', "SELECT don_creer(50,'om','+221777306661',NULL,'TEST-X1')");
    await expectThrow('montant > 2000000 rejeté', "SELECT don_creer(5000000,'om','+221777306661',NULL,'TEST-X2')");
    await expectThrow('canal invalide rejeté', "SELECT don_creer(5000,'paypal','+221777306661',NULL,'TEST-X3')");
    await expectThrow('téléphone non-E.164 rejeté', "SELECT don_creer(5000,'om','abc',NULL,'TEST-X4')");
    await expectThrow('téléphone NULL rejeté', "SELECT don_creer(5000,'om',NULL,NULL,'TEST-X5')");

    // =========================================================
    console.log('\n[5] don_attacher_uuid — attachement idempotent');
    // =========================================================
    const uuid1 = 'a1111111-1111-1111-1111-111111111111';
    const r1 = (await client.query("SELECT don_attacher_uuid($1,$2::uuid) AS ok", [did, uuid1])).rows[0].ok;
    check('1er attachement → true', r1 === true);
    const r2 = (await client.query("SELECT don_attacher_uuid($1,$2::uuid) AS ok", [did, 'b2222222-2222-2222-2222-222222222222'])).rows[0].ok;
    check('2e attachement → false (déjà présent)', r2 === false);
    const uuidStocke = (await client.query("SELECT uuid FROM don WHERE id=$1", [did])).rows[0].uuid;
    check("uuid inchangé après 2e tentative (toujours le 1er)", uuidStocke === uuid1);
    const r3 = (await client.query("SELECT don_attacher_uuid(999999999,$1::uuid) AS ok", [uuid1])).rows[0].ok;
    check('don inexistant → false', r3 === false);

    // =========================================================
    console.log('\n[6] don_confirmer — IDEMPOTENCE CRITIQUE');
    // =========================================================
    const ref = 'TEST-CONFIRM01';
    const did2 = (await client.query("SELECT don_creer(10000,'om','+221771234567','Cheikh A.', $1) AS id", [ref])).rows[0].id;
    createdDonIds.push(did2);
    // 1ère confirmation
    const c1 = (await client.query("SELECT don_confirmer($1,'c3333333-3333-3333-3333-333333333333'::uuid,'OP-REF-1') AS r", [did2])).rows[0].r;
    check('1ère confirmation → action=confirme', c1.action === 'confirme' && c1.ok === true, JSON.stringify(c1));
    // 2e confirmation (même uuid) → no_op
    const c2 = (await client.query("SELECT don_confirmer($1,'c3333333-3333-3333-3333-333333333333'::uuid,'OP-REF-2') AS r", [did2])).rows[0].r;
    check('2e confirmation → action=no_op', c2.action === 'no_op' && c2.raison === 'deja_traite', JSON.stringify(c2));
    // 3e confirmation (uuid différent) → toujours no_op, uuid NON écrasé
    const c3 = (await client.query("SELECT don_confirmer($1,'d4444444-4444-4444-4444-444444444444'::uuid) AS r", [did2])).rows[0].r;
    check('3e confirmation (uuid diff) → no_op', c3.action === 'no_op', JSON.stringify(c3));
    const d2row = (await client.query("SELECT statut, uuid, ref_operateur, confirme_le FROM don WHERE id=$1", [did2])).rows[0];
    check('statut = confirme', d2row.statut === 'confirme');
    check('uuid = 1er uuid (non écrasé)', d2row.uuid === 'c3333333-3333-3333-3333-333333333333');
    check('ref_operateur = 1ère (COALESCE ne remplace pas)', d2row.ref_operateur === 'OP-REF-1');
    check('confirme_le renseigné', d2row.confirme_le !== null);
    // soutien créé UNE seule fois
    const sc = (await client.query("SELECT COUNT(*) AS n FROM soutien_recus WHERE reference_don=$1", [ref])).rows[0].n;
    check('soutien créé UNE fois (idempotence carrousel)', Number(sc) === 1, `(n=${sc})`);
    // journal don_confirme UNE fois
    const jcf = (await client.query("SELECT COUNT(*) AS n FROM journal_organisation WHERE cible_type='don' AND cible_id=$1 AND evenement='don_confirme'", [did2])).rows[0].n;
    check('journal don_confirme UNE fois', Number(jcf) === 1);

    // =========================================================
    console.log('\n[7] don_echouer — IDEMPOTENCE + no-op après confirmation');
    // =========================================================
    // échouer un don déjà confirmé → no_op (on ne peut pas échouer un confirmé)
    const e0 = (await client.query("SELECT don_echouer($1,NULL,'test_apres_confirme') AS r", [did2])).rows[0].r;
    check('échouer un don confirmé → no_op', e0.action === 'no_op', JSON.stringify(e0));
    // échouer un don en_attente
    const ref3 = 'TEST-ECHEC01';
    const did3 = (await client.query("SELECT don_creer(3000,'wave','779988776','Anonyme', $1) AS id", [ref3])).rows[0].id;
    createdDonIds.push(did3);
    const e1 = (await client.query("SELECT don_echouer($1,'e5555555-5555-5555-5555-555555555555'::uuid,'paiement_refuse') AS r", [did3])).rows[0].r;
    check('échouer don en_attente → action=echoue', e1.action === 'echoue' && e1.ok === true, JSON.stringify(e1));
    const e2 = (await client.query("SELECT don_echouer($1,NULL,'re-tentative') AS r", [did3])).rows[0].r;
    check('2e échouer → no_op', e2.action === 'no_op', JSON.stringify(e2));
    const d3row = (await client.query("SELECT statut, uuid FROM don WHERE id=$1", [did3])).rows[0];
    check('statut = echoue', d3row.statut === 'echoue');
    check('AUCUN soutien créé pour don échoué', (await client.query("SELECT COUNT(*) AS n FROM soutien_recus WHERE reference_don=$1", [ref3])).rows[0].n === '0');
    const je = (await client.query("SELECT COUNT(*) AS n FROM journal_organisation WHERE cible_type='don' AND cible_id=$1 AND evenement='don_echoue'", [did3])).rows[0].n;
    check('journal don_echoue UNE fois', Number(je) === 1);

    // =========================================================
    console.log('\n[8] soutien_upsert — idempotence directe');
    // =========================================================
    const sid1 = (await client.query("SELECT soutien_upsert($1,'TEST-DUP', '77 •• •• 99','Toto','OM',2500,now()) AS id", [did])).rows[0].id;
    const sid2 = (await client.query("SELECT soutien_upsert($1,'TEST-DUP', '77 •• •• 99','Toto','OM',2500,now()) AS id", [did])).rows[0].id;
    check('2 upserts même réf → même id', sid1 === sid2, `(${sid1}==${sid2})`);
    check('1 seule ligne créée', (await client.query("SELECT COUNT(*) AS n FROM soutien_recus WHERE reference_don='TEST-DUP'")).rows[0].n === '1');

    // =========================================================
    console.log('\n[9] soutien_lister — pas de montant/téléphone clair');
    // =========================================================
    const rows = (await client.query("SELECT * FROM soutien_lister(50,0)")).rows;
    check('retourne au moins 1 soutien', rows.length >= 1);
    check('AUCUNE colonne montant', !rows.some(r => 'montant' in r));
    check('AUCUNE colonne telephone_clair', !rows.some(r => 'telephone_clair' in r));
    check('colonnes exactes', rows.length === 0 || JSON.stringify(Object.keys(rows[0]).sort()) === JSON.stringify(['canal','confirme_le','nom_affiche','numero_masque']), `(${rows.length?Object.keys(rows[0]).join(','):''})`);
    // ordre DESC
    if (rows.length >= 2) {
      check('tri confirme_le DESC', new Date(rows[0].confirme_le) >= new Date(rows[1].confirme_le));
    }
    // pagination
    const p1 = (await client.query("SELECT * FROM soutien_lister(1,0)")).rows.length;
    const p2 = (await client.query("SELECT * FROM soutien_lister(1,1)")).rows.length;
    check('pagination (limit/offset) fonctionne', p1 === 1);
    check('pagination borne supérieure (limit 9999 → 20)', (await client.query("SELECT * FROM soutien_lister(9999,0)")).rows.length <= 200);

    // =========================================================
    console.log('\n[10] nom_affiche NULL → Anonyme dans soutien');
    // =========================================================
    const refAnon = 'TEST-ANON01';
    const didAnon = (await client.query("SELECT don_creer(1500,'om','+221770000000',NULL,$1) AS id", [refAnon])).rows[0].id;
    createdDonIds.push(didAnon);
    await client.query("SELECT don_confirmer($1)", [didAnon]);
    const anonNom = (await client.query("SELECT nom_affiche FROM soutien_recus WHERE reference_don=$1", [refAnon])).rows[0].nom_affiche;
    check('nom_affiche NULL → Anonyme', anonNom === 'Anonyme');

    console.log(`\n================= RÉCAPITULATIF : ${pass} réussis, ${fail} échoués =================`);
  } finally {
    // Nettoyage
    console.log('\n[Nettoyage] suppression des données de test...');
    try {
      // Ordre FK : soutien_recus (CASCADE sur don) puis don, puis journal (orphelins).
      await client.query("DELETE FROM soutien_recus WHERE reference_don LIKE 'TEST-%'");
      await client.query("DELETE FROM journal_organisation WHERE details->>'reference_interne' LIKE 'TEST-%'");
      await client.query("DELETE FROM journal_organisation WHERE evenement IN ('reconciliation') AND details->>'source' = 'test'");
      await client.query("DELETE FROM journal_organisation WHERE cible_type='don' AND (details->>'reference_interne' LIKE 'TEST-%' OR details->>'montant' IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM don WHERE id = journal_organisation.cible_id)");
      await client.query("DELETE FROM don WHERE reference_interne LIKE 'TEST-%'");
      // re-vérif
      const reste = await client.query("SELECT (SELECT COUNT(*) FROM don) AS don, (SELECT COUNT(*) FROM soutien_recus) AS sout, (SELECT COUNT(*) FROM journal_organisation) AS jrn");
      console.log('  états finaux:', JSON.stringify(reste.rows[0]));
    } catch (e) { console.error('  nettoyage partiel:', e.message); }
    await client.end();
  }
  process.exit(fail > 0 ? 1 : 0);
}

run().catch(e => { console.error('FATAL', e); process.exit(1); });
