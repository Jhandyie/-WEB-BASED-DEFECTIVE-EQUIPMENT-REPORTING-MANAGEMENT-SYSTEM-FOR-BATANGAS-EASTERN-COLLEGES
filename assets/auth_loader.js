/*
 * auth_loader.js — shared "signing you in" loading overlay for all role logins.
 * Path-independent (no images). Usage:
 *   AuthLoader.show();                       // default message
 *   AuthLoader.show('Verifying your code…');  // custom message
 *   AuthLoader.hide();                        // dismiss (e.g. on error)
 */
(function () {
  if (window.AuthLoader) return;

  var CSS = '' +
    '.authld{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;' +
    'background:rgba(45,5,5,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);' +
    'opacity:0;visibility:hidden;transition:opacity .28s ease,visibility .28s ease;}' +
    '.authld.on{opacity:1;visibility:visible;}' +
    '.authld-card{background:#fff;border-radius:20px;padding:2.4rem 2.6rem;text-align:center;' +
    'box-shadow:0 24px 60px rgba(45,5,5,.32);max-width:340px;width:calc(100% - 3rem);' +
    'transform:translateY(10px) scale(.97);transition:transform .32s cubic-bezier(.34,1.56,.64,1);font-family:"DM Sans",system-ui,sans-serif;}' +
    '.authld.on .authld-card{transform:translateY(0) scale(1);}' +
    '.authld-ring{width:66px;height:66px;margin:0 auto 1.25rem;position:relative;}' +
    '.authld-ring svg{width:100%;height:100%;transform:rotate(-90deg);}' +
    '.authld-ring .trk{fill:none;stroke:#F0E3E0;stroke-width:6;}' +
    '.authld-ring .arc{fill:none;stroke:#7B1D1D;stroke-width:6;stroke-linecap:round;' +
    'stroke-dasharray:150;stroke-dashoffset:110;animation:authld-spin 1s linear infinite;transform-origin:center;}' +
    '@keyframes authld-spin{to{transform:rotate(360deg);}}' +
    '.authld-mono{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;' +
    'font-weight:700;font-size:.82rem;letter-spacing:.05em;color:#7B1D1D;}' +
    '.authld-msg{font-size:1.06rem;font-weight:600;color:#2D0505;margin:0 0 .35rem;}' +
    '.authld-sub{font-size:.83rem;color:#9A7A7A;margin:0 0 1.15rem;}' +
    '.authld-bar{height:5px;border-radius:99px;background:#F0E3E0;overflow:hidden;}' +
    '.authld-bar>span{display:block;height:100%;width:40%;border-radius:99px;' +
    'background:linear-gradient(90deg,#7B1D1D,#C53030);animation:authld-slide 1.15s ease-in-out infinite;}' +
    '@keyframes authld-slide{0%{margin-left:-40%;}100%{margin-left:100%;}}' +
    '@media(prefers-reduced-motion:reduce){.authld-arc,.authld-bar>span{animation-duration:2.4s;}}';

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
      '<div class="authld-card">' +
        '<div class="authld-ring">' +
          '<svg viewBox="0 0 66 66"><circle class="trk" cx="33" cy="33" r="27"/>' +
          '<circle class="arc" cx="33" cy="33" r="27"/></svg>' +
          '<span class="authld-mono">BEC</span>' +
        '</div>' +
        '<p class="authld-msg">Signing you in…</p>' +
        '<p class="authld-sub">Please wait a moment</p>' +
        '<div class="authld-bar"><span></span></div>' +
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
