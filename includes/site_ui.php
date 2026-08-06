<?php
/**
 * includes/site_ui.php — shared front-end behaviour for every portal.
 *
 * Replaces includes/site_transitions.php. That file put a full-screen branded
 * loader over every navigation and, to give it time to paint, did
 * `preventDefault()` + `setTimeout(140ms)` before letting the browser go — a
 * delay the user paid on every single click. This gives the same "something is
 * happening" feedback from a 2px top bar that costs nothing and covers nothing.
 *
 * The branded full-screen loader still exists where it belongs: assets/auth_loader.js
 * on the sign-in submit, where the wait is a real OTP/auth round trip.
 *
 * Provides:
 *   - a 2px top progress bar for navigations and non-AJAX form submits
 *   - fast [data-tip] tooltips (native title= is pinned to the OS ~1s delay and
 *     cannot be styled or sped up); existing title= markup is adopted at load
 *   - a confirmation before signing out
 *
 * Include once before </body>. includes/admin_ui.php wraps this and adds the
 * admin-only shell rules.
 *
 * NOTE: `html{font-size:106.25%}` deliberately does NOT live here. It belongs in
 * each page's <head>. Setting the root font size from an end-of-<body> include
 * re-lays-out the whole page after first paint — that is what the "glitching /
 * reload" effect actually was.
 */
if (defined('SITE_UI_RENDERED')) { return; }
define('SITE_UI_RENDERED', true);
?>
<style>
/* ---- top progress bar ---------------------------------------------------- */
#becBar{position:fixed;top:0;left:0;height:2px;width:0;z-index:99999;
  background:linear-gradient(90deg,#7B1D1D,#C9960C);
  box-shadow:0 0 8px rgba(201,150,12,.5);
  opacity:0;transition:width .22s ease,opacity .2s ease;pointer-events:none;}
#becBar.on{opacity:1;}

/* ---- tooltips ------------------------------------------------------------ */
#becTip{position:fixed;left:0;top:0;z-index:100000;max-width:260px;
  padding:.34rem .55rem;border-radius:6px;background:#2D0505;color:#fff;
  font-family:'DM Sans',system-ui,sans-serif;font-size:.7rem;font-weight:600;
  line-height:1.35;letter-spacing:.2px;
  box-shadow:0 6px 18px rgba(45,5,5,.3);
  opacity:0;transform:translateY(3px);pointer-events:none;
  transition:opacity .1s ease,transform .1s ease;}
#becTip.on{opacity:1;transform:none;}

/* ---- logout confirmation ------------------------------------------------- */
#becLogout{position:fixed;inset:0;z-index:100001;display:none;
  align-items:center;justify-content:center;padding:1rem;
  background:rgba(26,8,8,.55);}
#becLogout.on{display:flex;}
#becLogout .bl-card{background:#fff;border:1px solid #E5D9C6;border-radius:16px;
  width:100%;max-width:360px;padding:1.5rem;text-align:center;
  box-shadow:0 18px 46px rgba(45,5,5,.28);animation:becMoUp .14s cubic-bezier(.4,0,.2,1);}
#becLogout .bl-ic{width:48px;height:48px;margin:0 auto .85rem;border-radius:50%;
  display:flex;align-items:center;justify-content:center;font-size:1.15rem;
  background:rgba(180,35,24,.1);color:#B42318;}
#becLogout h3{font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:800;
  color:#1A0808;margin:0 0 .3rem;}
#becLogout p{font-size:.82rem;color:#9C7A7A;margin:0 0 1.15rem;line-height:1.45;}
#becLogout .bl-row{display:flex;gap:.55rem;justify-content:center;}
#becLogout button,#becLogout a{font-family:'DM Sans',sans-serif;font-size:.82rem;
  font-weight:700;padding:.6rem 1.15rem;border-radius:9px;cursor:pointer;
  text-decoration:none;border:1px solid #E5D9C6;}
#becLogout .bl-no{background:#FAF7F0;color:#5C3838;}
#becLogout .bl-no:hover{background:#F0EBE3;}
#becLogout .bl-yes{background:#7B1D1D;border-color:#7B1D1D;color:#fff;}
#becLogout .bl-yes:hover{background:#4A0E0E;border-color:#4A0E0E;}

/* Shared with the admin modal rules in includes/admin_ui.php. */
@keyframes becMoUp{from{transform:translateY(6px);opacity:0}to{transform:none;opacity:1}}

@media (prefers-reduced-motion:reduce){
  #becBar{transition:opacity .2s ease;}
  #becLogout .bl-card{animation:none;}
}
</style>

<div id="becBar"></div>
<div id="becTip" role="tooltip" aria-hidden="true"></div>

<div id="becLogout" role="dialog" aria-modal="true" aria-labelledby="becLogoutTitle">
  <div class="bl-card">
    <div class="bl-ic"><i class="fas fa-sign-out-alt"></i></div>
    <h3 id="becLogoutTitle">Sign out?</h3>
    <p>You will be returned to the sign-in page.</p>
    <div class="bl-row">
      <button type="button" class="bl-no" id="becLogoutNo">Cancel</button>
      <a class="bl-yes" id="becLogoutYes" href="logout.php">Sign out</a>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------- top progress bar ---------------- */
  var bar = document.getElementById('becBar');
  var creep = null, safety = null;

  function barStart() {
    if (!bar) return;
    clearInterval(creep); clearTimeout(safety);
    bar.classList.add('on');
    var w = 8;
    bar.style.width = w + '%';
    creep = setInterval(function () {          // ease toward 90%, never arrive
      w += (90 - w) * 0.18;
      bar.style.width = w.toFixed(1) + '%';
    }, 200);
    // If the navigation never happened (an AJAX handler swallowed it), release.
    safety = setTimeout(barDone, 15000);
  }
  function barDone() {
    if (!bar) return;
    clearInterval(creep); clearTimeout(safety);
    bar.style.width = '100%';
    setTimeout(function () { bar.classList.remove('on'); bar.style.width = '0'; }, 220);
  }
  window.becShowLoader = barStart;   // back-compat for the old full-screen loader

  window.addEventListener('pageshow', barDone);   // also covers bfcache restores

  // Link navigations: start the bar and let the browser navigate normally.
  // Bubble phase on purpose: a page handler that opens a modal has already
  // called preventDefault by the time this runs, so modals get no progress bar.
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented) return;
    var a = e.target.closest('a');
    if (!a) return;
    if (a.target === '_blank' || a.hasAttribute('download')) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    var href = a.getAttribute('href') || '';
    if (href === '' || href.charAt(0) === '#') return;
    if (/^(mailto:|tel:|javascript:)/i.test(href)) return;
    var url;
    try { url = new URL(a.href, location.href); } catch (_) { return; }
    if (url.origin !== location.origin) return;
    if (url.pathname === location.pathname && url.hash) return;   // in-page anchor
    barStart();
  });

  // Non-AJAX form submits. The sign-in form runs assets/auth_loader.js instead,
  // which is the one place a full-screen branded wait is honest.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM' || e.defaultPrevented) return;
    if (f.matches('.tech-ajax, .search, [data-no-loader]')) return;
    if (f.hasAttribute('data-ajax')) return;
    barStart();
  });

  /* ---------------- tooltips ---------------- */
  var tip = document.getElementById('becTip'), tipFor = null, tipTimer = null;

  // Adopt native title= so existing markup keeps working unchanged. A title
  // attribute can never beat the OS delay (~1s, not configurable), so it has to
  // go entirely. Done once at load, and again lazily on hover — some pages set
  // title from JS after load, and those would otherwise keep the slow tooltip.
  function adopt(el) {
    if (!el || el.closest('svg')) return;
    var t = el.getAttribute('title');
    if (t === null) return;
    el.removeAttribute('title');
    if (t) { el.setAttribute('data-tip', t); }
  }
  Array.prototype.forEach.call(document.querySelectorAll('[title]'), adopt);

  function place(el) {
    var r = el.getBoundingClientRect();
    var t = tip.getBoundingClientRect();
    var left = r.left + r.width / 2 - t.width / 2;
    var top  = r.bottom + 8;
    if (top + t.height > window.innerHeight - 8) { top = r.top - t.height - 8; }
    left = Math.max(8, Math.min(left, window.innerWidth - t.width - 8));
    tip.style.left = Math.round(left) + 'px';
    tip.style.top  = Math.round(Math.max(8, top)) + 'px';
  }
  function showTip(el) {
    var text = el.getAttribute('data-tip');
    if (!tip || !text) return;
    tipFor = el;
    tip.textContent = text;
    tip.style.visibility = 'hidden';
    tip.classList.add('on');
    place(el);                       // measured with the real text in place
    tip.style.visibility = '';
    tip.setAttribute('aria-hidden', 'false');
  }
  function hideTip() {
    clearTimeout(tipTimer);
    tipFor = null;
    if (!tip) return;
    tip.classList.remove('on');
    tip.setAttribute('aria-hidden', 'true');
  }

  document.addEventListener('mouseover', function (e) {
    var el = e.target.closest('[data-tip],[title]');
    if (!el || el === tipFor) return;
    adopt(el);                       // no-op unless this one still carries title=
    if (!el.hasAttribute('data-tip')) return;
    clearTimeout(tipTimer);
    tipTimer = setTimeout(function () { showTip(el); }, reduce ? 0 : 80);
  });
  document.addEventListener('mouseout', function (e) {
    if (e.target.closest('[data-tip]')) { hideTip(); }
  });
  document.addEventListener('focusin', function (e) {
    var el = e.target.closest('[data-tip]');
    if (el) { showTip(el); }
  });
  document.addEventListener('focusout', hideTip);
  document.addEventListener('click', hideTip);
  window.addEventListener('scroll', hideTip, true);

  /* ---------------- logout confirmation ---------------- */
  var lo = document.getElementById('becLogout');
  if (lo) {
    var loYes = document.getElementById('becLogoutYes');
    var closeLo = function () { lo.classList.remove('on'); };
    // Capture phase: the click listener above starts the progress bar on any
    // same-origin link, and we need to cancel this navigation before that runs.
    document.addEventListener('click', function (e) {
      var a = e.target.closest('a[href]');
      if (!a || a === loYes) return;
      if (!/(^|\/)logout\.php(\?|$)/.test(a.getAttribute('href') || '')) return;
      e.preventDefault();
      e.stopPropagation();
      loYes.setAttribute('href', a.getAttribute('href'));
      lo.classList.add('on');
      document.getElementById('becLogoutNo').focus();
    }, true);
    document.getElementById('becLogoutNo').addEventListener('click', closeLo);
    lo.addEventListener('click', function (e) { if (e.target === lo) closeLo(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && lo.classList.contains('on')) closeLo();
    });
  }
})();
</script>
