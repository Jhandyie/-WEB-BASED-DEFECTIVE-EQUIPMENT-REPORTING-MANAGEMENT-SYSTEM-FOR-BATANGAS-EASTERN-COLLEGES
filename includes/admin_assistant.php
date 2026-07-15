<?php
/**
 * includes/admin_assistant.php — floating "BECCA AI" admin assistant widget.
 * Include once before </body> on any admin page:  require __DIR__.'/includes/admin_assistant.php';
 * Self-contained (scoped .aia- styles + JS); posts to admin_chat_proxy.php. Read-only advisor.
 */
if (defined('ADMIN_ASSISTANT_RENDERED')) { return; }
define('ADMIN_ASSISTANT_RENDERED', true);

/* Unread-notification count for the uniform floating bell (best-effort). */
$__aiaUnread = 0;
try {
    if (function_exists('getDBConnection') && !empty($_SESSION['user_id'])) {
        $__c = getDBConnection();
        $__s = $__c->prepare("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = false");
        if ($__s) {
            $__uid = (string)$_SESSION['user_id'];
            $__s->bind_param('s', $__uid);
            $__s->execute();
            $__r = $__s->get_result()->fetch_assoc();
            $__aiaUnread = (int)($__r['n'] ?? 0);
            $__s->close();
        }
    }
} catch (\Throwable $e) { $__aiaUnread = 0; }
?>
<style>
  .aia-fab{position:fixed;right:1.4rem;bottom:6rem;z-index:9990;display:flex;align-items:center;justify-content:center;width:62px;height:62px;padding:0;background:linear-gradient(135deg,rgba(74,14,14,.9),rgba(45,5,5,.9));-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);color:#fff;border:2px solid rgba(201,150,12,.6);border-radius:50%;cursor:pointer;box-shadow:0 10px 30px rgba(44,10,10,.4),0 0 20px rgba(201,150,12,.25);transition:transform .25s cubic-bezier(.22,1,.36,1),box-shadow .3s;animation:aiaFloat 6s ease-in-out infinite,aiaGlow 4.5s ease-in-out infinite;}
  .aia-fab:hover{animation:none;transform:translateY(-4px) scale(1.06);box-shadow:0 16px 40px rgba(44,10,10,.5),0 0 32px rgba(201,150,12,.5);}
  @keyframes aiaFloat{0%,100%{transform:translateY(0);}50%{transform:translateY(-6px);}}
  @keyframes aiaGlow{0%,100%{box-shadow:0 10px 30px rgba(44,10,10,.4),0 0 16px rgba(201,150,12,.2);}50%{box-shadow:0 12px 34px rgba(44,10,10,.46),0 0 30px rgba(201,150,12,.45);}}
  .aia-fab .aia-ic{width:46px;height:46px;border-radius:50%;overflow:hidden;background:rgba(255,255,255,.13);border:1.5px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;position:relative;}
  .aia-fab .aia-ic img{width:100%;height:100%;object-fit:cover;display:block;}
  .aia-fab .aia-ic::after{content:'';position:absolute;top:-1px;right:-1px;width:12px;height:12px;border-radius:50%;background:#F0C040;border:2px solid #2D0505;box-shadow:0 0 0 0 rgba(240,192,64,.7);animation:aiaPulse 2.2s infinite;}
  @keyframes aiaPulse{0%{box-shadow:0 0 0 0 rgba(240,192,64,.6);}70%{box-shadow:0 0 0 8px rgba(240,192,64,0);}100%{box-shadow:0 0 0 0 rgba(240,192,64,0);}}
  @media(max-width:560px){.aia-fab b,.aia-fab span{display:none;}.aia-fab{padding:.5rem;right:1rem;bottom:5rem;}}
  .aia-overlay{position:fixed;inset:0;z-index:9991;background:rgba(20,5,5,.45);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);display:flex;align-items:flex-end;justify-content:flex-end;padding:1.25rem;opacity:0;pointer-events:none;transition:opacity .2s;}
  .aia-overlay.open{opacity:1;pointer-events:all;}
  .aia-panel{width:100%;max-width:400px;height:600px;max-height:calc(100vh - 2.5rem);background:rgba(255,255,255,.85);-webkit-backdrop-filter:blur(22px) saturate(1.2);backdrop-filter:blur(22px) saturate(1.2);border-radius:22px;border:1px solid rgba(255,255,255,.6);box-shadow:0 20px 60px rgba(44,10,10,.32),0 4px 14px rgba(44,10,10,.14),inset 0 0 0 1px rgba(255,255,255,.3);display:flex;flex-direction:column;overflow:hidden;transform:translateY(20px) scale(.98);opacity:0;transition:transform .26s cubic-bezier(.22,1,.36,1),opacity .2s;font-family:'DM Sans','Outfit',sans-serif;}
  .aia-overlay.open .aia-panel{transform:none;opacity:1;}
  .aia-head{background:linear-gradient(135deg,#2D0505,#7B1D1D);color:#fff;padding:.9rem 1rem;display:flex;align-items:center;gap:.65rem;flex-shrink:0;border-bottom:2px solid rgba(201,150,12,.5);}
  .aia-av{width:40px;height:40px;border-radius:50%;overflow:hidden;background:#fff;border:1.5px solid rgba(201,150,12,.6);box-shadow:0 0 14px rgba(201,150,12,.4);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .aia-av img{width:100%;height:100%;object-fit:cover;display:block;}
  .aia-head .aia-t{flex:1;min-width:0;}
  .aia-head .aia-t b{font-size:.92rem;display:block;}
  .aia-head .aia-t small{font-size:.64rem;color:rgba(255,255,255,.6);}
  .aia-x{background:rgba(255,255,255,.12);border:none;color:#fff;width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:.85rem;}
  .aia-x:hover{background:rgba(255,255,255,.22);}
  .aia-msgs{flex:1;overflow-y:auto;padding:.9rem;display:flex;flex-direction:column;gap:.6rem;background:#F8F3EA;}
  .aia-msgs::-webkit-scrollbar{width:6px;}.aia-msgs::-webkit-scrollbar-thumb{background:rgba(201,150,12,.4);border-radius:3px;}
  .aia-row{display:flex;gap:.5rem;max-width:92%;}
  .aia-row.u{align-self:flex-end;flex-direction:row-reverse;}
  .aia-mav{width:26px;height:26px;border-radius:50%;overflow:hidden;flex-shrink:0;border:1px solid rgba(201,150,12,.45);box-shadow:0 0 8px rgba(201,150,12,.3);align-self:flex-end;}
  .aia-mav img{width:100%;height:100%;object-fit:cover;display:block;}
  .aia-b{padding:.6rem .8rem;border-radius:14px;font-size:.84rem;line-height:1.6;white-space:pre-wrap;word-break:break-word;}
  .aia-row.bot .aia-b{background:#fff;border:1px solid #E2D9CC;border-bottom-left-radius:4px;color:#1C1008;}
  .aia-row.u .aia-b{background:#7B1D1D;color:#fff;border-bottom-right-radius:4px;}
  .aia-b b,.aia-b strong{color:inherit;font-weight:700;}
  .aia-chips{display:flex;flex-wrap:wrap;gap:.35rem;padding:.5rem .9rem;border-top:1px solid #E2D9CC;background:#fff;flex-shrink:0;}
  .aia-chip{font-size:.7rem;padding:.3rem .6rem;border-radius:20px;border:1.5px solid #E2D9CC;background:#fff;color:#5C3838;cursor:pointer;font-family:inherit;}
  .aia-chip:hover{border-color:#7B1D1D;color:#7B1D1D;background:#FBF4F4;}
  .aia-inp{display:flex;gap:.5rem;padding:.7rem .8rem;border-top:1px solid #E2D9CC;background:#fff;flex-shrink:0;}
  .aia-inp textarea{flex:1;border:1.5px solid #E2D9CC;border-radius:11px;padding:.6rem .8rem;font:inherit;font-size:16px;resize:none;max-height:90px;outline:none;}
  .aia-inp textarea:focus{border-color:#7B1D1D;box-shadow:0 0 0 3px rgba(123,29,29,.09);}
  .aia-send{width:42px;height:42px;border-radius:10px;background:#4A0E0E;color:#fff;border:none;cursor:pointer;flex-shrink:0;font-size:.9rem;}
  .aia-send:hover{background:#7B1D1D;}.aia-send:disabled{opacity:.5;cursor:not-allowed;}
  .aia-dots span{display:inline-block;width:5px;height:5px;border-radius:50%;background:#7B1D1D;margin:0 1px;animation:aiaDot 1.3s infinite;}
  .aia-dots span:nth-child(2){animation-delay:.2s;}.aia-dots span:nth-child(3){animation-delay:.4s;}
  @keyframes aiaDot{0%,80%,100%{opacity:.2;}40%{opacity:1;}}
  @media(prefers-reduced-motion:reduce){.aia-fab,.aia-fab .aia-ic::after{animation:none;}}
</style>

<style>
  /* Uniform notification bell — same spot on every admin page (stacked above Becca). */
  .aia-bell{position:fixed;right:1.4rem;bottom:11rem;z-index:1490;width:52px;height:52px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:1.05rem;color:#7B1D1D;
    background:#fff;border:1.5px solid #E2D9CC;box-shadow:0 8px 22px rgba(44,10,10,.18);transition:all .18s;}
  .aia-bell:hover{color:#fff;background:linear-gradient(135deg,#4A0E0E,#7B1D1D);border-color:transparent;
    transform:translateY(-2px);box-shadow:0 12px 28px rgba(74,14,14,.3);}
  .aia-bell .ab-dot{position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;
    background:linear-gradient(135deg,#DC2626,#B91C1C);color:#fff;font-size:.64rem;font-weight:800;
    display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(220,38,38,.4);}
  @media(max-width:560px){.aia-bell{right:1rem;bottom:10.4rem;width:46px;height:46px;}}
  /* When relocated into a page's top header bar it sits inline, not floating. */
  .aia-bell.in-top{position:relative;top:auto;right:auto;bottom:auto;left:auto;width:40px;height:40px;font-size:.95rem;margin-left:.15rem;flex-shrink:0;
    background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.28);color:#fff;box-shadow:none;}
  .aia-bell.in-top:hover{background:rgba(255,255,255,.22);color:#fff;transform:none;box-shadow:none;}
</style>
<a class="aia-bell" href="admin_notifications.php" aria-label="Notifications<?php echo $__aiaUnread > 0 ? ' (' . $__aiaUnread . ' unread)' : ''; ?>" title="Notifications">
  <i class="fas fa-bell"></i>
  <?php if ($__aiaUnread > 0): ?><span class="ab-dot"><?php echo $__aiaUnread > 9 ? '9+' : $__aiaUnread; ?></span><?php endif; ?>
</a>
<script>
/* Put the notification bell in its perfect place: the page's top-right header.
   Pages using the .topbar layout already render a header bell, so the floating
   one is a duplicate and is removed; .top (maroon bar) pages get the bell moved in. */
(function () {
  var bell = document.querySelector('.aia-bell');
  if (!bell) return;
  var place = function () {
    if (document.querySelector('.topbar .tb-r a[href*="notification"]')) { bell.remove(); return; }
    var top = document.querySelector('.top');
    if (top) {
      bell.classList.add('in-top');
      var back = top.querySelector('a.back, .back');
      if (back) { top.insertBefore(bell, back); } else { top.appendChild(bell); }
    }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', place);
  else place();
})();
</script>
<button class="aia-fab" id="aiaFab" type="button" aria-label="Open BECCA AI admin assistant" title="BECCA AI">
  <span class="aia-ic"><img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="AI"></span>
</button>

<div class="aia-overlay" id="aiaOverlay">
  <div class="aia-panel" role="dialog" aria-label="BECCA AI">
    <div class="aia-head">
      <div class="aia-av"><img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="AI"></div>
      <div class="aia-t"><b>BECCA AI</b><small>Administrative assistant · read-only</small></div>
      <button class="aia-x" id="aiaClose" type="button" aria-label="Close"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="aia-msgs" id="aiaMsgs"></div>
    <div class="aia-chips" id="aiaChips">
      <button class="aia-chip" type="button">Give me a summary</button>
      <button class="aia-chip" type="button">What's overdue?</button>
      <button class="aia-chip" type="button">Who's the busiest technician?</button>
      <button class="aia-chip" type="button">How do I assign a report?</button>
    </div>
    <div class="aia-inp">
      <textarea id="aiaInput" rows="1" placeholder="Ask about reports, workflow, or data…" aria-label="Message"></textarea>
      <button class="aia-send" id="aiaSend" type="button" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
    </div>
  </div>
</div>

<script>
(function(){
  const fab=document.getElementById('aiaFab'), ov=document.getElementById('aiaOverlay'),
        msgs=document.getElementById('aiaMsgs'), input=document.getElementById('aiaInput'),
        send=document.getElementById('aiaSend'), closeBtn=document.getElementById('aiaClose');
  let history=[], busy=false, greeted=false;
  const esc=s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const fmt=s=>esc(s).replace(/\*\*(.+?)\*\*/g,'<b>$1</b>');

  function add(role,text){
    const row=document.createElement('div'); row.className='aia-row '+(role==='u'?'u':'bot');
    const av = role==='u' ? '' : '<span class="aia-mav"><img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="AI"></span>';
    row.innerHTML=av+'<div class="aia-b">'+fmt(text)+'</div>';
    msgs.appendChild(row); msgs.scrollTop=msgs.scrollHeight; return row;
  }
  function typing(){
    const row=document.createElement('div'); row.className='aia-row bot'; row.id='aiaTyping';
    row.innerHTML='<span class="aia-mav"><img src="assets/Gemini_Generated_Image_e35zfue35zfue35z.png" alt="AI"></span><div class="aia-b"><span class="aia-dots"><span></span><span></span><span></span></span></div>';
    msgs.appendChild(row); msgs.scrollTop=msgs.scrollHeight;
  }
  function untype(){ const t=document.getElementById('aiaTyping'); if(t) t.remove(); }

  function open(){ ov.classList.add('open'); if(!greeted){greeted=true; add('bot',"Hi! I'm **BECCA AI**, your admin assistant. Ask me about live data (open reports, overdue, technician workload, top equipment) or how any workflow works — receiving, approving, assigning, preventive maintenance. How can I help?");} setTimeout(()=>input.focus(),250); }
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
      const r=await fetch('admin_chat_proxy.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({messages:history})});
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
  document.querySelectorAll('#aiaChips .aia-chip').forEach(c=>c.addEventListener('click',()=>{ open(); ask(c.textContent); }));
})();

/* ── Admin action feedback: every POST action (Approve / Reject / Receive / Verify …)
      instantly locks its button with a spinner and shows the branded loader — and
      double-submits become impossible. Included on every admin page via this file. ── */
(function () {
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM') return;
    if (e.defaultPrevented) return;                                   // e.g. a confirm() was cancelled
    if ((f.method || '').toLowerCase() !== 'post') return;            // searches/filters stay instant
    if (f.classList.contains('no-action-feedback')) return;           // opt-out hook
    if (f.hasAttribute('data-af-done')) return;
    f.setAttribute('data-af-done', '1');
    var loader = document.getElementById('pageLoader');
    if (loader) loader.classList.add('show');
    f.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (b) {
      b.disabled = true;
      if (b.tagName === 'BUTTON') b.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Working…';
      else b.value = 'Working…';
    });
  }, false); /* bubble phase: runs AFTER inline confirm() handlers, so a cancelled
                confirm (defaultPrevented) never locks the page */
  /* restore state if the page is shown again from bfcache */
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) return;
    var loader = document.getElementById('pageLoader');
    if (loader) loader.classList.remove('show');
  });
})();
</script>
