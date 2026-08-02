/*!
 * page_loader.js — self-contained BEC branded page loading screen.
 * Works on ANY page (root or subfolder, .php or .html): it derives its own
 * asset path from this script's URL, injects the styles + overlay, and shows
 * the loader when navigating to another same-origin page.
 * Usage:  <script src="assets/page_loader.js"></script>   (adjust ../ as needed)
 */
(function () {
  if (window.__becPageLoader) return; window.__becPageLoader = true;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var me = document.currentScript || (function () { var s = document.getElementsByTagName('script'); return s[s.length - 1]; })();
  var base = (me && me.src) ? me.src.replace(/[^\/]*$/, '') : 'assets/';   // e.g. .../assets/
  var logo = base + 'logs.png';

  // ensure Font Awesome is present (for the walking icon) — from this server,
  // not a CDN, so the icon still appears with no internet at the venue.
  if (!document.querySelector('link[href*="font-awesome"],link[href*="fontawesome"]')) {
    var fa = document.createElement('link');
    fa.rel = 'stylesheet';
    fa.href = base + 'vendor/fontawesome/css/all.min.css';
    document.head.appendChild(fa);
  }

  var css =
    "html{scrollbar-gutter:stable;}" +
    ".page-loader{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:radial-gradient(120% 120% at 50% 30%,#4A0E0E 0%,#2D0505 100%);opacity:0;visibility:hidden;transition:opacity .15s ease,visibility .15s ease;}" +
    ".page-loader.show{opacity:1;visibility:visible;}" +
    ".pl-box{display:flex;flex-direction:column;align-items:center;gap:1.05rem;text-align:center;}" +
    ".pl-emblem{position:relative;width:84px;height:84px;display:flex;align-items:center;justify-content:center;}" +
    ".pl-ring{position:absolute;inset:0;border-radius:50%;border:3px solid rgba(201,150,12,.22);border-top-color:#C9960C;animation:plSpin .8s linear infinite;}" +
    ".pl-logo{width:58px;height:58px;border-radius:50%;overflow:hidden;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 2.5px rgba(201,150,12,.35),0 7px 20px rgba(0,0,0,.45);}" +
    ".pl-logo img{width:100%;height:100%;object-fit:cover;display:block;}" +
    ".pl-text{color:rgba(255,255,255,.85);font-family:'DM Sans',sans-serif;font-size:.74rem;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;}" +
    "@keyframes plSpin{to{transform:rotate(360deg);}}" +
    // No body opacity fade: added from a late-loading script it runs after first
    // paint and makes the page flash off then on. The loader overlay covers the
    // transition; the page simply stays visible.
    "@media(prefers-reduced-motion:reduce){.pl-ring{animation:none;}}";
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  var loader;
  function init() {
    loader = document.createElement('div');
    loader.id = 'pageLoader';
    loader.className = 'page-loader';
    loader.setAttribute('role', 'status');
    loader.setAttribute('aria-live', 'polite');
    loader.setAttribute('aria-hidden', 'true');
    loader.innerHTML =
      '<div class="pl-box">' +
        '<div class="pl-emblem"><div class="pl-ring"></div>' +
          '<div class="pl-logo"><img src="' + logo + '" alt="BEC" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'<i class=&quot;fas fa-graduation-cap&quot; style=&quot;color:#7B1D1D;font-size:1.4rem&quot;></i>\'"></div>' +
        '</div>' +
        '<div class="pl-text">Loading&hellip;</div>' +
      '</div>';
    document.body.appendChild(loader);

    window.addEventListener('pageshow', function () { if (loader) loader.classList.remove('show'); });
    document.addEventListener('click', function (e) {
      var a = e.target.closest('a');
      if (!a) return;
      if (a.target === '_blank' || a.hasAttribute('download') || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
      var href = a.getAttribute('href') || '';
      if (href === '' || href.charAt(0) === '#' || /^(mailto:|tel:|javascript:)/i.test(href)) return;
      var url; try { url = new URL(a.href, location.href); } catch (_) { return; }
      if (url.origin !== location.origin) return;
      if (url.pathname === location.pathname && url.hash) return;
      e.preventDefault();
      if (loader) loader.classList.add('show');
      setTimeout(function () { window.location.href = a.href; }, reduce ? 0 : 350);
    });
  }
  if (document.body) init(); else document.addEventListener('DOMContentLoaded', init);
})();
