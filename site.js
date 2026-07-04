/* Éléments partagés : i18n, navigation, footer, compte à rebours */
(function(){
'use strict';
const I18N={
 fr:{nav_accueil:"Le Cheikh",nav_dign:"Dignitaires",nav_jak:"Jeunesse Al Khaïry",nav_galerie:"Galerie",nav_acces:"Plan & Accès",
   bio:"Biographie",adresse:"Adresse",citations:"Enseignements & citations",programme:"Programme",
   galerie:"Galerie",soutien:"Soutenir l'événement",contact:"Contact",
   cta_venue:"Préparer ma venue",cta_wa:"Écrire sur WhatsApp",copie:"Numéro copié ✓",
   j:"Jours",h:"Heures",m:"Min",s:"Sec",encours:"L'événement est en cours !",
   dign_intro:"Les dignitaires des dahira accompagnent la visite du Cheikh. Le jour de la cérémonie, ils sont accueillis par des entrées dédiées (cartes Verte et Orange) et prennent place au premier rang.",
   jak_membres:"Les membres",fondateur:"Membre fondateur",fonction:"Fonction",
   soutien_txt:"Votre contribution aide à organiser dignement la visite du Cheikh. Contribuez par Wave ou Orange Money :",
   protocole:"Protocole du jour",
   protocole_txt:"Entrées dédiées cartes Verte (hommes, porte Est) et Orange (femmes, porte Ouest) · Placement au 1er rang face au podium · Appel nominatif pour les discours, escorte vers le podium, un orateur à la fois.",
   footer:"Organisé par Jeunesse Al Khaïry · CICES, Dakar",
   acces_membre:"Accès membre", deconnexion:"Se déconnecter",
   otp_envoi:"Recevez un code par WhatsApp", otp_tel:"Votre téléphone (9 chiffres)",
   otp_code:"Code à 6 chiffres", otp_valider:"Valider", enregistrer:"Enregistrer",
   ma_fiche:"Ma fiche", saved_ok:"Fiche enregistrée ✓"},
 en:{nav_accueil:"The Cheikh",nav_dign:"Dignitaries",nav_jak:"Jeunesse Al Khaïry",nav_galerie:"Gallery",nav_acces:"Map & Access",
   bio:"Biography",adresse:"Address",citations:"Teachings & quotes",programme:"Programme",
   galerie:"Gallery",soutien:"Support the event",contact:"Contact",
   cta_venue:"Plan my visit",cta_wa:"Message on WhatsApp",copie:"Number copied ✓",
   j:"Days",h:"Hours",m:"Min",s:"Sec",encours:"The event is underway!",
   dign_intro:"The dahira dignitaries accompany the Cheikh's visit. On ceremony day they are welcomed through dedicated gates (Green and Orange cards) and seated in the front row.",
   jak_membres:"Members",fondateur:"Founding member",fonction:"Role",
   soutien_txt:"Your contribution helps organise the Cheikh's visit with dignity. Contribute via Wave or Orange Money:",
   protocole:"Ceremony protocol",
   protocole_txt:"Dedicated gates: Green card (men, East gate) and Orange card (women, West gate) · Front-row seating facing the podium · Speakers called by name and escorted, one at a time.",
   footer:"Organised by Jeunesse Al Khaïry · CICES, Dakar"},
 wo:{nav_accueil:"Cheikh bi",nav_dign:"Ñi am maqaama",nav_jak:"Jeunesse Al Khaïry",nav_galerie:"Nataal yi",nav_acces:"Palaŋ & Yoon",
   bio:"Jaar-jaaram",adresse:"Dëkkuwaay",citations:"Njàngale ak i wax",programme:"Prograam",
   galerie:"Nataal yi",soutien:"Jàppale xew mi",contact:"Jokkoo",
   cta_venue:"Waajal sama ñëw",cta_wa:"Bind ci WhatsApp",copie:"Numero bi copié ✓",
   j:"Fan",h:"Waxtu",m:"Simili",s:"Saa",encours:"Xew mi a ngi dox !",
   dign_intro:"Ñi am maqaama ci dahira yi ñoo y gunge ganesi Cheikh bi. Bés bu ndaje mi, bunt yu ñu leen jagleel (kàrt Werte ak Orange) te ñu toog ci rang bu jëkk.",
   jak_membres:"Way-bokk yi",fondateur:"Ku sos mbootaay gi",fonction:"Liggéey",
   soutien_txt:"Sa ndimbal day tax ba ganesi Cheikh bi am ci teraanga. Jàppale ci Wave walla Orange Money :",
   protocole:"Doxalinu bés bi",
   protocole_txt:"Bunt yu jagleel : kàrt Werte (góor, penku) ak Orange (jigéen, sowwu) · Toogu ci rang bu jëkk · Ku ñu woo ci turam rekk a fay yéeg ci podium bi, kenn-kenn.",
   footer:"Jeunesse Al Khaïry moo ko tërël · CICES, Dakar"},
 ff:{nav_accueil:"Ceerno oo",nav_dign:"Tedduɓe",nav_jak:"Jeunesse Al Khaïry",nav_galerie:"Nate",nav_acces:"Kartal & Naatirde",
   bio:"Nguurndam makko",adresse:"Ñiiɓirde",citations:"Jaŋde e konnguɗi",programme:"Prograam",
   galerie:"Nate",soutien:"Wallitde dille ɗe",contact:"Jokkondiral",
   cta_venue:"Heblo garal am",cta_wa:"Winndu e WhatsApp",copie:"Limmoore ndee copié ✓",
   j:"Ñalɗi",h:"Waktuuji",m:"Hojom",s:"Leƴƴere",encours:"Dille ɗe ina ndogi !",
   dign_intro:"Tedduɓe dahiraaji ɗi ina nduwondira e njillu Ceerno oo. Ñalnde ndaje nge, ɓe naatirta damalji keeriiɗi (karte Werte e Orange), ɓe njooɗoo e laylol aranol.",
   jak_membres:"Terɗe fedde nde",fondateur:"Sincuɗo fedde",fonction:"Golle",
   soutien_txt:"Ballal maa ina walla yuɓɓinde njillu Ceerno oo no moƴƴiri. Wallit e Wave maa Orange Money :",
   protocole:"Yamiroore ñalnde nde",
   protocole_txt:"Damalji keeriiɗi : karte Werte (worɓe, fuɗnaange) e Orange (rewɓe, hiirnaange) · Jooɗorde e laylol aranol · Noddaaɗo tan ƴeeŋata podium, gooto gooto.",
   footer:"Ko Jeunesse Al Khaïry yuɓɓini · CICES, Dakar"} ,
 ar:{nav_accueil:"الشيخ",nav_dign:"الأعيان",nav_jak:"شبيبة الخيري",nav_galerie:"معرض الصور",nav_acces:"الخريطة والدخول",
   bio:"السيرة",adresse:"العنوان",citations:"تعاليم وأقوال",programme:"البرنامج",
   galerie:"معرض الصور",soutien:"دعم الحدث",contact:"اتصل بنا",
   cta_venue:"تحضير زيارتي",cta_wa:"مراسلة عبر واتساب",copie:"تم نسخ الرقم ✓",
   j:"أيام",h:"ساعات",m:"دقائق",s:"ثوانٍ",encours:"الحدث جارٍ الآن !",
   dign_intro:"يرافق أعيانُ الدوائر زيارةَ الشيخ. في يوم الحفل يُستقبلون عبر مداخل مخصصة (البطاقة الخضراء والبرتقالية) ويجلسون في الصف الأول.",
   jak_membres:"الأعضاء",fondateur:"عضو مؤسس",fonction:"المهمة",
   soutien_txt:"مساهمتكم تساعد على تنظيم زيارة الشيخ على أحسن وجه. ساهموا عبر Wave أو Orange Money :",
   protocole:"بروتوكول اليوم",
   protocole_txt:"مداخل مخصصة : البطاقة الخضراء (رجال، البوابة الشرقية) والبرتقالية (نساء، البوابة الغربية) · الجلوس في الصف الأول أمام المنصة · يُنادى على المتحدث باسمه ويُرافَق، واحدًا تلو الآخر.",
   footer:"من تنظيم شبيبة الخيري · مركز CICES، داكار"}
};
/* le brouillon enregistré dans admin.html est appliqué en direct sur cet appareil */
try{
  const _dr=localStorage.getItem('admin_draft');
  if(_dr){const _d=JSON.parse(_dr);if(_d&&_d.settings&&_d.jak)window.SITE_DATA=_d;}
}catch(e){}

let lang='fr';
try{lang=localStorage.getItem('site_lang')||'fr';}catch(e){}
if(!I18N[lang])lang='fr';
document.documentElement.dir=(lang==='ar')?'rtl':'ltr';
window.T=k=>(I18N[lang]&&I18N[lang][k])||I18N.fr[k]||k;
window.SITE_LANG=()=>lang;

document.addEventListener('contextmenu',e=>{if(e.target.closest('img,figure,.slide,.gal,.ph'))e.preventDefault();});
document.addEventListener('dragstart',e=>{if(e.target&&e.target.tagName==='IMG')e.preventDefault();});

/* Drapeaux SVG inline (rendu identique partout, y compris Windows). Wolof et
   Pulaar (sans pays propre) partagent le drapeau du Sénégal, distingués par le code. */
const FLAG={
  fr:'<svg class="flag" viewBox="0 0 6 4" aria-hidden="true"><rect width="6" height="4" fill="#fff"/><rect width="2" height="4" fill="#0055A4"/><rect x="4" width="2" height="4" fill="#EF4135"/></svg>',
  sn:'<svg class="flag" viewBox="0 0 6 4" aria-hidden="true"><rect width="2" height="4" fill="#00853F"/><rect x="2" width="2" height="4" fill="#FDEF42"/><rect x="4" width="2" height="4" fill="#E31B23"/><path d="M3 1.35l.221.68h.716l-.579.42.221.681L3 3.39l-.579.42.221-.68-.579-.42h.716z" fill="#00853F"/></svg>',
  sa:'<svg class="flag" viewBox="0 0 6 4" aria-hidden="true"><rect width="6" height="4" fill="#006C35"/><rect x="1" y="2.7" width="4" height="0.25" rx=".12" fill="#fff"/></svg>',
  gb:'<svg class="flag" viewBox="0 0 60 30" aria-hidden="true"><rect width="60" height="30" fill="#012169"/><path d="M0,0 60,30M60,0 0,30" stroke="#fff" stroke-width="6"/><path d="M0,0 60,30M60,0 0,30" stroke="#C8102E" stroke-width="4"/><path d="M30,0V30M0,15H60" stroke="#fff" stroke-width="10"/><path d="M30,0V30M0,15H60" stroke="#C8102E" stroke-width="6"/></svg>'
};
const LANGMETA={
  fr:{name:'Français',flag:FLAG.fr}, wo:{name:'Wolof',flag:FLAG.sn},
  ar:{name:'العربية',flag:FLAG.sa}, ff:{name:'Pulaar',flag:FLAG.sn},
  en:{name:'English',flag:FLAG.gb}
};
const LANGORDER=['fr','wo','ar','ff','en'];

window.renderChrome=function(active){
  const S=window.SITE_DATA.settings;
  const nav=document.createElement('nav');nav.className='site';
  nav.innerHTML=`<div class="in">
    <img class="logo" src="logo.png" alt="JAK" onerror="this.style.display='none'">
    <span class="brand">${S.organisateur}<small>${S.dates_texte}</small></span>
    <a href="index.html" class="${active==='accueil'?'on':''}">${T('nav_accueil')}</a>
    <a href="dignitaires.html" class="${active==='dign'?'on':''}">${T('nav_dign')}</a>
    <a href="jak.html" class="${active==='jak'?'on':''}">${T('nav_jak')}</a>
    <a href="galerie.html" class="${active==='galerie'?'on':''}">${T('nav_galerie')}</a>
    <a href="Mon_Acces_2026.html">${T('nav_acces')}</a>
    <div class="langsel" role="group" aria-label="Langue">${LANGORDER.map(lg=>
      `<button type="button" class="langbtn${lg===lang?' on':''}" data-lang="${lg}" aria-label="${LANGMETA[lg].name}" title="${LANGMETA[lg].name}">${LANGMETA[lg].flag}<span>${lg.toUpperCase()}</span></button>`
    ).join('')}</div></div>`;
  document.body.prepend(nav);
  nav.querySelectorAll('.langbtn').forEach(b=>b.addEventListener('click',()=>{
    try{localStorage.setItem('site_lang',b.dataset.lang);}catch(e){}
    location.reload();
  }));
  const f=document.createElement('footer');f.className='site';
  f.innerHTML=`<img src="logo.png" alt="" onerror="this.style.display='none'"><br>
    ${S.event_nom} — ${S.dates_texte}<br>${T('footer')}`;
  document.body.appendChild(f);
};

window.startCountdown=function(elId){
  const el=document.getElementById(elId);if(!el)return;
  const target=new Date(window.SITE_DATA.settings.date_compte_rebours).getTime();
  function tick(){
    const d=target-Date.now();
    if(d<=0){el.innerHTML=`<div class="u" style="min-width:auto;padding:12px 22px"><div class="n" style="font-size:22px">${T('encours')}</div></div>`;return;}
    const j=Math.floor(d/864e5),h=Math.floor(d%864e5/36e5),m=Math.floor(d%36e5/6e4),s=Math.floor(d%6e4/1e3);
    el.innerHTML=[[j,'j'],[h,'h'],[m,'m'],[s,'s']].map(([v,k])=>
      `<div class="u"><div class="n">${v}</div><div class="l">${T(k)}</div></div>`).join('');
    setTimeout(tick,1000);
  }
  tick();
};

window.esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
window.todoWrap=s=>{const e=esc(s);return /\[À COMPLÉTER|\[A COMPLETER|\[À CONFIRMER/i.test(s)?`<span class="todo">${e}</span>`:e;};

window.personCard=function(p,extra){
  const ph=p.photo?`<img src="${esc(p.photo)}" alt="${esc(p.nom_complet)}" onerror="this.outerHTML='<div class=noimg>؟</div>'">`
                  :`<div class="noimg">${esc((p.nom_complet||'?').replace(/\[.*?\]/,'?').trim().charAt(0)||'?')}</div>`;
  return `<article class="pcard"><div class="ph">${ph}</div><div class="bd">
    ${extra&&extra.fondateur?`<span class="badge-f">★ ${T('fondateur')}</span>`:''}
    <h3>${todoWrap(p.nom_complet)}</h3>
    ${extra&&extra.fonction?`<span class="fn">${todoWrap(extra.fonction)}</span>`:''}
    <div class="bio">${todoWrap(p.biographie)}</div>
    <div class="adr">📍 ${todoWrap(p.adresse)}</div></div></article>`;
};

window.soutienBlock=function(){
  const S=window.SITE_DATA.settings;
  const cards=(S.whatsapp||[]).filter(Boolean).map(n=>{
    const digits=n.replace(/\D/g,'');
    return `<div class="ctc">
      <div class="ctc-num">${esc(n)}</div>
      <div class="ctc-ways">
        <button class="way wv" data-copy="${digits}"><img src="icone/wave.png" alt="Wave"><span>Wave</span></button>
        <button class="way om" data-copy="${digits}"><img src="icone/om.png" alt="Orange Money"><span>OM</span></button>
        <a class="way wa" href="https://wa.me/221${digits}" target="_blank" rel="noopener">
          <img src="icone/whatsapp.png" alt="WhatsApp"><span>WhatsApp</span></a>
      </div></div>`;
  }).join('');
  return `<section id="soutien"><div class="wrap">
    <h2 class="sec-label">${T('soutien')}</h2>
    <div class="panel"><p>${T('soutien_txt')}</p>
      <div class="ctc-grid">${cards}</div>
      ${S.note_soutien?`<p class="muted" style="margin-top:10px;font-size:12px">${todoWrap(S.note_soutien)}</p>`:''}
    </div></div></section>`;
};

/* copie du numéro (Wave / OM) + toast */
document.addEventListener('click',e=>{
  const b=e.target.closest('[data-copy]');
  if(!b)return;
  const num=b.dataset.copy;
  const done=()=>{
    let t=document.querySelector('.toast');
    if(!t){t=document.createElement('div');t.className='toast';document.body.appendChild(t);}
    t.textContent=T('copie');t.classList.add('on');
    setTimeout(()=>t.classList.remove('on'),1800);
  };
  if(navigator.clipboard&&navigator.clipboard.writeText)
    navigator.clipboard.writeText(num).then(done,done);
  else done();
});

/* ============ CARROUSEL PARTAGÉ ============ */
window.initCarousel=function(root,items,opts){
  opts=opts||{};
  if(!items||!items.length){root.style.display='none';return null;}
  root.classList.add('jcaro-stage');
  root.innerHTML=`<div class="jcaro"></div>
    <button class="jarr l" aria-label="prev">‹</button>
    <button class="jarr r" aria-label="next">›</button>
    <div class="jdots"></div><div class="jcnt"></div>`;
  const caro=root.querySelector('.jcaro'),dots=root.querySelector('.jdots');
  items.forEach((g,i)=>{
    const sl=document.createElement('div');sl.className='jslide';
    sl.innerHTML=`<img src="${esc(g.src)}" alt="${esc(g.legende||'')}" loading="lazy">`
      +(g.legende?`<div class="jcap">${esc(g.legende)}</div>`:'');
    sl.addEventListener('click',()=>{
      if(sl.classList.contains('next'))go(cur+1);
      else if(sl.classList.contains('prev'))go(cur-1);
      else openLightbox(items,cur);
    });
    caro.appendChild(sl);
    const b=document.createElement('button');
    b.type='button';b.setAttribute('aria-label','Diapositive '+(i+1));
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
    root.querySelector('.jcnt').textContent=(cur+1)+' / '+items.length;
  }
  function go(i){cur=mod(i);render();restart();}
  function restart(){clearInterval(timer);
    if(items.length>1)timer=setInterval(()=>{cur=mod(cur+1);render();},opts.delay||3800);}
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
  return {go,getCur:()=>cur,count:items.length};
};

/* ============ LIGHTBOX PLEIN ÉCRAN ============ */
let LBX=null,lbxItems=[],lbxCur=0;
function lbxBuild(){
  if(LBX)return;
  LBX=document.createElement('div');LBX.className='lbx';
  LBX.innerHTML=`
    <button class="lbx-x" aria-label="Fermer">✕</button>
    <div class="lbx-frame">
      <span class="lbx-ring r1"></span><span class="lbx-ring r2"></span>
      <span class="lbx-dot d1"></span><span class="lbx-dot d2"></span>
      <span class="lbx-dot d3"></span><span class="lbx-dot d4"></span>
      <img alt="">
    </div>
    <div class="lbx-cap"></div>
    <div class="lbx-info">
      <span class="lbx-badge"></span>
      <h3></h3><div class="lfn"></div><p class="lbio"></p><div class="ladr"></div>
    </div>
    <div class="lbx-cnt"></div>
    <button class="lbx-nav l" aria-label="prev">‹</button>
    <button class="lbx-nav r" aria-label="next">›</button>`;
  document.body.appendChild(LBX);
  LBX.querySelector('.lbx-x').addEventListener('click',closeLightbox);
  LBX.addEventListener('click',e=>{if(e.target===LBX)closeLightbox();});
  LBX.querySelector('.lbx-nav.l').addEventListener('click',()=>lbxGo(lbxCur-1));
  LBX.querySelector('.lbx-nav.r').addEventListener('click',()=>lbxGo(lbxCur+1));
  document.addEventListener('keydown',e=>{
    if(!LBX.classList.contains('on'))return;
    if(e.key==='Escape')closeLightbox();
    if(e.key==='ArrowLeft')lbxGo(lbxCur-1);
    if(e.key==='ArrowRight')lbxGo(lbxCur+1);
  });
  let tx=null;
  LBX.addEventListener('touchstart',e=>{tx=e.touches[0].clientX;},{passive:true});
  LBX.addEventListener('touchend',e=>{
    if(tx==null)return;const dx=e.changedTouches[0].clientX-tx;
    if(Math.abs(dx)>40)lbxGo(lbxCur+(dx<0?1:-1));tx=null;},{passive:true});
}
function lbxGo(i){
  lbxCur=((i%lbxItems.length)+lbxItems.length)%lbxItems.length;
  const g=lbxItems[lbxCur],img=LBX.querySelector('.lbx-frame img');
  img.style.opacity=0;
  setTimeout(()=>{img.src=g.src;img.onload=()=>{img.style.opacity=1;};},130);
  const rich=!!(g.bio||g.fonction||g.adresse||g.fondateur);
  LBX.classList.toggle('rich',rich);
  LBX.querySelector('.lbx-cap').textContent=rich?'':(g.legende||'');
  const info=LBX.querySelector('.lbx-info');
  info.style.display=rich?'':'none';
  if(rich){
    info.querySelector('h3').textContent=g.legende||'';
    const bd=info.querySelector('.lbx-badge');
    bd.style.display=g.fondateur?'':'none';
    bd.textContent=g.fondateur?('★ '+T('fondateur')):'';
    const fn=info.querySelector('.lfn');
    fn.style.display=g.fonction?'':'none';fn.textContent=g.fonction||'';
    const bio=info.querySelector('.lbio');
    bio.style.display=g.bio?'':'none';bio.textContent=g.bio||'';
    const ad=info.querySelector('.ladr');
    ad.style.display=g.adresse?'':'none';ad.textContent=g.adresse?('📍 '+g.adresse):'';
  }
  LBX.querySelector('.lbx-cnt').textContent=(lbxCur+1)+' / '+lbxItems.length;
  const one=lbxItems.length<2;
  LBX.querySelector('.lbx-nav.l').style.display=one?'none':'';
  LBX.querySelector('.lbx-nav.r').style.display=one?'none':'';
}
window.openLightbox=function(items,index){
  lbxBuild();lbxItems=items;
  document.body.style.overflow='hidden';
  LBX.classList.add('on');
  lbxGo(index||0);
};
window.closeLightbox=function(){
  if(!LBX)return;
  LBX.classList.remove('on');
  document.body.style.overflow='';
};

/* clic sur une image de grille ou de carte -> lightbox */
document.addEventListener('click',e=>{
  const img=e.target.closest('.gal figure img,.pcard .ph img');
  if(!img)return;
  const scope=img.closest('.gal,.cards');
  const imgs=[...scope.querySelectorAll('figure img,.ph img')];
  const items=imgs.map(im=>{
    const fig=im.closest('figure,.pcard');
    if(!fig)return {src:im.getAttribute('src'),legende:''};
    const q=sel=>{const el=fig.querySelector(sel);return el?el.textContent.trim():'';};
    return {src:im.getAttribute('src'),legende:q('figcaption,h3'),
      fonction:q('.fn'),bio:q('.bio'),
      adresse:q('.adr').replace(/^\u{1F4CD}\s*/u,''),
      fondateur:!!fig.querySelector('.badge-f')};
  });
  openLightbox(items,imgs.indexOf(img));
});
})();
