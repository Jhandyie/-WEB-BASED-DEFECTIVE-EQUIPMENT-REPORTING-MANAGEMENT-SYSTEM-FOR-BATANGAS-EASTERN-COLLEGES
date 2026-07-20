/*
 * search_premium.js — premium upgrade for the shared admin search box (.fsw/.fsi).
 * Additive & non-breaking: it only restyles the existing input and adds a clear (×)
 * button. The input keeps its id/value/oninput handler, so filtering/search is
 * unchanged — clearing simply dispatches an 'input' event so the page re-runs its
 * own search with an empty term.
 * Usage: <script src="assets/search_premium.js"></script>
 */
(function () {
  if (window.__becSearchPremium) return; window.__becSearchPremium = true;

  var STYLE_ID = 'sp-search-style';
  function injectStyle() {
    if (document.getElementById(STYLE_ID)) return;
    // Higher-specificity rules (.fsw .fsi) placed after the page CSS, so they win.
    var css = ''
      + '.fsw{transition:transform .16s;}'
      + '.fsw > i{transition:color .18s;z-index:1;}'
      + '.fsw .fsi{border-radius:999px;padding-right:2.1rem;'
      +   'transition:border-color .18s,box-shadow .18s,background .18s;}'
      + '.fsw:focus-within .fsi{border-color:var(--m3,#7B1D1D);background:var(--s1,#fff);'
      +   'box-shadow:0 0 0 4px rgba(123,29,29,.11);}'
      + '.fsw:focus-within > i{color:var(--m3,#7B1D1D);}'
      + '.fsw .fsc{position:absolute;right:.42rem;top:50%;transform:translateY(-50%) scale(.75);'
      +   'width:1.4rem;height:1.4rem;border:none;border-radius:50%;background:var(--bdr,#E2D9CC);'
      +   'color:var(--t2,#5C3838);cursor:pointer;display:flex;align-items:center;justify-content:center;'
      +   'font-size:.82rem;line-height:1;padding:0;opacity:0;pointer-events:none;'
      +   'transition:opacity .16s,transform .16s,background .16s,color .16s;}'
      + '.fsw .fsc.show{opacity:1;pointer-events:auto;transform:translateY(-50%) scale(1);}'
      + '.fsw .fsc:hover{background:var(--m3,#7B1D1D);color:#fff;}'
      + '.fsw .fsc:active{transform:translateY(-50%) scale(.9);}';
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    document.head.appendChild(s);
  }

  function enhance(w) {
    if (w.dataset.spInit) return;
    var inp = w.querySelector('.fsi');
    if (!inp) return;
    w.dataset.spInit = '1';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'fsc';
    btn.setAttribute('aria-label', 'Clear search');
    btn.innerHTML = '&times;';
    w.appendChild(btn);

    function upd() { btn.classList.toggle('show', (inp.value || '').length > 0); }
    inp.addEventListener('input', upd);
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      inp.value = '';
      // let the page's own oninput/search handler react to the cleared value
      inp.dispatchEvent(new Event('input', { bubbles: true }));
      upd();
      inp.focus();
    });
    upd();
  }

  function scan(root) { (root || document).querySelectorAll('.fsw').forEach(enhance); }

  function init() { injectStyle(); scan(document); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  if (window.MutationObserver) {
    new MutationObserver(function (muts) {
      muts.forEach(function (mu) {
        mu.addedNodes && mu.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          if (n.matches && n.matches('.fsw')) enhance(n);
          if (n.querySelectorAll) scan(n);
        });
      });
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
})();
