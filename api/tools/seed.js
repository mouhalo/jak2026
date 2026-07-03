// Génère data.json depuis data.js en ajoutant id + telephone aux membres.
const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '..', '..');
const window = {};
// eval() volontaire : data.js est un fichier statique du dépôt (pas une entrée
// utilisateur/externe), utilisé uniquement comme script de build/seed local
// pour capturer l'affectation window.SITE_DATA qu'il effectue.
eval(fs.readFileSync(path.join(root, 'data.js'), 'utf8')); // définit window.SITE_DATA
const d = window.SITE_DATA;

const slug = (s, i) => 'm-' + (String(s||'').toLowerCase()
  .normalize('NFD').replace(/[̀-ͯ]/g,'')
  .replace(/\[.*?\]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'') || ('membre-'+(i+1)));

d.jak = (d.jak || []).map((m, i) => ({
  id: m.id || slug(m.nom_complet, i),
  telephone: m.telephone || '',
  ...m,
}));

fs.writeFileSync(path.join(root, 'data.json'),
  JSON.stringify(d, null, 2), 'utf8');
console.log('data.json généré :', d.jak.length, 'membres');
