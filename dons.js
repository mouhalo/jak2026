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
  // Succès : on affiche la zone de paiement (QR + bouton) sous le formulaire.
  // Pas de redirection auto : l'utilisateur scanne le QR ou clique le bouton.
  // Le polling détecte le paiement en arrière-plan et met à jour le statut.
  showPayZone(r, canal);
  if(r.uuid){pollStatut(r.uuid);}
}

/* ---- Zone de paiement (QR + boutons) affichée sous le formulaire ---- */
function showPayZone(r, canal){
  // Retire un éventuel message d'attente, on passe en zone dédiée.
  const msg=$('#donMsg'); if(msg){msg.style.display='none';}
  let zone=$('#payZone');
  if(!zone){
    zone=document.createElement('div');zone.id='payZone';zone.className='pay-zone';
    // Insérée juste après le formulaire, dans le même .panel.
    const form=$('#donForm'); form.insertAdjacentElement('afterend',zone);
  }
  const qr = r.qrCode ? `<div class="pay-qr"><img src="data:image/png;base64,${esc(r.qrCode)}" alt="QR code ${esc(canal)}"></div>` : '';
  const link = r.payment_url || r.om || r.maxit || null;  // Wave=payment_url, OM=om/maxit
  const linkLabel = canal==='OM' ? '📱 Ouvrir Orange Money' : '📱 Ouvrir Wave';
  const btn = link ? `<button type="button" class="cta" id="payOpenBtn">${linkLabel}</button>` : '';
  const noQr = !r.qrCode && !link;
  zone.innerHTML = `
    ${qr}
    <div class="pay-acts">
      <div class="pay-hint">${esc(canal)} · ${T('don_payez_hint')}</div>
      ${btn}
      <div class="pay-statut wait" id="payStatut">${T('don_en_cours')}</div>
      <button type="button" class="cta ghost" onclick="razDon()">↺ ${T('don_nouveau')}</button>
    </div>
    ${noQr ? `<div class="pay-statut err">${T('don_echec')}</div>` : ''}`;
  // Ouverture du lien de paiement via un handler JS : le lien vient d'un tiers
  // (OFMS/INTOUCH) et esc() n'échappe PAS l'apostrophe → on ne l'interpole jamais
  // dans du HTML/JS inline (MIN-001). `link` reste une variable, en closure.
  if(link){
    const ob=$('#payOpenBtn');
    if(ob){ob.addEventListener('click',()=>window.open(link,'_blank','noopener'));}
  }
  zone.style.display='flex';
  // Scroll doux vers la zone.
  zone.scrollIntoView({behavior:'smooth',block:'center'});
}

/* Réinitialise le formulaire + retire la zone de paiement (nouveau don). */
function razDon(){
  const zone=$('#payZone'); if(zone){zone.remove();}
  const form=$('#donForm'); if(form){form.reset();}
  // Rétablit OM coché par défaut (form.reset() garde les radios initiaux, mais on s'assure).
  const om=document.querySelector('input[name=canal][value=OM]'); if(om)om.checked=true;
}
window.razDon=razDon;

/* ---- Polling du statut (UX temps réel) ----
 * On interroge status.php toutes les 3 s jusqu'à un statut TERMINAL :
 *   - 'confirme' (COMPLETED / SUCCESSFUL côté opérateur)
 *   - 'echoue'   (FAILED / CANCELED côté opérateur — cf. walletApi.js)
 * On patiente longtemps (60 × 3 s = 180 s) car la confirmation OM/Sonatel
 * peut prendre 1-2 min (saisie du code PIN, validation opérateur). Si le
 * délai est dépassé sans statut terminal, on n'affirme PAS un échec : on
 * indique que la vérification se poursuit (reconcile.php rattrapera). */
const POLL_MAX=60, POLL_INTERVAL_MS=3000;   // 60 × 3 s = 180 s (≥ 90 s requis)
function pollStatut(uuid){
  let n=0;
  const t=setInterval(async()=>{
    if(++n>POLL_MAX){
      clearInterval(t);
      // Délai dépassé : statut encore non terminal → surtout ne pas dire "échec".
      setPayStatut('wait', T('don_en_cours'));
      return;
    }
    const r=await api('api/pay/status.php?uuid='+encodeURIComponent(uuid));
    if(r&&r.success&&r.statut==='confirme'){
      clearInterval(t);
      setPayStatut('ok', T('don_merci'));
      loadSoutiens();  // rafraîchit le carrousel
    }else if(r&&r.statut==='echoue'){
      clearInterval(t);
      setPayStatut('err', T('don_echec'));
    }
    // 'en_attente' (PENDING/PROCESSING) → on continue de poller.
  },POLL_INTERVAL_MS);
}

/* Met à jour le statut affiché dans la zone de paiement. */
function setPayStatut(type, text){
  const el=$('#payStatut'); if(!el)return;
  el.className='pay-statut '+(type==='ok'?'ok':type==='wait'?'wait':'err');
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
