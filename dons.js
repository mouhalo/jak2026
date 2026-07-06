/* Page de dons — formulaire Wave/OM + carrousel des soutiens (fetch live).
 * Charge les soutiens via api/pay/liste.php (jamais de montant ni de téléphone clair),
 * soumet le don vers api/pay/create.php, redirige vers le paiement, et poll
 * api/pay/status.php pour l'UX temps réel. Déclenche aussi reconcile.php (rattrapage). */
(function(){
'use strict';
const $=s=>document.querySelector(s);
const api=(u,o)=>fetch(u,o).then(r=>r.json()).catch(()=>({success:false}));
const esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const REDUCED=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function init(){
  if(!window.SITE_DATA){return;}  // data.js pas prêt
  const D=window.SITE_DATA;
  renderChrome('dons');
  // En-tête
  $('#pgEyebrow').textContent=D.settings.event_nom+' · '+D.settings.dates_texte;
  $('#donTitre').textContent=T('don_titre');
  $('#donIntro').textContent=T('don_intro');
  // Labels du formulaire
  $('#lblMontant').textContent=T('don_montant');
  $('#lblCanal').textContent=T('don_canal');
  $('#lblTel').textContent=T('don_telephone');
  $('#lblNom').textContent=T('don_nom');
  $('#lblAnonyme').textContent=T('don_anonyme');
  $('#donSubmit').textContent=T('don_envoyer');
  $('#secSoutiens').textContent=T('soutiens_recents');
  // Bloc contacts (réutilise soutienBlock : numéros WhatsApp directs)
  $('#soutienMount').outerHTML=soutienBlock();

  // Formulaire
  $('#donForm').addEventListener('submit', submitDon);
  // Indication dynamique du canal : écoute chaque radio et met à jour l'affichage.
  document.querySelectorAll('input[name=canal]').forEach(r=>{
    r.addEventListener('change', updateCanalIndic);
  });
  updateCanalIndic();  // init : canal coché par défaut (OM)
  // Carrousel + rattrapage (best-effort)
  loadSoutiens();
  api('api/pay/reconcile.php').catch(()=>{});  // déclenche le fallback throttlé
  // Reprise d'un paiement en cours : au chargement (onglet rouvert/rechargé) ET
  // au retour d'onglet (visibilitychange → visible), cas dominant sur mobile où
  // l'onglet reste ouvert en arrière-plan pendant le passage à l'app wallet.
  resumePendingIfAny();
  document.addEventListener('visibilitychange', ()=>{
    if(document.visibilityState==='visible') resumePendingIfAny();
  });
}

/* Met à jour l'indication du canal choisi (icône + nom + hint).
 * Appelée au chargement puis à chaque clic sur un radio. */
function updateCanalIndic(){
  const el=$('#canalIndic'); if(!el)return;
  const checked=document.querySelector('input[name=canal]:checked');
  const canal=checked?checked.value:'OM';
  const ic=canal==='WAVE'?'wave.png':'om.png';
  const nom=canal==='WAVE'?'Wave':'Orange Money';
  el.innerHTML=`<span class="ci-ic"><img src="icone/${ic}" alt=""></span>`+
    `<span>${T('canal_choisi')} <span class="ci-nom">${nom}</span></span>`+
    ` <span class="ci-hint">${T('canal_hint_'+canal)}</span>`;
}

/* ---- Soumission du don ---- */
async function submitDon(e){
  e.preventDefault();
  const btn=$('#donSubmit'), msg=$('#donMsg');
  const montant=parseInt($('#fMontant').value,10);
  const canal=document.querySelector('input[name=canal]:checked').value;
  const telephone=$('#fTel').value.replace(/\D/g,'');
  const nom=$('#fNom').value.trim()||null;
  if(!montant||montant<1000||montant>2000000){
    // Montant insuffisant : modal auto-fermant (plutôt qu'un message statique).
    showModal(T('montant_min_titre'), T('montant_min_txt'), '⚠️');
    return;
  }
  if(telephone.length!==9){showMsg('err',T('don_telephone'));return;}
  btn.disabled=true; showMsg('ok',T('don_en_cours'));
  const r=await api('api/pay/create.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({montant,canal,telephone,nom})});
  btn.disabled=false;
  if(!r.success){showMsg('err',r.message||T('don_echec'));return;}
  const msgEl=$('#donMsg'); if(msgEl){msgEl.style.display='none';}
  // Succès : on PERSISTE le paiement en cours (localStorage) puis on ouvre le
  // modal bloquant. La persistance permet de REPRENDRE l'affichage du statut si
  // l'utilisateur quitte l'onglet vers l'app wallet (Wave/OM) et revient — le
  // navigateur retrouve le uuid + QR + liens sans jamais rappeler create.php.
  const now=Date.now();
  const pending={
    uuid:r.uuid, canal, montant,
    qrCode:r.qrCode||null,
    payment_url:r.payment_url||null, om:r.om||null, maxit:r.maxit||null,
    ts:now, deadline:now+WINDOW_MS
  };
  savePending(pending);
  openPayModal(pending,{resumed:false});
}

/* ---- Persistance du paiement en cours (localStorage) ----
 * Une seule note à la fois (clé PEND_KEY). Tolérant aux erreurs (mode privé,
 * quota, localStorage indisponible → no-op silencieux). */
const PEND_KEY='jak_don_pending';
const WINDOW_MS=180000;     // 3 min : fenêtre de paiement (= durée de polling)
const TTL_MS=1800000;       // 30 min : TTL dur (ne jamais ressusciter un vieux don)
const POLL_INTERVAL_MS=3000;
function savePending(o){ try{ localStorage.setItem(PEND_KEY, JSON.stringify(o)); }catch(_){ } }
function loadPending(){ try{ const s=localStorage.getItem(PEND_KEY); return s?JSON.parse(s):null; }catch(_){ return null; } }
function clearPending(){ try{ localStorage.removeItem(PEND_KEY); }catch(_){ } }

/* ---- État du modal de paiement (module) ---- */
let _cur=null;        // paiement en cours affiché {uuid,canal,montant,qrCode,...,deadline}
let _poll=null;       // handle setInterval du polling (unique)
let _tick=null;       // handle setInterval du compte à rebours
let _modalOpen=false; // garde d'idempotence (évite double ouverture / double poll)

/* Liens de paiement à proposer selon le canal.
 * OM → bouton Orange Money (om) + bouton Maxit (maxit) si distinct.
 * WAVE → bouton lien de paiement (payment_url). */
function payLinks(d){
  const out=[];
  if(d.canal==='WAVE'){
    if(d.payment_url) out.push({label:T('don_ouvrir_wave'), url:d.payment_url});
  }else{ // OM
    if(d.om)                       out.push({label:T('don_ouvrir_om'),    url:d.om});
    if(d.maxit && d.maxit!==d.om)  out.push({label:T('don_ouvrir_maxit'), url:d.maxit});
  }
  // Repli : canal sans lien dédié mais payment_url présent.
  if(!out.length && d.payment_url){
    out.push({label:T(d.canal==='WAVE'?'don_ouvrir_wave':'don_ouvrir_om'), url:d.payment_url});
  }
  return out;
}

/* ---- Modal bloquant « Paiement en cours » ----
 * Reste bloquant (pas de croix, pas de clic overlay) TANT QUE le polling est
 * actif. Contient : QR, boutons d'ouverture wallet, compte à rebours, statut.
 * À l'expiration du délai → devient fermable + bouton « J'ai payé / Vérifier ».
 * opts.resumed=true : reprise (l'état du timer est recalculé depuis deadline). */
function openPayModal(d, opts){
  opts=opts||{};
  if(_modalOpen) return;   // idempotent : déjà ouvert (reload + visibilitychange)
  _modalOpen=true; _cur=d;
  let ov=$('#payModal');
  if(!ov){
    ov=document.createElement('div'); ov.className='pay-modal'; ov.id='payModal';
    ov.innerHTML=`<div class="pm-card">
      <button type="button" class="pm-close" aria-label="${esc(T('fermer'))}">✕</button>
      <h3 class="pm-titre"></h3>
      <div class="pm-timer"></div>
      <div class="pm-qr"></div>
      <div class="pm-hint"></div>
      <div class="pm-acts"></div>
      <div class="pm-statut wait"></div>
    </div>`;
    document.body.appendChild(ov);
    ov.querySelector('.pm-close').addEventListener('click', closePayModal);
  }
  ov.classList.remove('expired','done');
  ov.querySelector('.pm-titre').textContent=T('don_modal_titre');
  const tim=ov.querySelector('.pm-timer'); tim.style.display='';
  // QR (base64 PNG). Pas d'apostrophe possible dans du base64 → interpolation sûre.
  const qrEl=ov.querySelector('.pm-qr');
  qrEl.innerHTML = d.qrCode ? `<img src="data:image/png;base64,${esc(d.qrCode)}" alt="QR ${esc(d.canal)}">` : '';
  ov.querySelector('.pm-hint').textContent=d.canal+' · '+T('don_payez_hint');
  // Boutons wallet : construits en DOM, lien capturé en closure (jamais interpolé
  // en HTML inline car esc() n'échappe pas l'apostrophe — cf. MIN-001).
  const acts=ov.querySelector('.pm-acts'); acts.innerHTML='';
  payLinks(d).forEach(l=>{
    const b=document.createElement('button'); b.type='button'; b.className='cta';
    b.textContent='📱 '+l.label;
    b.addEventListener('click',()=>window.open(l.url,'_blank','noopener'));
    acts.appendChild(b);
  });
  setPayStatut('wait', T('don_en_cours'));
  ov.classList.add('on');
  startTimer();
  startPoll();
}

/* Ferme le modal (uniquement possible en état fermable : expiré ou terminal).
 * La fermeture explicite efface la note : l'utilisateur a fini avec ce paiement. */
function closePayModal(){
  const ov=$('#payModal'); if(ov) ov.classList.remove('on');
  stopPoll(); clearInterval(_tick); _tick=null;
  _modalOpen=false; _cur=null;
  clearPending();
}
window.closePayModal=closePayModal;

/* Compte à rebours (délai de paiement). À 0 → onTimeout(). */
function startTimer(){
  clearInterval(_tick);
  const paint=()=>{
    const el=document.querySelector('#payModal .pm-timer'); if(!el||!_cur)return;
    const ms=_cur.deadline-Date.now();
    if(ms<=0){ el.textContent='00:00'; clearInterval(_tick); _tick=null; onTimeout(); return; }
    const s=Math.ceil(ms/1000), m=Math.floor(s/60), ss=s%60;
    const pad=n=>String(n).padStart(2,'0');
    el.textContent=T('don_modal_delai')+' '+pad(m)+':'+pad(ss);
  };
  paint();
  _tick=setInterval(paint,1000);
}

/* Polling du statut, borné par la deadline. Handle unique (_poll) : si un poll
 * tourne déjà (onglet resté ouvert + visibilitychange), on ne relance pas. */
function startPoll(){
  if(_poll||!_cur) return;
  if(Date.now()>=_cur.deadline) return;   // déjà expiré → pas de polling
  _poll=setInterval(async()=>{
    if(!_cur||Date.now()>=_cur.deadline){ stopPoll(); return; }
    const r=await api('api/pay/status.php?uuid='+encodeURIComponent(_cur.uuid));
    if(r&&r.success&&r.statut==='confirme') onConfirmed();
    else if(r&&r.statut==='echoue') onFailed();
    // 'en_attente' (PENDING/PROCESSING) → on continue.
  }, POLL_INTERVAL_MS);
}
function stopPoll(){ if(_poll){ clearInterval(_poll); _poll=null; } }

/* Vérification unique à la demande (bouton « J'ai payé / Vérifier »). */
async function checkOnce(){
  if(!_cur) return;
  setPayStatut('wait', T('don_verification'));
  const r=await api('api/pay/status.php?uuid='+encodeURIComponent(_cur.uuid));
  if(r&&r.success&&r.statut==='confirme') onConfirmed();
  else if(r&&r.statut==='echoue') onFailed();
  else setPayStatut('wait', T('don_verification'));
}

/* Transitions terminales (idempotentes). */
function onConfirmed(){
  stopPoll(); clearInterval(_tick); _tick=null; clearPending();
  markTerminal('ok');
  loadSoutiens();  // rafraîchit le carrousel des soutiens
}
function onFailed(){
  stopPoll(); clearInterval(_tick); _tick=null; clearPending();
  markTerminal('err');
}

/* Délai dépassé sans statut terminal : surtout NE PAS affirmer un échec
 * (reconcile.php rattrapera). Modal fermable + bouton « J'ai payé / Vérifier ».
 * La note localStorage reste tant que l'utilisateur ne ferme pas (le bouton
 * Vérifier en a besoin). */
function onTimeout(){
  stopPoll();
  const ov=$('#payModal'); if(!ov)return;
  ov.classList.add('expired');   // révèle la croix de fermeture (CSS)
  const t=ov.querySelector('.pm-titre'); if(t) t.textContent=T('don_delai_depasse');
  setPayStatut('wait', T('don_verification'));
  const acts=ov.querySelector('.pm-acts');
  if(acts && !acts.querySelector('.pm-verify')){
    const vb=document.createElement('button'); vb.type='button'; vb.className='cta pm-verify';
    vb.textContent='✅ '+T('don_jai_paye');
    vb.addEventListener('click', checkOnce);
    acts.insertBefore(vb, acts.firstChild);
  }
}

/* État terminal (confirmé/échoué) : le titre porte l'issue, le QR / le hint /
 * le timer / le statut d'attente n'ont plus de sens → masqués (sinon un titre
 * « Paiement en cours » resterait au-dessus d'un « Merci », au-dessus du QR).
 * Modal fermable, actions remplacées par « Nouveau don ». Idempotent.
 * kind : 'ok' (confirmé) | 'err' (échoué). */
function markTerminal(kind){
  clearInterval(_tick); _tick=null;
  const ov=$('#payModal'); if(!ov)return;
  ov.classList.add('expired','done');
  ['.pm-timer','.pm-qr','.pm-hint','.pm-statut'].forEach(sel=>{
    const el=ov.querySelector(sel); if(el) el.style.display='none';
  });
  const t=ov.querySelector('.pm-titre');
  if(t) t.textContent=(kind==='ok'?'✅ ':'⚠️ ')+T(kind==='ok'?'don_merci':'don_echec');
  const acts=ov.querySelector('.pm-acts');
  if(acts){
    acts.style.display='';
    acts.innerHTML='';
    const nb=document.createElement('button'); nb.type='button'; nb.className='cta ghost';
    nb.textContent='↺ '+T('don_nouveau');
    nb.addEventListener('click', ()=>{ closePayModal(); razDon(); });
    acts.appendChild(nb);
  }
}

/* Reprise : si une note de paiement en cours existe (et n'a pas dépassé le TTL
 * dur), ré-ouvre le modal et reprend le polling. Appelée au chargement et au
 * retour d'onglet (visibilitychange). Idempotente (garde _modalOpen). */
function resumePendingIfAny(){
  if(_modalOpen) return;
  const p=loadPending();
  if(!p||!p.uuid) return;
  if(Date.now()-(p.ts||0) > TTL_MS){ clearPending(); return; }
  openPayModal(p,{resumed:true});
}

/* Réinitialise le formulaire pour un nouveau don. */
function razDon(){
  const form=$('#donForm'); if(form){form.reset();}
  // Rétablit OM coché par défaut (form.reset() garde les radios initiaux, mais on s'assure).
  const om=document.querySelector('input[name=canal][value=OM]'); if(om)om.checked=true;
  updateCanalIndic();
}
window.razDon=razDon;

/* Met à jour le statut affiché dans le modal. */
function setPayStatut(type, text){
  const el=document.querySelector('#payModal .pm-statut'); if(!el)return;
  el.className='pm-statut '+(type==='ok'?'ok':type==='wait'?'wait':'err');
  el.textContent=text;
}

/* ---- Carrousel des soutiens (fetch live) ---- */
async function loadSoutiens(){
  const root=$('#soutiensCaro'); if(!root)return;
  const r=await api('api/pay/liste.php?limit=20').catch(()=>null);
  const items=(r&&r.success&&Array.isArray(r.soutiens))?r.soutiens:[];
  if(!items.length){
    root.innerHTML='<p class="muted" style="text-align:center">—</p>';
    root.style.minHeight='auto';
    return;
  }
  const cards=items.map(s=>soutienCard(s));
  if(REDUCED){
    // Liste statique (accessibilité).
    root.innerHTML=`<div class="sc-list">${cards.join('')}</div>`;
  }else{
    // Carrousel animé paginé (nouveau moteur cartes).
    initSoutiensCaro(root,items);
  }
}

function soutienCard(s){
  const canal=s.canal==='WAVE'?'wave.png':'om.png';
  const nom=s.nom_affiche||T('soutien_anonyme');
  const num=s.numero_masque||'';
  return `<article class="soutien-card">
    <div class="sc-num">${esc(num)}</div>
    <div class="sc-nom">${esc(nom)}</div>
    <div class="sc-canal"><img src="icone/${canal}" alt="">${esc(s.canal)}</div>
  </article>`;
}

/* Nouveau moteur carrousel pour les cartes de soutiens (texte, pas images).
 * Réutilise les classes .jcaro-stage/.jcaro/.jslide/.jarr/.jdots de site.css,
 * mais avec un rendu de cartes. Logique calquée sur initCarousel (site.js). */
function initSoutiensCaro(root,items){
  root.classList.add('jcaro-stage');
  root.innerHTML=`<div class="jcaro"></div>
    <button class="jarr l" aria-label="prev">‹</button>
    <button class="jarr r" aria-label="next">›</button>
    <div class="jdots"></div>`;
  const caro=root.querySelector('.jcaro'),dots=root.querySelector('.jdots');
  items.forEach((s,i)=>{
    const sl=document.createElement('div');sl.className='jslide';
    sl.innerHTML=soutienCard(s);
    sl.addEventListener('click',()=>{
      if(sl.classList.contains('next'))go(cur+1);
      else if(sl.classList.contains('prev'))go(cur-1);
    });
    caro.appendChild(sl);
    const b=document.createElement('button');b.type='button';
    b.setAttribute('aria-label','Soutien '+(i+1));
    b.addEventListener('click',()=>go(i));dots.appendChild(b);
  });
  const slides=[...caro.children],dbs=[...dots.children];
  let cur=0,timer=null;
  const mod=i=>((i%items.length)+items.length)%items.length;
  function render(){
    slides.forEach((sl,i)=>{
      sl.className='jslide'+(i===cur?' cur':i===mod(cur-1)&&items.length>1?' prev':i===mod(cur+1)&&items.length>1?' next':'');
    });
    dbs.forEach((b,i)=>b.classList.toggle('on',i===cur));
  }
  function go(i){cur=mod(i);render();restart();}
  function restart(){clearInterval(timer);
    if(items.length>1)timer=setInterval(()=>{cur=mod(cur+1);render();},3800);}
  root.querySelector('.jarr.l').addEventListener('click',()=>go(cur-1));
  root.querySelector('.jarr.r').addEventListener('click',()=>go(cur+1));
  root.addEventListener('mouseenter',()=>clearInterval(timer));
  root.addEventListener('mouseleave',restart);
  let tx=null;
  root.addEventListener('touchstart',e=>{tx=e.touches[0].clientX;},{passive:true});
  root.addEventListener('touchend',e=>{
    if(tx==null)return;const dx=e.changedTouches[0].clientX-tx;
    if(Math.abs(dx)>40)go(cur+(dx<0?1:-1));tx=null;},{passive:true});
  render();restart();
}

/* ---- Helpers UI ---- */
function showMsg(type,text){
  const msg=$('#donMsg'); if(!msg)return;
  msg.className='don-msg '+type;
  msg.textContent=text;msg.style.display='block';
}

/* Modal auto-fermant : s'affiche centré, se ferme tout seul après 3,5 s
 * (barre de progression dorée) ou au clic sur l'overlay/le bouton. */
let _mtTimer=null;
function showModal(titre, texte, icone){
  let ov=document.querySelector('.mt-overlay');
  if(!ov){
    ov=document.createElement('div');ov.className='mt-overlay';
    ov.innerHTML=`<div class="mt-card">
      <div class="mt-ic"></div>
      <h3 class="mt-titre"></h3>
      <p class="mt-txt"></p>
      <button type="button" class="mt-btn"></button>
      <div class="mt-bar"></div></div>`;
    document.body.appendChild(ov);
    // Fermer au clic sur l'overlay (hors carte) ou le bouton.
    ov.addEventListener('click',e=>{ if(e.target===ov) closeModal(); });
    ov.querySelector('.mt-btn').addEventListener('click', closeModal);
  }
  ov.querySelector('.mt-ic').textContent=icone||'';
  ov.querySelector('.mt-titre').textContent=titre||'';
  ov.querySelector('.mt-txt').textContent=texte||'';
  ov.querySelector('.mt-btn').textContent=T('fermer')||'OK';
  // Relance la barre de progression (replay animation).
  const bar=ov.querySelector('.mt-bar');
  bar.style.animation='none'; void bar.offsetWidth; bar.style.animation='';
  ov.classList.add('on');
  clearTimeout(_mtTimer);
  _mtTimer=setTimeout(closeModal, 3500);
}
function closeModal(){
  const ov=document.querySelector('.mt-overlay'); if(!ov)return;
  ov.classList.remove('on');
  clearTimeout(_mtTimer);
}
window.closeModal=closeModal;  // exposé pour les onclick inline si besoin

if(document.readyState!=='loading')init();
else document.addEventListener('DOMContentLoaded',init);
})();
