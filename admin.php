<?php
require_once __DIR__.'/api/lib/bootstrap.php';
require_once __DIR__.'/api/lib/auth.php';
$cfg = app_boot();
header('Content-Type: text/html; charset=utf-8');           // écrase le JSON de app_boot()
header('X-Robots-Tag: noindex, nofollow');

if (auth_current($cfg)) {                                     // session admin valide → console
  readfile(__DIR__.'/admin.html');
  exit;
}
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Connexion administration — JAK 2026</title>
<link rel="stylesheet" href="site.css">
</head><body>
<section><div class="wrap" style="max-width:440px;margin-top:12vh">
  <h2 class="sec-label">Espace administrateur</h2>
  <div class="panel">
    <p class="muted" id="adMsg">Recevez un code sur le numéro administrateur pour vous connecter.</p>
    <div id="adStep1">
      <button class="cta" id="adSend" style="width:100%">📲 Recevoir mon code</button>
    </div>
    <div id="adStep2" style="display:none">
      <input id="adCode" inputmode="numeric" maxlength="6" placeholder="Code à 6 chiffres"
        style="width:100%;padding:13px;border-radius:12px;border:1px solid var(--line);background:var(--panel-2);color:var(--ink);font-family:'IBM Plex Mono',monospace;font-size:18px;letter-spacing:.3em;text-align:center">
      <button class="cta" id="adVerify" style="width:100%;margin-top:10px">Valider</button>
    </div>
  </div>
</div></section>
<script>
const msg=t=>document.getElementById('adMsg').textContent=t;
document.getElementById('adSend').addEventListener('click',async e=>{
  e.target.disabled=true;
  const r=await fetch('api/auth/request-otp.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({role:'admin'})}).then(r=>r.json()).catch(()=>({success:false}));
  msg(r.message||'Erreur');
  if(r.success){document.getElementById('adStep1').style.display='none';document.getElementById('adStep2').style.display='';}
  else e.target.disabled=false;
});
document.getElementById('adVerify').addEventListener('click',async ()=>{
  const code=document.getElementById('adCode').value.replace(/\D/g,'');
  const r=await fetch('api/auth/verify-otp.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code})}).then(r=>r.json()).catch(()=>({success:false}));
  if(r.success){location.reload();} else msg(r.message||'Code invalide');
});
</script>
</body></html>
