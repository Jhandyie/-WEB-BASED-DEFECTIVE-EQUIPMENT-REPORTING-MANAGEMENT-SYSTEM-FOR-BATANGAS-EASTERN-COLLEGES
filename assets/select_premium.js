/*
 * select_premium.js — custom premium dropdown for native <select> filters.
 *
 * Native <select> option lists can't be styled by CSS (the browser draws them),
 * so this overlays a styled dropdown while keeping the real <select> as the
 * source of truth: choosing an option sets the native value and dispatches a
 * 'change' event, so every existing onchange handler (e.g. go()) still runs.
 *
 * Safe by design:
 *   • Auto-applies only to <select class="fsel"> and [data-premium-select].
 *   • Desktop only — on touch / narrow screens it leaves the native control
 *     alone (native mobile pickers are the better UX).
 *   • Skips multiple / size>1 selects and anything with data-no-premium-select.
 *
 * Usage: <script src="assets/select_premium.js"></script>
 */
(function () {
  if (window.__becSelectPremium) return; window.__becSelectPremium = true;

  var isTouch = ('ontouchstart' in window) || navigator.maxTouchPoints > 0;
  var narrow = window.matchMedia('(max-width:860px)');

  var CSS = ''
    + '.psel{position:relative;display:inline-block;vertical-align:middle;}'
    + '.psel-native{position:absolute;inset:0;width:100%;height:100%;opacity:0;pointer-events:none;margin:0;}'
    + '.psel-btn{display:inline-flex;align-items:center;gap:.5rem;width:100%;cursor:pointer;'
    +   'padding:.44rem 2rem .44rem .7rem;background:var(--s2,#f6f4f0);border:1.5px solid var(--bdr,#e2d9cc);'
    +   'border-radius:var(--r1,9px);font-family:inherit;font-size:.8rem;font-weight:600;color:var(--t1,#1c1008);'
    +   'position:relative;transition:border-color .16s,box-shadow .16s,background .16s;text-align:left;white-space:nowrap;}'
    + '.psel-btn:hover{border-color:var(--m3,#7B1D1D);}'
    + '.psel.open .psel-btn,.psel-btn:focus-visible{border-color:var(--m3,#7B1D1D);background:var(--s1,#fff);'
    +   'box-shadow:0 0 0 3px rgba(123,29,29,.1);outline:none;}'
    + '.psel-btn .psel-lbl{flex:1;overflow:hidden;text-overflow:ellipsis;}'
    + '.psel-btn .psel-chev{position:absolute;right:.62rem;top:50%;transform:translateY(-50%);'
    +   'font-size:.62rem;color:var(--t3,#8a7466);transition:transform .2s,color .16s;pointer-events:none;}'
    + '.psel.open .psel-btn .psel-chev{transform:translateY(-50%) rotate(180deg);color:var(--m3,#7B1D1D);}'
    + '.psel-panel{position:fixed;z-index:99990;min-width:160px;max-height:280px;overflow-y:auto;'
    +   'background:var(--s1,#fff);border:1px solid var(--bdr,#e2d9cc);border-radius:12px;'
    +   'box-shadow:0 16px 40px rgba(20,4,4,.22),0 2px 8px rgba(20,4,4,.1);padding:.35rem;'
    +   'opacity:0;transform:translateY(-6px) scale(.98);transform-origin:top;visibility:hidden;'
    +   'transition:opacity .15s ease,transform .15s ease,visibility .15s ease;font-family:inherit;}'
    + '.psel-panel.show{opacity:1;transform:translateY(0) scale(1);visibility:visible;}'
    + '.psel-opt{display:flex;align-items:center;gap:.5rem;padding:.5rem .6rem;border-radius:8px;'
    +   'font-size:.8rem;font-weight:600;color:var(--t2,#5c3838);cursor:pointer;white-space:nowrap;'
    +   'transition:background .12s,color .12s;}'
    + '.psel-opt .psel-tick{width:.9rem;flex-shrink:0;font-size:.72rem;color:var(--m3,#7B1D1D);opacity:0;}'
    + '.psel-opt:hover,.psel-opt.active{background:var(--s2,#f6f4f0);color:var(--m3,#7B1D1D);}'
    + '.psel-opt.sel{color:var(--m3,#7B1D1D);font-weight:700;}'
    + '.psel-opt.sel .psel-tick{opacity:1;}'
    + '.psel-opt.disabled{opacity:.45;cursor:default;}'
    + '@media(prefers-reduced-motion:reduce){.psel-panel{transition:none;}}';

  function injectStyle() {
    if (document.getElementById('psel-style')) return;
    var s = document.createElement('style'); s.id = 'psel-style'; s.textContent = CSS;
    document.head.appendChild(s);
  }

  var openInstance = null;

  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  function enhance(sel) {
    if (sel.dataset.pselInit) return;
    if (sel.multiple || sel.size > 1 || sel.disabled) return;
    if (sel.hasAttribute('data-no-premium-select')) return;
    if (isTouch || narrow.matches) return; // keep native on mobile/touch
    sel.dataset.pselInit = '1';

    var wrap = document.createElement('span');
    wrap.className = 'psel';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('psel-native');
    sel.setAttribute('tabindex', '-1');
    sel.setAttribute('aria-hidden', 'true');

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'psel-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span class="psel-lbl"></span><i class="fas fa-chevron-down psel-chev"></i>';
    // match the native control's width so layout doesn't shift
    wrap.appendChild(btn);

    var panel = document.createElement('div');
    panel.className = 'psel-panel';
    panel.setAttribute('role', 'listbox');

    var lbl = btn.querySelector('.psel-lbl');
    var opts = Array.prototype.slice.call(sel.options);

    function syncLabel() {
      var o = sel.options[sel.selectedIndex];
      lbl.textContent = o ? o.text : '';
    }
    function buildPanel() {
      panel.innerHTML = opts.map(function (o, i) {
        var cls = 'psel-opt' + (i === sel.selectedIndex ? ' sel' : '') + (o.disabled ? ' disabled' : '');
        return '<div class="' + cls + '" role="option" data-i="' + i + '" aria-selected="' + (i === sel.selectedIndex) + '">' +
          '<i class="fas fa-check psel-tick"></i><span>' + esc(o.text) + '</span></div>';
      }).join('');
    }

    function position() {
      var r = btn.getBoundingClientRect();
      panel.style.minWidth = r.width + 'px';
      panel.style.left = r.left + 'px';
      panel.style.top = (r.bottom + 5) + 'px';
      requestAnimationFrame(function () {
        var ph = panel.offsetHeight || 200;
        if (r.bottom + 5 + ph > window.innerHeight - 8 && r.top - ph - 5 > 0) {
          panel.style.top = (r.top - ph - 5) + 'px';
          panel.style.transformOrigin = 'bottom';
        } else {
          panel.style.transformOrigin = 'top';
        }
      });
    }

    function open() {
      if (openInstance && openInstance !== api) openInstance.close();
      buildPanel();
      document.body.appendChild(panel);
      position();
      requestAnimationFrame(function () { panel.classList.add('show'); });
      wrap.classList.add('open');
      btn.setAttribute('aria-expanded', 'true');
      openInstance = api;
      var selEl = panel.querySelector('.psel-opt.sel') || panel.querySelector('.psel-opt');
      setActive(selEl);
      document.addEventListener('mousedown', onOutside, true);
      window.addEventListener('scroll', reposOrClose, true);
      window.addEventListener('resize', reposOrClose, true);
    }
    function close() {
      panel.classList.remove('show');
      wrap.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      var p = panel;
      setTimeout(function () { if (p.parentNode) p.parentNode.removeChild(p); }, 150);
      if (openInstance === api) openInstance = null;
      document.removeEventListener('mousedown', onOutside, true);
      window.removeEventListener('scroll', reposOrClose, true);
      window.removeEventListener('resize', reposOrClose, true);
    }
    function reposOrClose() { if (wrap.classList.contains('open')) close(); }
    function onOutside(e) { if (!panel.contains(e.target) && e.target !== btn && !btn.contains(e.target)) close(); }

    function setActive(el) {
      panel.querySelectorAll('.psel-opt.active').forEach(function (n) { n.classList.remove('active'); });
      if (el) { el.classList.add('active'); el.scrollIntoView({ block: 'nearest' }); }
    }
    function choose(i) {
      if (i < 0 || i >= opts.length || opts[i].disabled) return;
      if (sel.selectedIndex !== i) {
        sel.selectedIndex = i;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      syncLabel();
      close();
      btn.focus();
    }

    panel.addEventListener('click', function (e) {
      var o = e.target.closest('.psel-opt'); if (!o || o.classList.contains('disabled')) return;
      choose(parseInt(o.dataset.i, 10));
    });
    panel.addEventListener('mousemove', function (e) {
      var o = e.target.closest('.psel-opt'); if (o && !o.classList.contains('disabled')) setActive(o);
    });

    btn.addEventListener('click', function () { wrap.classList.contains('open') ? close() : open(); });

    // keyboard
    var typeBuf = '', typeTimer = null;
    btn.addEventListener('keydown', function (e) {
      var isOpen = wrap.classList.contains('open');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!isOpen) { open(); return; }
        var cur = panel.querySelector('.psel-opt.active');
        var list = Array.prototype.slice.call(panel.querySelectorAll('.psel-opt:not(.disabled)'));
        var idx = list.indexOf(cur);
        idx = e.key === 'ArrowDown' ? Math.min(list.length - 1, idx + 1) : Math.max(0, idx - 1);
        setActive(list[idx]);
      } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        if (!isOpen) { open(); return; }
        var a = panel.querySelector('.psel-opt.active');
        if (a) choose(parseInt(a.dataset.i, 10));
      } else if (e.key === 'Escape') {
        if (isOpen) { e.preventDefault(); close(); }
      } else if (e.key.length === 1 && /\S/.test(e.key)) {
        typeBuf += e.key.toLowerCase();
        clearTimeout(typeTimer); typeTimer = setTimeout(function () { typeBuf = ''; }, 600);
        var match = opts.findIndex(function (o) { return o.text.toLowerCase().indexOf(typeBuf) === 0; });
        if (match >= 0) { if (isOpen) setActive(panel.querySelector('.psel-opt[data-i="' + match + '"]')); else choose(match); }
      }
    });

    // reflect external/programmatic changes to the native select
    sel.addEventListener('change', syncLabel);

    var api = { close: close };
    syncLabel();
  }

  function scan(root) {
    (root || document).querySelectorAll('select.fsel, select[data-premium-select]').forEach(enhance);
  }

  function init() { injectStyle(); scan(document); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  if (window.MutationObserver) {
    new MutationObserver(function (muts) {
      muts.forEach(function (mu) {
        mu.addedNodes && mu.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          if (n.matches && n.matches('select.fsel, select[data-premium-select]')) enhance(n);
          if (n.querySelectorAll) scan(n);
        });
      });
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
})();
