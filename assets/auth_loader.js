/*
 * auth_loader.js — shared "signing you in" loading screen for all role logins
 * (reporter, admin OTP, technician).
 *
 * Visually identical to the portal/page transition loader (page_loader.js): a
 * full-screen deep-maroon field with the BEC seal turning inside a gold ring.
 * Sign-in and page navigation used to look like two different products — a
 * white modal card here, the branded maroon screen everywhere else.
 *
 * The logo path is derived from this script's own URL, so it works from any
 * folder depth (root or /admin/, /technician/).
 *
 * API is unchanged:
 *   AuthLoader.show();                        // default message
 *   AuthLoader.show('Verifying your code…');  // custom message
 *   AuthLoader.show('Welcome back!', 'Opening your workspace…');
 *   AuthLoader.hide();                        // dismiss (e.g. on error)
 */
(function () {
  if (window.AuthLoader) return;

  /* logs.png lives beside this script in /assets — resolve it path-independently */
  var LOGO = (function () {
    try {
      var s = document.currentScript && document.currentScript.src;
      if (s) return s.replace(/[^/]*$/, 'logs.png');
    } catch (e) {}
    return 'assets/logs.png';
  })();

  var CSS = '' +
    '.authld{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;' +
    'background:radial-gradient(120% 120% at 50% 30%,#4A0E0E 0%,#2D0505 100%);' +
    'opacity:0;visibility:hidden;transition:opacity .28s ease,visibility .28s ease;}' +
    '.authld.on{opacity:1;visibility:visible;}' +
    '.authld-box{display:flex;flex-direction:column;align-items:center;gap:1.05rem;text-align:center;' +
    'padding:0 1.5rem;font-family:"DM Sans",system-ui,-apple-system,sans-serif;' +
    'transform:scale(.96);transition:transform .34s cubic-bezier(.34,1.56,.64,1);}' +
    '.authld.on .authld-box{transform:scale(1);}' +
    /* emblem: seal centred inside a spinning gold ring */
    '.authld-emblem{position:relative;width:84px;height:84px;display:flex;align-items:center;justify-content:center;}' +
    '.authld-ring{position:absolute;inset:0;border-radius:50%;border:3px solid rgba(201,150,12,.22);' +
    'border-top-color:#C9960C;animation:authld-spin .8s linear infinite;}' +
    '.authld-logo{width:58px;height:58px;border-radius:50%;overflow:hidden;background:#fff;' +
    'display:flex;align-items:center;justify-content:center;' +
    'box-shadow:0 0 0 2.5px rgba(201,150,12,.35),0 7px 20px rgba(0,0,0,.45);}' +
    '.authld-logo img{width:100%;height:100%;object-fit:cover;display:block;}' +
    '.authld-logo .fb{font-weight:800;font-size:.92rem;letter-spacing:.05em;color:#7B1D1D;}' +
    '@keyframes authld-spin{to{transform:rotate(360deg);}}' +
    /* wording */
    '.authld-msg{color:#fff;font-size:1.02rem;font-weight:700;margin:0;letter-spacing:.01em;}' +
    '.authld-sub{color:rgba(255,255,255,.62);font-size:.72rem;font-weight:700;margin:0;' +
    'letter-spacing:2.2px;text-transform:uppercase;}' +
    '@media(prefers-reduced-motion:reduce){.authld-ring{animation:none;}.authld-box{transition:none;}}';

  function ensure() {
    if (document.getElementById('authldOv')) return;
    var st = document.createElement('style');
    st.textContent = CSS;
    document.head.appendChild(st);
    var ov = document.createElement('div');
    ov.id = 'authldOv';
    ov.className = 'authld';
    ov.setAttribute('role', 'status');
    ov.setAttribute('aria-live', 'polite');
    ov.innerHTML =
      '<div class="authld-box">' +
        '<div class="authld-emblem">' +
          '<div class="authld-ring"></div>' +
          '<div class="authld-logo">' +
            '<img src="' + LOGO + '" alt="BEC" ' +
            'onerror="this.onerror=null;this.parentElement.innerHTML=\'<span class=&quot;fb&quot;>BEC</span>\';">' +
          '</div>' +
        '</div>' +
        '<p class="authld-msg">Signing you in&hellip;</p>' +
        '<p class="authld-sub">Please wait a moment</p>' +
      '</div>';
    document.body.appendChild(ov);
  }

  window.AuthLoader = {
    show: function (msg, sub) {
      ensure();
      if (msg) document.querySelector('#authldOv .authld-msg').textContent = msg;
      if (sub) document.querySelector('#authldOv .authld-sub').textContent = sub;
      // rAF so the transition plays even when show() runs right before navigation.
      requestAnimationFrame(function () {
        document.getElementById('authldOv').classList.add('on');
      });
    },
    hide: function () {
      var o = document.getElementById('authldOv');
      if (o) o.classList.remove('on');
    }
  };
})();
