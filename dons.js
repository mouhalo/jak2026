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
  // Carrousel + rattrapage (best-effort)
  loadSoutiens();
  api('api/pay/reconcile.php').catch(()=>{});  // déclenche le fallback throttlé
}

/* ---- Soumission du don ---- */
async function submitDon(e){
  e.preventDefault();
  const btn=$('#donSubmit'), msg=$('#donMsg');
  const montant=parseInt($('#fMontant').value,10);
  const canal=document.querySelector('input[name=canal]:checked').value;
  const telephone=$('#fTel').value.replace(/\D/g,'');
  const nom=$('#fNom').value.trim()||null;
  if(!montant||montant<100||montant>2000000){showMsg('err',T('don_montant'));return;}
  if(telephone.length!==9){showMsg('err',T('don_telephone'));return;}
  btn.disabled=true; showMsg('ok',T('don_en_cours'));
  const r=await api('api/pay/create.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({montant,canal,telephone,nom})});
  btn.disabled=false;
  if(!r.success){showMsg('err',r.message||T('don_echec'));return;}
  // Succès : on a uuid + payment_url (Wave) ou om/qrCode (OM).
  // On démarre le polling en parallèle du paiement (best-effort).
  if(r.uuid){pollStatut(r.uuid);}
  if(canal==='OM'){
    // OM : afficher le QR OU rediriger vers om. On redirige (plus simple).
    showMsg('ok',T('don_redir_om'));
    if(r.om||r.maxit){setTimeout(()=>location.href=r.om||r.maxit,1200);}
    else if(r.qrCode){showQr(r.qrCode);}
    else {showMsg('ok',T('don_merci'));}
  }else{
    // WAVE : payment_url à ouvrir dans un nouvel onglet.
    showMsg('ok',T('don_merci'));
    if(r.payment_url){window.open(r.payment_url,'_blank','noopener');}
    else {showMsg('err',T('don_echec'));}  // INTOUCH down → pas d'URL
  }
}

/* ---- Polling du statut (UX temps réel) ---- */
function pollStatut(uuid){
  let n=0;
  const t=setInterval(async()=>{
    if(++n>40){clearInterval(t);return;}  // ~2 min max
    const r=await api('api/pay/status.php?uuid='+encodeURIComponent(uuid));
    if(r&&r.success&&r.statut==='confirme'){
      clearInterval(t);
      showMsg('ok',T('don_merci'));
      loadSoutiens();  // rafraîchit le carrousel
    }else if(r&&r.statut==='echoue'){
      clearInterval(t);
      showMsg('err',T('don_echec'));
    }
  },3000);
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
function showQr(b64){
  const msg=$('#donMsg');
  msg.className='don-msg ok';
  msg.innerHTML='<img src="data:image/png;base64,'+esc(b64)+'" alt="QR Orange Money" style="max-width:200px;border-radius:8px">';
  msg.style.display='block';
}

if(document.readyState!=='loading')init();
else document.addEventListener('DOMContentLoaded',init);
})();
