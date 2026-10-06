<?php
/**
 * includes/install_app.php — "Get the app" for reporters and the public pages.
 *
 * The technician portal has been installable since August (manifest.webmanifest).
 * This is the same thing for everyone else, as an app of its own
 * (manifest-reporter.webmanifest, id bec-pmo-reporter) that opens on the reporter
 * sign-in, so a student can report a broken unit from the home screen in two taps.
 *
 * Any element carrying data-install-app is a trigger:
 *   - Chrome, Edge, Samsung Internet: the browser's own install prompt, one tap.
 *   - iPhone/iPad, or a browser that has not offered the prompt yet: a short sheet
 *     with the steps for that device, so the button never does nothing.
 *   - Inside the installed app: every trigger hides itself.
 *
 * Deliberately NOT on the report form (student_dashboard.php): the defense panel
 * asked for that screen to carry nothing but its four questions.
 *
 * Self-contained like site_nav.php — its CSS is emitted here, inside <body>, so it
 * outranks the page's own sheets. Safe to require twice; only the first prints.
 */
if (defined('BEC_INSTALL_APP_INCLUDED')) { return; }
define('BEC_INSTALL_APP_INCLUDED', true);
?>
<style>
[data-install-app][hidden]{display:none!important;}
.iapp-ovl{position:fixed;inset:0;z-index:10050;display:flex;align-items:center;justify-content:center;padding:1.25rem;
  background:rgba(28,16,8,.5);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);opacity:0;transition:opacity .2s ease;
  font-family:'DM Sans',system-ui,sans-serif;}
.iapp-ovl[hidden]{display:none;}
.iapp-ovl.open{opacity:1;}
.iapp-sheet{position:relative;width:100%;max-width:440px;max-height:calc(100vh - 2.5rem);overflow:auto;background:#fff;border-radius:18px;
  box-shadow:0 24px 60px rgba(44,10,10,.3);padding:1.5rem 1.5rem 1.35rem;color:#1C1008;transform:translateY(10px);transition:transform .22s ease;}
.iapp-ovl.open .iapp-sheet{transform:none;}
.iapp-x{position:absolute;top:.75rem;right:.75rem;width:40px;height:40px;border-radius:50%;border:1px solid #E8DDD0;background:#fff;
  color:#5C3838;font-size:1rem;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;}
.iapp-x:hover{background:#FBF6EE;}
.iapp-head{display:flex;align-items:center;gap:.9rem;padding-right:2.5rem;margin-bottom:1rem;}
.iapp-head img{width:56px;height:56px;border-radius:50%;flex-shrink:0;box-shadow:0 4px 14px rgba(74,14,14,.25);}
.iapp-head b{display:block;font-family:'Fraunces',Georgia,serif;font-size:1.25rem;line-height:1.2;color:#4A0E0E;}
.iapp-head span{display:block;font-size:.9rem;color:#6F564A;margin-top:.2rem;}
.iapp-why{list-style:none;margin:0 0 1.1rem;padding:.75rem .9rem;background:#FBF6EE;border:1px solid #F0E6D6;border-radius:12px;}
.iapp-why li{display:flex;align-items:center;gap:.6rem;font-size:.92rem;padding:.22rem 0;color:#3B2A22;}
.iapp-why i{width:18px;text-align:center;color:#C9960C;}
.iapp-h{font-size:.74rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#7B1D1D;margin:0 0 .55rem;}
.iapp-steps ol{list-style:none;margin:0;padding:0;counter-reset:iapp;}
.iapp-steps li{display:flex;align-items:flex-start;gap:.75rem;font-size:.95rem;line-height:1.45;padding:.5rem 0;border-top:1px solid #F3ECE2;}
.iapp-steps li:first-child{border-top:0;}
.iapp-steps li::before{counter-increment:iapp;content:counter(iapp);flex-shrink:0;width:26px;height:26px;border-radius:50%;
  background:#4A0E0E;color:#fff;font-size:.8rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;margin-top:.05rem;}
.iapp-steps li i{color:#7B1D1D;margin:0 .15rem;}
.iapp-note{font-size:.95rem;line-height:1.5;margin:0;padding:.75rem .9rem;border-radius:12px;background:#FFF7E6;border:1px solid #F3D9A4;}
.iapp-go{width:100%;margin-top:1.1rem;min-height:48px;border:0;border-radius:12px;background:#4A0E0E;color:#fff;font:700 1rem 'DM Sans',sans-serif;
  cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:.55rem;}
.iapp-go:hover{background:#7B1D1D;}
.iapp-go[hidden]{display:none;}
.iapp-toast{position:fixed;left:50%;bottom:1.5rem;z-index:10060;transform:translateX(-50%);max-width:calc(100vw - 2rem);
  padding:.8rem 1.1rem;border-radius:12px;background:#1C1008;color:#fff;font:600 .92rem 'DM Sans',sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.25);}
.iapp-toast[hidden]{display:none;}
.iapp-ovl :focus-visible{outline:3px solid #C9960C;outline-offset:2px;}
@media(max-width:640px){
  /* a bottom sheet on a phone: the thumb is already there */
  .iapp-ovl{align-items:flex-end;padding:0;}
  .iapp-sheet{max-width:none;border-radius:20px 20px 0 0;padding:1.4rem 1.15rem calc(1.2rem + env(safe-area-inset-bottom));transform:translateY(30px);}
}
@media (prefers-reduced-motion:reduce){.iapp-ovl,.iapp-sheet{transition:none;}}
</style>
<div class="iapp-ovl" id="iappOvl" hidden>
  <div class="iapp-sheet" role="dialog" aria-modal="true" aria-labelledby="iappTitle">
    <button type="button" class="iapp-x" id="iappClose" aria-label="Close"><i aria-hidden="true" class="fas fa-xmark"></i></button>
    <div class="iapp-head">
      <img src="assets/app-icon-192.png" alt="" width="56" height="56">
      <div><b id="iappTitle">Get the BEC Report app</b><span>Report broken equipment from your home screen</span></div>
    </div>
    <ul class="iapp-why">
      <li><i aria-hidden="true" class="fas fa-bolt"></i> Opens straight to reporting</li>
      <li><i aria-hidden="true" class="fas fa-mobile-screen-button"></i> No app store, and almost no storage</li>
      <li><i aria-hidden="true" class="fas fa-rotate"></i> Always up to date by itself</li>
    </ul>
    <p class="iapp-h" id="iappHow">How to install</p>
    <div class="iapp-steps" id="iappSteps"></div>
    <button type="button" class="iapp-go" id="iappGo" hidden><i aria-hidden="true" class="fas fa-download"></i> Install now</button>
  </div>
</div>
<div class="iapp-toast" id="iappToast" role="status" aria-live="polite" hidden></div>
<script>
(function () {
  // Pages that already link a manifest keep theirs; the rest get the reporter app.
  if (!document.querySelector('link[rel="manifest"]')) {
    var l = document.createElement('link');
    l.rel = 'manifest'; l.href = 'manifest-reporter.webmanifest';
    document.head.appendChild(l);
  }
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
  }

  var ua = navigator.userAgent || '';
  var isIOS = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isAndroid = /android/i.test(ua);
  var host = location.hostname;
  var secure = location.protocol === 'https:' || host === 'localhost' || host === '127.0.0.1' || window.isSecureContext === true;
  var deferred = null;
  var ovl, steps, how, go, toastEl, lastFocus, toastTimer;

  function standalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  }
  function triggers() { return Array.prototype.slice.call(document.querySelectorAll('[data-install-app]')); }
  function hideTriggers() { triggers().forEach(function (b) { b.hidden = true; }); }

  function toast(msg) {
    if (!toastEl) return;
    toastEl.textContent = msg; toastEl.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { toastEl.hidden = true; }, 5000);
  }
  function list(items) {
    return '<ol>' + items.map(function (s) {
      return '<li><span>' + s + '</span></li>';
    }).join('') + '</ol>';
  }
  function icon(name) { return '<i aria-hidden="true" class="fas ' + name + '"></i>'; }
  function stepsHtml() {
    if (!secure) {
      return '<p class="iapp-note">To install the app, open the secure address <b>https://becpmo.com</b> on your phone.</p>';
    }
    if (isIOS) {
      return list([
        'Tap the <b>Share</b> button ' + icon('fa-arrow-up-from-bracket') + ' in the browser toolbar.',
        'Scroll down and tap <b>Add to Home Screen</b> ' + icon('fa-square-plus') + '.',
        'Tap <b>Add</b>. The BEC Report icon appears on your home screen.'
      ]);
    }
    if (isAndroid) {
      return list([
        'Tap the browser menu ' + icon('fa-ellipsis-vertical') + ' at the top right.',
        'Tap <b>Install app</b> or <b>Add to Home screen</b>.',
        'Tap <b>Install</b>. The BEC Report icon appears on your home screen.'
      ]);
    }
    return list([
      'Click the install icon ' + icon('fa-download') + ' at the right end of the address bar, or open the browser menu.',
      'Choose <b>Install BEC Report</b> (in some browsers: <b>Apps</b>, then <b>Install this site as an app</b>).',
      'The app opens in its own window, with a desktop and Start-menu icon.'
    ]);
  }

  function open() {
    if (!ovl) return;
    lastFocus = document.activeElement;
    steps.innerHTML = stepsHtml();
    go.hidden = !deferred;
    how.textContent = deferred ? 'Ready to install' : 'How to install';
    ovl.hidden = false;
    requestAnimationFrame(function () { ovl.classList.add('open'); });
    var x = document.getElementById('iappClose'); if (x) x.focus();
  }
  function close() {
    if (!ovl || ovl.hidden) return;
    ovl.classList.remove('open');
    setTimeout(function () { ovl.hidden = true; }, 180);
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
  }
  function install() {
    if (!deferred) { open(); return; }
    var d = deferred; deferred = null;
    try {
      d.prompt();
      d.userChoice.then(function (choice) {
        if (choice && choice.outcome === 'accepted') { hideTriggers(); close(); }
      }).catch(function () {});
    } catch (e) { open(); }
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (ovl && !ovl.hidden) { go.hidden = false; how.textContent = 'Ready to install'; }
  });
  window.addEventListener('appinstalled', function () {
    deferred = null; hideTriggers(); close();
    toast('Installed — find BEC Report on your home screen.');
  });

  function wire() {
    ovl = document.getElementById('iappOvl');
    steps = document.getElementById('iappSteps');
    how = document.getElementById('iappHow');
    go = document.getElementById('iappGo');
    toastEl = document.getElementById('iappToast');
    // This file is required from inside the shared nav, which on some pages sits
    // in a wrapper that makes its own stacking context — the sheet then rendered
    // under the BECCA button. Moved to <body>, its z-index means what it says.
    [ovl, toastEl].forEach(function (el) { if (el && el.parentNode !== document.body) document.body.appendChild(el); });
    if (standalone()) { hideTriggers(); return; }      // already running as the app
    triggers().forEach(function (b) {
      b.addEventListener('click', function (e) { e.preventDefault(); install(); });
    });
    if (go) go.addEventListener('click', install);
    var x = document.getElementById('iappClose'); if (x) x.addEventListener('click', close);
    if (ovl) ovl.addEventListener('click', function (e) { if (e.target === ovl) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    // ?app=1 opens the sheet by itself, so a "scan to get the app" QR poster lands
    // on the steps. (A browser only shows its own prompt after a tap, which is
    // what the sheet's Install now button is for.)
    if (/[?&]app=1(&|$)/.test(location.search)) setTimeout(open, 700);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wire); else wire();
})();
</script>
