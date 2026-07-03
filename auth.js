/* Accès membre : connexion OTP WhatsApp + édition de sa fiche. */
(function(){
  'use strict';
  const $=s=>document.querySelector(s);
  const api=(u,b)=>fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})}).then(r=>r.json());

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

  function openLogin(){
    const o=overlay(
      '<h3>'+esc(T('acces_membre'))+'</h3>'+
      '<p class="muted" id="aMsg">'+esc(T('otp_envoi'))+'</p>'+
      '<input id="aTel" inputmode="numeric" maxlength="9" placeholder="'+esc(T('otp_tel'))+'" class="authinp">'+
      '<button class="cta" id="aSend" style="width:100%">📲 '+esc(T('otp_valider'))+'</button>'+
      '<div id="aS2" style="display:none;margin-top:10px">'+
        '<input id="aCode" inputmode="numeric" maxlength="6" placeholder="'+esc(T('otp_code'))+'" class="authinp otp">'+
        '<button class="cta" id="aVerify" style="width:100%">'+esc(T('otp_valider'))+'</button></div>');
    const msg=t=>$('#aMsg').textContent=t;
    $('#aSend').addEventListener('click',async e=>{
      const tel=$('#aTel').value.replace(/\D/g,''); if(tel.length!==9){msg(T('otp_tel'));return;}
      e.target.disabled=true;
      const r=await api('api/auth/request-otp.php',{role:'membre',telephone:tel}).catch(()=>({success:false}));
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
      compress(f,700,0.85,d=>{photoData=d;$('#ePhotoPrev').src=d;});
    });
    $('#eSave').addEventListener('click',async ()=>{
      const body={photo:photoData,nom_complet:$('#eNom').value,adresse:$('#eAdr').value,
        telephone:$('#eTel').value.replace(/\D/g,''),biographie:$('#eBio').value};
      const r=await api('api/member/save-fiche.php',body).catch(()=>({success:false}));
      $('#eMsg').textContent=r.success?T('saved_ok'):((r.errors&&r.errors.join(', '))||r.message||'Erreur');
    });
    $('#eOut').addEventListener('click',async ()=>{await api('api/auth/logout.php');o.remove();});
  }

  // Compression photo (reprise de la logique canvas d'admin.html)
  function compress(file,max,q,cb){
    const img=new Image(); const rd=new FileReader();
    rd.onload=()=>{img.onload=()=>{
      let w=img.width,h=img.height; if(w>h&&w>max){h=h*max/w;w=max;} else if(h>max){w=w*max/h;h=max;}
      const c=document.createElement('canvas');c.width=w;c.height=h;
      c.getContext('2d').drawImage(img,0,0,w,h); cb(c.toDataURL('image/jpeg',q));
    };img.src=rd.result;};
    rd.readAsDataURL(file);
  }

  if(document.readyState!=='loading') mountButton();
  else document.addEventListener('DOMContentLoaded',mountButton);
})();
