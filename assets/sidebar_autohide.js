/*
 * BEC — Sidebar behavior (shared by admin + technician panels)
 *
 * ADMIN (fixed sidebar #sb):
 *   • Desktop: auto "icon rail" — the sidebar shows icons only and
 *     automatically expands to full width while the mouse is over it
 *     (overlay, so content doesn't reflow). No clicking needed.
 *   • Mobile: off-canvas drawer opened by a floating hamburger + scrim.
 *
 * TECHNICIAN (grid sidebar #technicianSidebar): original collapse/peek/toggle
 *   behavior, unchanged.
 */
(function () {
  var body = document.body;
  var adminSb = document.getElementById('sb');
  var techSb = document.getElementById('technicianSidebar');
  if (adminSb) { initAdmin(adminSb); }
  else if (techSb) { initLegacy(techSb); }

  /* ============================ ADMIN: auto-rail ============================ */
  function initAdmin(SB) {
    /* Auto icon-rail for every admin sidebar. Rules cover both class systems:
       the component (.ni/.nav-sec/.sb-top/.lout) and the dashboard (.nav-item/.nav-lbl/.sb-seal/.logout-btn). */
    var RAILKEY = 'becSidebarRail';
    var mq = window.matchMedia('(max-width: 860px)');
    var isMobile = function () { return mq.matches; };

    var style = document.createElement('style');
    style.textContent =
      'body.becReady #sb{transition:width .22s ease,transform .26s ease;}' +
      'body.becReady .wrap,body.becReady .main,body.becReady .top{transition:margin-left .22s ease;}' +
      /* desktop rail — override --sb so the sidebar AND every content offset shrink together */
      '@media(min-width:861px){' +
        /* RAIL_WIDTH: change this value to resize the collapsed rail */
        'body.becRail{--sb:72px;--sidebar:72px;}' +
        'body.becRail #sb{overflow:hidden;}' +
        'body.becRail #sb:not(.becPeek) .sb-top,body.becRail #sb:not(.becPeek) .sb-seal{justify-content:center;padding-left:0;padding-right:0;}' +
        'body.becRail #sb:not(.becPeek) .sb-brand{display:none;}' +
        'body.becRail #sb:not(.becPeek) .sb-user{justify-content:center;margin-left:.4rem;margin-right:.4rem;padding-left:0;padding-right:0;}' +
        'body.becRail #sb:not(.becPeek) .sb-user>div:last-child{display:none;}' +
        'body.becRail #sb:not(.becPeek) .nav-sec,body.becRail #sb:not(.becPeek) .nav-lbl{display:none;}' +
        'body.becRail #sb:not(.becPeek) .ni,body.becRail #sb:not(.becPeek) .nav-item{justify-content:center;font-size:0;padding-left:0;padding-right:0;}' +
        'body.becRail #sb:not(.becPeek) .nbadge,body.becRail #sb:not(.becPeek) .nav-badge{display:none;}' +
        'body.becRail #sb:not(.becPeek) .lout,body.becRail #sb:not(.becPeek) .logout-btn{justify-content:center;font-size:0;}' +
        'body.becRail #sb:not(.becPeek) .lout i,body.becRail #sb:not(.becPeek) .logout-btn i{font-size:1rem;}' +
        'body.becRail #sb.becPeek{width:262px;overflow-y:auto;box-shadow:6px 0 42px rgba(20,5,5,.32);z-index:500;}' +
        '#becSbToggle{display:none!important;}' +
      '}' +
      /* mobile off-canvas */
      '@media(max-width:860px){' +
        '#sb{transform:translateX(-100%);}' +
        'body.becReady #sb{transition:transform .32s cubic-bezier(.4,0,.2,1);}' +
        'body.becMobileOpen #sb{transform:translateX(0);box-shadow:0 0 60px rgba(0,0,0,.5);z-index:1200;}' +
        'body.becMobileOpen{overflow:hidden;}' +   /* lock background scroll while drawer is open */
        '#becSbToggle{position:fixed;top:12px;left:12px;z-index:1300;display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:13px;background:linear-gradient(135deg,#7B1D1D,#4A0E0E);color:#fff;border:1px solid rgba(201,150,12,.5);box-shadow:0 4px 16px rgba(44,10,10,.34);cursor:pointer;transition:transform .16s ease,box-shadow .18s ease;}' +
        '#becSbToggle:active{transform:scale(.9);}' +
        'body.becMobileOpen #becSbToggle{box-shadow:0 4px 22px rgba(44,10,10,.5);}' +
        /* three-bar hamburger that morphs into an X when the drawer opens */
        '#becSbToggle .becHamb{position:relative;width:20px;height:14px;display:block;}' +
        '#becSbToggle .becHamb span{position:absolute;left:0;width:100%;height:2px;background:#fff;border-radius:2px;transition:transform .3s cubic-bezier(.4,0,.2,1),opacity .2s ease,top .3s cubic-bezier(.4,0,.2,1);}' +
        '#becSbToggle .becHamb span:nth-child(1){top:0;}' +
        '#becSbToggle .becHamb span:nth-child(2){top:6px;}' +
        '#becSbToggle .becHamb span:nth-child(3){top:12px;}' +
        'body.becMobileOpen #becSbToggle .becHamb span:nth-child(1){top:6px;transform:rotate(45deg);}' +
        'body.becMobileOpen #becSbToggle .becHamb span:nth-child(2){opacity:0;transform:translateX(-8px);}' +
        'body.becMobileOpen #becSbToggle .becHamb span:nth-child(3){top:6px;transform:rotate(-45deg);}' +
      '}' +
      '.mob-tog{display:none!important;}' +
      /* scrim fades + blurs in instead of snapping on */
      '#becScrim{position:fixed;inset:0;background:rgba(20,5,5,.45);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);z-index:1100;opacity:0;visibility:hidden;transition:opacity .3s ease,visibility .3s ease;}' +
      'body.becMobileOpen #becScrim{opacity:1;visibility:visible;}';
    document.head.appendChild(style);

    /* mobile hamburger (hidden on desktop via CSS) — CSS bars morph into an X */
    var btn = document.createElement('button');
    btn.id = 'becSbToggle';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Open menu');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span class="becHamb"><span></span><span></span><span></span></span>';
    body.appendChild(btn);

    /* mobile scrim */
    var scrim = document.createElement('div');
    scrim.id = 'becScrim';
    body.appendChild(scrim);

    function syncBtn(open) {
      btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function closeMobile() { body.classList.remove('becMobileOpen'); syncBtn(false); }

    btn.addEventListener('click', function () { syncBtn(body.classList.toggle('becMobileOpen')); });
    scrim.addEventListener('click', closeMobile);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && body.classList.contains('becMobileOpen')) closeMobile();
    });

    /* close mobile drawer after tapping a link */
    SB.addEventListener('click', function (e) {
      if (isMobile() && e.target.closest('a')) { closeMobile(); }
    });

    /* desktop: hover the rail to expand it as an overlay */
    SB.addEventListener('mouseenter', function () {
      if (!isMobile() && body.classList.contains('becRail')) { SB.classList.add('becPeek'); }
    });
    SB.addEventListener('mouseleave', function () { SB.classList.remove('becPeek'); });

    /* leaving mobile width should drop the mobile-open state */
    mq.addEventListener('change', function () {
      closeMobile();
      SB.classList.remove('becPeek');
    });

    /* Rail preference (default: rail OFF — a full, labelled, stationary sidebar).
       It used to default ON, which meant the desktop sidebar was a 72px icon rail
       that expanded to 262px as an overlay whenever the pointer crossed it. Since
       nothing ever writes RAILKEY, that was effectively unconditional, and it is
       what made the admin sidebar feel like it was constantly moving. Set
       localStorage becSidebarRail = '1' to get the auto-rail back. */
    var rail = '0';
    try { rail = localStorage.getItem(RAILKEY) || '0'; } catch (e) {}
    body.classList.toggle('becRail', rail === '1');
    requestAnimationFrame(function () { body.classList.add('becReady'); });
  }

  /* ============ ADMIN (dashboard / non-rail design): classic toggle ============ */
  function initAdminLegacy(SB) {
    var KEY = 'becSidebarHidden';
    var style = document.createElement('style');
    style.textContent =
      'body.becReady #sb{transition:transform .26s ease;}' +
      'body.becReady .wrap,body.becReady .main,body.becReady .top{transition:margin-left .26s ease;}' +
      'body.becSbHide #sb{transform:translateX(-100%);}' +
      'body.becSbHide .wrap,body.becSbHide .main,body.becSbHide .top{margin-left:0 !important;}' +
      'body.becSbHide #sb.becPeek{transform:translateX(0);box-shadow:0 0 50px rgba(0,0,0,.45);z-index:1200;}' +
      '#becSbToggle{position:fixed;top:14px;z-index:1300;display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:9px;background:#7B1D1D;color:#fff;border:1px solid rgba(201,150,12,.5);box-shadow:0 4px 14px rgba(44,10,10,.3);cursor:pointer;font-size:1rem;}' +
      '#becSbEdge{position:fixed;left:0;top:0;width:16px;height:100vh;z-index:1100;}' +
      'body:not(.becSbHide) #becSbEdge{display:none;}';
    document.head.appendChild(style);
    var btn = document.createElement('button');
    btn.id = 'becSbToggle'; btn.type = 'button';
    btn.setAttribute('aria-label', 'Toggle sidebar'); btn.innerHTML = '<i class="fas fa-bars"></i>';
    body.appendChild(btn);
    function pos() { var hidden = body.classList.contains('becSbHide'); var w = hidden ? 0 : (SB.offsetWidth || 262); btn.style.left = (w + 14) + 'px'; }
    function setHidden(h) { body.classList.toggle('becSbHide', h); try { localStorage.setItem(KEY, h ? '1' : '0'); } catch (e) {} pos(); }
    btn.addEventListener('click', function () { SB.classList.remove('becPeek'); setHidden(!body.classList.contains('becSbHide')); });
    var edge = document.createElement('div'); edge.id = 'becSbEdge'; body.appendChild(edge);
    edge.addEventListener('mouseenter', function () { if (body.classList.contains('becSbHide')) { SB.classList.add('becPeek'); } });
    SB.addEventListener('mouseleave', function () { SB.classList.remove('becPeek'); });
    window.addEventListener('resize', pos);
    SB.addEventListener('transitionend', pos);
    var saved = '0'; try { saved = localStorage.getItem(KEY) || '0'; } catch (e) {}
    setHidden(saved === '1');
    requestAnimationFrame(function () { body.classList.add('becReady'); });
  }

  /* ===================== TECHNICIAN: original behavior ===================== */
  function initLegacy(SB) {
    var KEY = 'becSidebarHidden';
    var style = document.createElement('style');
    style.textContent =
      'body.becReady #technicianSidebar,body.becReady .shell{transition:grid-template-columns .26s ease;}' +
      'body.becSbHide .shell{grid-template-columns:0 minmax(0,1fr) !important;}' +
      'body.becSbHide #technicianSidebar{overflow:hidden;}' +
      'body.becSbHide #technicianSidebar.becPeek{position:fixed;left:0;top:0;height:100vh;width:290px;z-index:1200;overflow:auto;box-shadow:0 0 50px rgba(0,0,0,.5);}' +
      '#becSbToggle{display:inline-flex;align-items:center;justify-content:center;background:rgba(0,0,0,.05);border:1px solid rgba(0,0,0,.08);cursor:pointer;font-size:1rem;color:inherit;width:36px;height:36px;border-radius:9px;transition:background .15s;}' +
      '#becSbToggle:hover{background:rgba(0,0,0,.1);}' +
      '#becSbToggle.becFloat{position:fixed;top:14px;left:14px;z-index:1300;background:#7B1D1D;color:#fff;border-color:rgba(201,150,12,.5);box-shadow:0 4px 14px rgba(44,10,10,.3);}' +
      '#becSbEdge{position:fixed;left:0;top:0;width:16px;height:100vh;z-index:1100;}' +
      'body:not(.becSbHide) #becSbEdge{display:none;}';
    document.head.appendChild(style);

    var btn = document.createElement('button');
    btn.id = 'becSbToggle';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Toggle sidebar');
    btn.innerHTML = '<i class="fas fa-bars"></i>';
    var topbar = document.querySelector('.topbar');
    var isFloat = false;
    if (topbar) { btn.style.marginRight = '.6rem'; topbar.insertBefore(btn, topbar.firstChild); }
    else { isFloat = true; btn.classList.add('becFloat'); body.appendChild(btn); }

    function positionFloat() {
      if (!isFloat) return;
      var hidden = body.classList.contains('becSbHide');
      var w = hidden ? 0 : (SB.offsetWidth || 290);
      btn.style.left = (w + 14) + 'px';
    }
    function setHidden(h) {
      body.classList.toggle('becSbHide', h);
      btn.setAttribute('aria-expanded', (!h).toString());
      try { localStorage.setItem(KEY, h ? '1' : '0'); } catch (e) {}
      positionFloat();
    }
    btn.addEventListener('click', function () { SB.classList.remove('becPeek'); setHidden(!body.classList.contains('becSbHide')); });

    var edge = document.createElement('div');
    edge.id = 'becSbEdge';
    body.appendChild(edge);
    edge.addEventListener('mouseenter', function () { if (body.classList.contains('becSbHide')) { SB.classList.add('becPeek'); } });
    SB.addEventListener('mouseleave', function () { SB.classList.remove('becPeek'); });

    window.addEventListener('resize', positionFloat);
    if (isFloat) { SB.addEventListener('transitionend', positionFloat); }

    var saved = '0';
    try { saved = localStorage.getItem(KEY) || '0'; } catch (e) {}
    setHidden(saved === '1');
    requestAnimationFrame(function () { body.classList.add('becReady'); });
  }
})();
