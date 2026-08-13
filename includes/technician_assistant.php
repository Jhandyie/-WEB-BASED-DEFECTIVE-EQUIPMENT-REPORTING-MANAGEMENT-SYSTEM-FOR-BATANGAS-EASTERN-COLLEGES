<?php
/**
 * includes/technician_assistant.php — floating "BECCA AI" technician assistant.
 * Include once before </body> on the technician dashboard:
 *   require __DIR__ . '/includes/technician_assistant.php';
 * Posts to technician_chat_proxy.php (technician-session gated, read-only).
 */
?>
<style>
  .tia-fab{position:fixed;right:1.4rem;bottom:1.4rem;z-index:1500;display:flex;align-items:center;justify-content:center;width:62px;height:62px;padding:0;background:linear-gradient(135deg,rgba(74,14,14,.9),rgba(45,5,5,.9));-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);color:#fff;border:2px solid rgba(201,150,12,.6);border-radius:50%;cursor:pointer;box-shadow:0 10px 30px rgba(44,10,10,.4),0 0 20px rgba(201,150,12,.25);transition:transform .25s cubic-bezier(.22,1,.36,1),box-shadow .3s;animation:tiaFloat 6s ease-in-out infinite,tiaGlow 4.5s ease-in-out infinite;}
  .tia-fab:hover{animation:none;transform:none;box-shadow:0 16px 40px rgba(44,10,10,.5),0 0 32px rgba(201,150,12,.5);}
  @keyframes tiaFloat{0%,100%{transform:translateY(0);}50%{transform:translateY(-6px);}}
  @keyframes tiaGlow{0%,100%{box-shadow:0 10px 30px rgba(44,10,10,.4),0 0 16px rgba(201,150,12,.2);}50%{box-shadow:0 12px 34px rgba(44,10,10,.46),0 0 30px rgba(201,150,12,.45);}}
  .tia-fab .tia-ic{width:46px;height:46px;border-radius:50%;overflow:hidden;background:rgba(255,255,255,.13);border:1.5px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;position:relative;}
  .tia-fab .tia-ic img{width:100%;height:100%;object-fit:cover;display:block;}
  .tia-fab .tia-ic::after{content:'';position:absolute;top:-1px;right:-1px;width:12px;height:12px;border-radius:50%;background:#F0C040;border:2px solid #2D0505;box-shadow:0 0 0 0 rgba(240,192,64,.7);animation:tiaPulse 2.2s infinite;}
  @keyframes tiaPulse{0%{box-shadow:0 0 0 0 rgba(240,192,64,.6);}70%{box-shadow:0 0 0 8px rgba(240,192,64,0);}100%{box-shadow:0 0 0 0 rgba(240,192,64,0);}}
  @media(prefers-reduced-motion:reduce){.tia-fab,.tia-fab .tia-ic::after{animation:none;}}
  @media(max-width:960px){.tia-fab{bottom:5.4rem;right:1rem;}}
  body.modal-open .tia-fab{display:none;}
  .tia-overlay{position:fixed;inset:0;z-index:1900;background:rgba(20,5,5,.45);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);display:flex;align-items:flex-end;justify-content:flex-end;padding:1.25rem;opacity:0;pointer-events:none;transition:opacity .2s;}
  .tia-overlay.open{opacity:1;pointer-events:all;}
  .tia-panel{width:100%;max-width:400px;height:600px;max-height:calc(100vh - 2.5rem);background:rgba(255,255,255,.85);-webkit-backdrop-filter:blur(22px) saturate(1.2);backdrop-filter:blur(22px) saturate(1.2);border-radius:22px;border:1px solid rgba(255,255,255,.6);box-shadow:0 20px 60px rgba(44,10,10,.32),0 4px 14px rgba(44,10,10,.14);display:flex;flex-direction:column;overflow:hidden;transform:translateY(20px) scale(.98);opacity:0;transition:transform .26s cubic-bezier(.22,1,.36,1),opacity .2s;font-family:'DM Sans',sans-serif;}
  .tia-overlay.open .tia-panel{transform:none;opacity:1;}
  @media(max-width:560px){.tia-overlay{padding:0;align-items:flex-end;justify-content:center;}
    .tia-panel{max-width:100%;width:100%;height:90vh;max-height:90vh;border-radius:20px 20px 0 0;}}
  .tia-head{background:linear-gradient(135deg,#2D0505,#7B1D1D);color:#fff;padding:.9rem 1rem;display:flex;align-items:center;gap:.65rem;flex-shrink:0;border-bottom:2px solid rgba(201,150,12,.5);}
  .tia-av{width:40px;height:40px;border-radius:50%;overflow:hidden;background:#fff;border:1.5px solid rgba(201,150,12,.6);box-shadow:0 0 14px rgba(201,150,12,.4);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .tia-av img{width:100%;height:100%;object-fit:cover;display:block;}
  .tia-head .tia-t{flex:1;min-width:0;}
  .tia-head .tia-t b{font-size:.92rem;display:block;}
  .tia-head .tia-t small{font-size:.64rem;color:rgba(255,255,255,.6);}
  .tia-x{background:rgba(255,255,255,.12);border:none;color:#fff;width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:.85rem;}
  .tia-x:hover{background:rgba(255,255,255,.22);}
  .tia-msgs{flex:1;overflow-y:auto;padding:.9rem;display:flex;flex-direction:column;gap:.6rem;background:#F8F3EA;}
  .tia-msgs::-webkit-scrollbar{width:6px;}.tia-msgs::-webkit-scrollbar-thumb{background:rgba(201,150,12,.4);border-radius:3px;}
  .tia-row{display:flex;gap:.5rem;max-width:92%;}
  .tia-row.u{align-self:flex-end;flex-direction:row-reverse;}
  .tia-mav{width:26px;height:26px;border-radius:50%;overflow:hidden;flex-shrink:0;border:1px solid rgba(201,150,12,.45);box-shadow:0 0 8px rgba(201,150,12,.3);align-self:flex-end;}
  .tia-mav img{width:100%;height:100%;object-fit:cover;display:block;}
  .tia-b{padding:.6rem .8rem;border-radius:14px;font-size:.84rem;line-height:1.6;white-space:pre-wrap;word-break:break-word;}
  .tia-row.bot .tia-b{background:#fff;border:1px solid #E2D9CC;border-bottom-left-radius:4px;color:#1C1008;}
  .tia-row.u .tia-b{background:#7B1D1D;color:#fff;border-bottom-right-radius:4px;}
  .tia-b b,.tia-b strong{color:inherit;font-weight:700;}
  .tia-chips{display:flex;flex-wrap:wrap;gap:.35rem;padding:.5rem .9rem;border-top:1px solid #E2D9CC;background:#fff;flex-shrink:0;}
  .tia-chip{font-size:.72rem;padding:.34rem .72rem;border-radius:18px;border:1px solid rgba(123,29,29,.18);background:rgba(123,29,29,.05);color:#7B1D1D;cursor:pointer;font-family:inherit;font-weight:600;transition:all .15s;}
  .tia-chip:hover{border-color:#7B1D1D;color:#7B1D1D;background:#FBF4F4;transform:none;box-shadow:0 2px 8px rgba(123,29,29,.12);}
  .tia-inp{display:flex;gap:.5rem;padding:.7rem .8rem;border-top:1px solid #E2D9CC;background:#fff;flex-shrink:0;}
  .tia-inp textarea{flex:1;border:1.5px solid #E2D9CC;border-radius:11px;padding:.6rem .8rem;font:inherit;font-size:16px;resize:none;max-height:90px;outline:none;}
  .tia-inp textarea:focus{border-color:#7B1D1D;box-shadow:0 0 0 3px rgba(123,29,29,.09);}
  .tia-send{width:42px;height:42px;border-radius:10px;background:#4A0E0E;color:#fff;border:none;cursor:pointer;flex-shrink:0;font-size:.9rem;}
  .tia-send:hover{background:#7B1D1D;}.tia-send:disabled{opacity:.5;cursor:not-allowed;}
  .tia-dots span{display:inline-block;width:5px;height:5px;border-radius:50%;background:#7B1D1D;margin:0 1px;animation:tiaDot 1.3s infinite;}
  .tia-dots span:nth-child(2){animation-delay:.2s;}.tia-dots span:nth-child(3){animation-delay:.4s;}
  @keyframes tiaDot{0%,80%,100%{opacity:.2;}40%{opacity:1;}}
  @media(max-width:640px){.tia-overlay{padding:0;align-items:flex-end;justify-content:center;}.tia-panel{max-width:100%;border-radius:20px 20px 0 0;height:88vh;max-height:88vh;}}
  /* comfortable tap targets on phones (placed after the base rules so it wins) */
  @media(max-width:560px){.tia-x{width:46px;height:46px;}.tia-chip{min-height:46px;}.tia-send{width:46px;height:46px;}}
</style>

<button class="tia-fab" id="tiaFab" type="button" aria-label="Open BECCA AI technician assistant" title="BECCA AI">
  <span class="tia-ic"><img src="assets/becca-mascot.svg" alt="AI" onerror="this.style.display='none';this.parentElement.innerHTML='<i class=&quot;fas fa-robot&quot;></i>'"></span>
</button>

<div class="tia-overlay" id="tiaOverlay">
  <div class="tia-panel" role="dialog" aria-label="BECCA AI">
    <div class="tia-head">
      <div class="tia-av"><img src="assets/becca-mascot.svg" alt="AI"></div>
      <div class="tia-t"><b>BECCA AI</b><small>Technician assistant · read-only</small></div>
      <button class="tia-x" id="tiaClose" type="button" aria-label="Close"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="tia-msgs" id="tiaMsgs"></div>
    <div class="tia-chips" id="tiaChips">
      <button class="tia-chip" type="button">What's next?</button>
      <button class="tia-chip" type="button">Summarize my tasks</button>
      <button class="tia-chip" type="button">How do I complete a task?</button>
    </div>
    <div class="tia-inp">
      <textarea id="tiaInput" rows="1" placeholder="Ask about your tasks or the repair workflow…" aria-label="Message"></textarea>
      <button class="tia-send" id="tiaSend" type="button" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
    </div>
  </div>
</div>

<script>
(function(){
  const fab=document.getElementById('tiaFab'), ov=document.getElementById('tiaOverlay'),
        msgs=document.getElementById('tiaMsgs'), input=document.getElementById('tiaInput'),
        send=document.getElementById('tiaSend'), closeBtn=document.getElementById('tiaClose');
  let history=[], busy=false, greeted=false;
  const esc=s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const fmt=s=>esc(s).replace(/\*\*(.+?)\*\*/g,'<b>$1</b>');

  function add(role,text){
    const row=document.createElement('div'); row.className='tia-row '+(role==='u'?'u':'bot');
    const av = role==='u' ? '' : '<span class="tia-mav"><img src="assets/becca-mascot.svg" alt="AI"></span>';
    row.innerHTML=av+'<div class="tia-b">'+fmt(text)+'</div>';
    msgs.appendChild(row); msgs.scrollTop=msgs.scrollHeight; return row;
  }
  function typing(){
    const row=document.createElement('div'); row.className='tia-row bot'; row.id='tiaTyping';
    row.innerHTML='<span class="tia-mav"><img src="assets/becca-mascot.svg" alt="AI"></span><div class="tia-b"><span class="tia-dots"><span></span><span></span><span></span></span></div>';
    msgs.appendChild(row); msgs.scrollTop=msgs.scrollHeight;
  }
  function untype(){ const t=document.getElementById('tiaTyping'); if(t) t.remove(); }

  function open(){ ov.classList.add('open'); if(!greeted){greeted=true; add('bot',"Hi! I'm **BECCA**, your technician assistant. I can tell you what's in your queue, what to work on next, and walk you through completion reports. How can I help?");} setTimeout(()=>input.focus(),250); }
  function close(){ ov.classList.remove('open'); }
  fab.addEventListener('click',open); closeBtn.addEventListener('click',close);
  ov.addEventListener('click',e=>{ if(e.target===ov) close(); });
  document.addEventListener('keydown',e=>{ if(e.key==='Escape') close(); });

  async function ask(text){
    if(busy||!text.trim()) return;
    busy=true; send.disabled=true;
    add('u',text); history.push({role:'user',content:text});
    input.value=''; input.style.height='auto'; typing();
    try{
      const r=await fetch('technician_chat_proxy.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({messages:history})});
      const d=await r.json(); untype();
      const reply=d.reply||"Sorry, I couldn't get a response.";
      history.push({role:'assistant',content:reply});
      add('bot',reply);
    }catch(e){ untype(); add('bot',"Connection error — please try again."); }
    busy=false; send.disabled=false; input.focus();
  }
  send.addEventListener('click',()=>ask(input.value));
  input.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault(); ask(input.value);} });
  input.addEventListener('input',()=>{ input.style.height='auto'; input.style.height=Math.min(input.scrollHeight,90)+'px'; });
  document.querySelectorAll('#tiaChips .tia-chip').forEach(c=>c.addEventListener('click',()=>{ open(); ask(c.textContent); }));
})();
</script>
