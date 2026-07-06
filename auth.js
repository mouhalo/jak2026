/* Accès membre : connexion OTP WhatsApp + édition de sa fiche. */
(function(){
  'use strict';
  const $=s=>document.querySelector(s);
  const api=(u,b)=>fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})}).then(r=>r.json());

  const FLAG = window.FLAG || {};
  const COUNTRIES = [
    {iso:'sn', code:'221', name:'Sénégal',       len:9,  flag:FLAG.sn||''},
    {iso:'mr', code:'222', name:'Mauritanie',    len:8,  flag:FLAG.mr||''},
    {iso:'ml', code:'223', name:'Mali',          len:8,  flag:FLAG.ml||''},
    {iso:'gn', code:'224', name:'Guinée',        len:9,  flag:FLAG.gn||''},
    {iso:'ci', code:'225', name:"Côte d'Ivoire", len:10, flag:FLAG.ci||''},
    {iso:'gm', code:'220', name:'Gambie',        len:7,  flag:FLAG.gm||''},
    {iso:'gw', code:'245', name:'Guinée-Bissau', len:7,  flag:FLAG.gw||''},
    {iso:'fr', code:'33',  name:'France',        len:9,  flag:FLAG.fr||''},
  ];
  const DEFAULT_ISO = 'sn';
  const byIso = iso => COUNTRIES.find(c=>c.iso===iso) || COUNTRIES[0];

  // Découpe un numéro stocké (E.164 avec ou sans '+') en pays + national.
  // Choisit le code correspondant le plus LONG (245 avant 22, etc.).
  function splitE164(stored){
    const d = String(stored||'').replace(/\D/g,'');
    let best = null;
    for (const c of COUNTRIES){
      if (d.startsWith(c.code) && (!best || c.code.length>best.code.length)) best = c;
    }
    if (!best) return {iso:'', code:'', national:d};
    return {iso:best.iso, code:best.code, national:d.slice(best.code.length)};
  }

  // Rendu lecture seule : drapeau + indicatif + national, non éditable.
  function phoneReadOnlyHTML(stored){
    const s = splitE164(stored);
    const c = s.iso ? byIso(s.iso) : null;
    const flag = c ? c.flag : '';
    const code = s.code ? ('+'+s.code+' ') : '';
    return '<div class="cc-ro">'+flag+'<span>'+esc(code+s.national)+'</span></div>';
  }

  // Bouton d'accès dans la barre de navigation
  function mountButton(){
    const nav=document.querySelector('nav.site .in'); if(!nav)return;
    const b=document.createElement('button'); b.type='button'; b.className='memberAccess';
    b.textContent='👤 '+T('acces_membre');
    b.addEventListener('click',openLogin);
    nav.appendChild(b);
  }

  function overlay(html){
    const o=document.createElement('div'); o.className='authov';
    o.innerHTML='<div class="authbox panel">'+html+'</div>';
    o.addEventListener('click',e=>{if(e.target===o)o.remove();});
    document.body.appendChild(o); return o;
  }

  // Champ téléphone international éditable. Renvoie un handle.
  function phoneField(defaultIso){
    let cur = byIso(defaultIso||DEFAULT_ISO);
    const wrap = document.createElement('div'); wrap.className='tel-row';
    wrap.innerHTML =
      '<div class="cc-wrap">'+
        '<button type="button" class="cc-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="'+esc(T('otp_indicatif'))+'">'+
          '<span class="ccf">'+cur.flag+'</span><span class="ccc">+'+cur.code+'</span><span class="chev">▾</span>'+
        '</button>'+
        '<div class="cc-list" role="listbox" hidden>'+
          COUNTRIES.map(c=>'<button type="button" class="cc-opt" role="option" data-iso="'+c.iso+'">'+
            '<span class="flag">'+c.flag+'</span><span>'+esc(c.name)+'</span><span class="code">+'+c.code+'</span></button>').join('')+
        '</div>'+
      '</div>'+
      '<input class="authinp cc-num" inputmode="numeric" maxlength="'+cur.len+'" '+
        'placeholder="'+esc(T('otp_tel'))+'">';
    const btn=wrap.querySelector('.cc-btn'), list=wrap.querySelector('.cc-list'),
          num=wrap.querySelector('.cc-num');
    const setCur=c=>{ cur=c;
      wrap.querySelector('.ccf').innerHTML=c.flag;
      wrap.querySelector('.ccc').textContent='+'+c.code;
      num.maxLength=c.len; num.value=num.value.slice(0,c.len);
    };
    const close=()=>{ list.hidden=true; btn.setAttribute('aria-expanded','false'); };
    btn.addEventListener('click',()=>{ const open=list.hidden; list.hidden=!open;
      btn.setAttribute('aria-expanded', open?'true':'false'); });
    list.querySelectorAll('.cc-opt').forEach(o=>o.addEventListener('click',()=>{
      setCur(byIso(o.dataset.iso)); close(); num.focus(); }));
    num.addEventListener('input',()=>{ num.value=num.value.replace(/\D/g,'').slice(0,cur.len); });
    function onDocClick(e){
      if(!document.contains(wrap)){ document.removeEventListener('click',onDocClick); return; }
      if(!wrap.contains(e.target)) close();
    }
    document.addEventListener('click',onDocClick);
    wrap.addEventListener('keydown',e=>{ if(e.key==='Escape') close(); });
    return {
      el: wrap,
      getCode: ()=>cur.code,
      getNational: ()=>num.value.replace(/\D/g,''),
      getE164Digits: ()=>cur.code+num.value.replace(/\D/g,''),
      validate: ()=>num.value.replace(/\D/g,'').length===cur.len,
      focus: ()=>num.focus(),
    };
  }

  function openLogin(){
    const o=overlay(
      '<h3>'+esc(T('acces_membre'))+'</h3>'+
      '<p class="muted" id="aMsg">'+esc(T('otp_envoi'))+'</p>'+
      '<div id="aTelMount"></div>'+
      '<button class="cta" id="aSend" style="width:100%">📲 '+esc(T('otp_valider'))+'</button>'+
      '<div id="aS2" style="display:none;margin-top:10px">'+
        '<input id="aCode" inputmode="numeric" maxlength="6" placeholder="'+esc(T('otp_code'))+'" class="authinp otp">'+
        '<button class="cta" id="aVerify" style="width:100%">'+esc(T('otp_valider'))+'</button></div>');
    const msg=t=>$('#aMsg').textContent=t;
    const pf=phoneField(DEFAULT_ISO);
    o.querySelector('#aTelMount').appendChild(pf.el);
    $('#aSend').addEventListener('click',async e=>{
      if(!pf.validate()){ msg(T('otp_num_invalide')); return; }
      e.target.disabled=true;
      const r=await api('api/auth/request-otp.php',{role:'membre',telephone:pf.getE164Digits()}).catch(()=>({success:false}));
      msg(r.message||''); if(r.success){$('#aS2').style.display='';} else e.target.disabled=false;
    });
    $('#aVerify').addEventListener('click',async ()=>{
      const code=$('#aCode').value.replace(/\D/g,'');
      const r=await api('api/auth/verify-otp.php',{code}).catch(()=>({success:false}));
      if(r.success&&r.member){o.remove();openEditor(r.member);} else msg(r.message||'');
    });
  }

  function openEditor(m){
    const o=overlay(
      '<h3>'+esc(T('ma_fiche'))+'</h3>'+
      '<label class="authlbl">Photo</label>'+
      '<input type="file" id="ePhotoFile" accept="image/*">'+
      '<img id="ePhotoPrev" class="authprev" src="'+esc(m.photo||'')+'" alt="">'+
      '<label class="authlbl">Nom</label><input id="eNom" class="authinp" value="'+esc(m.nom_complet||'')+'">'+
      '<label class="authlbl">Adresse</label><input id="eAdr" class="authinp" value="'+esc(m.adresse||'')+'">'+
      '<label class="authlbl">Téléphone (9 chiffres)</label><input id="eTel" class="authinp" inputmode="numeric" maxlength="9" value="'+esc(m.telephone||'')+'">'+
      '<label class="authlbl">Biographie</label><textarea id="eBio" class="authinp" rows="4">'+esc(m.biographie||'')+'</textarea>'+
      '<p class="muted" id="eMsg"></p>'+
      '<button class="cta" id="eSave" style="width:100%">'+esc(T('enregistrer'))+'</button>'+
      '<button class="cta ghost" id="eOut" style="width:100%;margin-top:8px">'+esc(T('deconnexion'))+'</button>');
    let photoData=m.photo||'';
    $('#ePhotoFile').addEventListener('change',e=>{
      const f=e.target.files[0]; if(!f)return;
      $('#eMsg').textContent='Traitement de la photo…';
      compress(f,700,0.82,
        d=>{photoData=d;$('#ePhotoPrev').src=d;$('#eMsg').textContent='Photo prête ('+Math.round(d.length/1024)+' Ko)';},
        err=>{$('#eMsg').textContent=err;});
    });
    $('#eSave').addEventListener('click',async ()=>{
      const body={photo:photoData,nom_complet:$('#eNom').value,adresse:$('#eAdr').value,
        telephone:$('#eTel').value.replace(/\D/g,''),biographie:$('#eBio').value};
      const r=await api('api/member/save-fiche.php',body).catch(()=>({success:false}));
      $('#eMsg').textContent=r.success?T('saved_ok'):((r.errors&&r.errors.join(', '))||r.message||'Erreur');
    });
    $('#eOut').addEventListener('click',async ()=>{await api('api/auth/logout.php');o.remove();});
  }

  // Compression photo robuste + adaptative : gère les erreurs de décodage
  // (ex. HEIC), redimensionne à `max` px, puis baisse qualité/dimensions
  // jusqu'à passer sous la limite serveur (~1,5 Mo base64, marge sous 2 Mo).
  function compress(file,max,q,onOk,onErr){
    onErr=onErr||function(){};
    const LIMIT=1500000;
    const rd=new FileReader();
    rd.onerror=()=>onErr('Lecture du fichier impossible.');
    rd.onload=()=>{
      const img=new Image();
      img.onerror=()=>onErr('Format d’image non pris en charge (essayez un JPEG ou PNG, pas un HEIC).');
      img.onload=()=>{
        try{
          const draw=(scale,quality)=>{
            const w=Math.max(1,Math.round(img.width*scale)), h=Math.max(1,Math.round(img.height*scale));
            const c=document.createElement('canvas'); c.width=w; c.height=h;
            c.getContext('2d').drawImage(img,0,0,w,h);
            return c.toDataURL('image/jpeg',quality);
          };
          let scale=Math.min(1, max/Math.max(img.width,img.height));
          let quality=q, out=draw(scale,quality);
          while(out.length>LIMIT && quality>0.4){ quality-=0.1; out=draw(scale,quality); }
          while(out.length>LIMIT && scale>0.25){ scale*=0.8; out=draw(scale,0.7); }
          if(out.length>LIMIT){ onErr('Photo trop lourde même après compression — choisissez une image plus petite.'); return; }
          onOk(out);
        }catch(err){ onErr('Traitement de l’image impossible sur cet appareil.'); }
      };
      img.src=rd.result;
    };
    rd.readAsDataURL(file);
  }

  // Le chrome (nav) peut être monté soit synchro (pages sans loadSiteData,
  // e.g. dons.html) au DOMContentLoaded, soit asynchrone après fetch
  // (loadSiteData). On s'abonne aux deux signaux ; mountButton est idempotent
  // (il ne remonte pas si la nav contient déjà le bouton).
  let _mounted=false;
  function tryMount(){
    if(_mounted)return;
    const nav=document.querySelector('nav.site .in');
    if(nav&&!nav.querySelector('.memberAccess')){mountButton();}
    if(nav)_mounted=true;
  }
  // nav déjà présente au chargement du script → mountButton direct.
  if(document.querySelector('nav.site .in')){tryMount();}
  // Sinon, nav créée plus tard (soit DOMContentLoaded pour le chemin synchrone,
  // soit l'événement chrome:ready émis par renderChrome après fetch).
  document.addEventListener('DOMContentLoaded',tryMount);
  document.addEventListener('chrome:ready',tryMount);

  window.jakPhone = { COUNTRIES, splitE164, phoneReadOnlyHTML };
})();
