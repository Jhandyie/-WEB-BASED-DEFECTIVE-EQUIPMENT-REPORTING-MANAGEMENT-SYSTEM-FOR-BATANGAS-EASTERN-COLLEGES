/*
 * date_picker.js — premium custom calendar popup for <input type="date">.
 * Purely additive: the native input keeps its name/value/required/min/max and
 * still works via keyboard exactly as before (nothing here can break form
 * submission). It only adds a nicer-looking trigger + a custom calendar
 * overlay as a friendlier way to pick a date with the mouse.
 * Self-contained — no external CSS/JS dependency. Auto-applies to every
 * input[type="date"] on the page and to any added later (MutationObserver).
 * Usage: <script src="assets/date_picker.js"></script>  (adjust path as needed)
 * Opt out per-field with data-no-premium-date.
 */
(function () {
  if (window.__becDatePicker) return; window.__becDatePicker = true;

  var MAROON = '#7B1D1D', MAROON_D = '#4A0E0E', GOLD = '#C9960C';
  var DOW = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
  var MON = ['January','February','March','April','May','June','July','August','September','October','November','December'];

  var css = ''
    + '.pdp-field{position:relative;display:inline-flex;align-items:center;width:100%;}'
    + '.pdp-field input[type=date]{width:100%;box-sizing:border-box;padding-right:2.35rem !important;cursor:pointer;}'
    + '.pdp-field input[type=date]::-webkit-calendar-picker-indicator{opacity:0;position:absolute;right:0;width:2.35rem;height:100%;cursor:pointer;margin:0;}'
    + '.pdp-trig{position:absolute;right:.15rem;top:50%;transform:translateY(-50%);width:1.85rem;height:1.85rem;border:none;'
    +   'border-radius:7px;background:transparent;color:' + MAROON + ';display:flex;align-items:center;justify-content:center;'
    +   'cursor:pointer;font-size:.92rem;transition:background .15s,color .15s;flex-shrink:0;}'
    + '.pdp-trig:hover{background:rgba(123,29,29,.09);}'
    + '.pdp-pop{position:fixed;z-index:99998;width:272px;background:#fff;border-radius:14px;'
    +   'box-shadow:0 18px 44px rgba(20,4,4,.28),0 2px 8px rgba(20,4,4,.12);border:1px solid #EFE3DA;'
    +   'padding:.85rem .85rem .7rem;font-family:"DM Sans",system-ui,sans-serif;'
    +   'opacity:0;transform:translateY(-6px) scale(.97);transition:opacity .15s ease,transform .15s ease;}'
    + '.pdp-pop.show{opacity:1;transform:translateY(0) scale(1);}'
    + '.pdp-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;}'
    + '.pdp-mon{font-weight:800;font-size:.86rem;color:#2D0505;font-family:"Outfit",sans-serif;}'
    + '.pdp-navb{width:1.7rem;height:1.7rem;border:none;border-radius:8px;background:#FBF3EC;color:' + MAROON + ';'
    +   'cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.72rem;transition:background .15s;}'
    + '.pdp-navb:hover{background:#F3E2D6;}'
    + '.pdp-navb:disabled{opacity:.35;cursor:default;background:#FBF3EC;}'
    + '.pdp-dow{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:.15rem;}'
    + '.pdp-dow span{text-align:center;font-size:.62rem;font-weight:700;color:#B09484;text-transform:uppercase;padding:.15rem 0;}'
    + '.pdp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;}'
    + '.pdp-day{aspect-ratio:1;border:none;background:transparent;border-radius:8px;font-size:.76rem;font-weight:600;'
    +   'color:#3A2418;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .12s,color .12s;position:relative;}'
    + '.pdp-day:hover:not(:disabled){background:#F3E2D6;}'
    + '.pdp-day.out{color:#D2C0B2;font-weight:500;}'
    + '.pdp-day.today{color:' + MAROON + ';font-weight:800;}'
    + '.pdp-day.today::after{content:"";position:absolute;bottom:4px;width:4px;height:4px;border-radius:50%;background:' + GOLD + ';}'
    + '.pdp-day.sel{background:linear-gradient(135deg,' + MAROON + ',' + MAROON_D + ');color:#fff;font-weight:800;box-shadow:0 3px 8px rgba(123,29,29,.35);}'
    + '.pdp-day.sel::after{background:' + GOLD + ';}'
    + '.pdp-day:disabled{color:#E4D8CC;cursor:not-allowed;}'
    + '.pdp-ft{display:flex;justify-content:space-between;gap:.5rem;margin-top:.65rem;padding-top:.55rem;border-top:1px solid #F1E5DA;}'
    + '.pdp-ftb{border:none;background:none;font-size:.7rem;font-weight:700;color:' + MAROON + ';cursor:pointer;padding:.25rem .4rem;border-radius:6px;font-family:"DM Sans",sans-serif;}'
    + '.pdp-ftb:hover{background:#FBF3EC;}'
    + '.pdp-ftb.clr{color:#9A7A7A;}'
    + '@media(prefers-reduced-motion:reduce){.pdp-pop{transition:none;}}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function iso(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
  function parseIso(v) {
    if (!v) return null;
    var p = v.split('-'); if (p.length !== 3) return null;
    var d = new Date(+p[0], +p[1] - 1, +p[2]);
    return isNaN(d.getTime()) ? null : d;
  }

  var openPop = null, openInput = null;

  function closePop() {
    if (!openPop) return;
    openPop.classList.remove('show');
    var p = openPop, iv = openInput;
    setTimeout(function () { if (p.parentNode) p.parentNode.removeChild(p); }, 150);
    openPop = null; openInput = null;
    document.removeEventListener('mousedown', onOutside, true);
    document.removeEventListener('keydown', onKey, true);
  }
  function onOutside(e) {
    if (openPop && !openPop.contains(e.target) && e.target !== openInput && !(openInput && openInput.pdpTrig === e.target)) closePop();
  }
  function onKey(e) { if (e.key === 'Escape') closePop(); }

  function buildPopup(input) {
    var min = parseIso(input.min), max = parseIso(input.max);
    var cur = parseIso(input.value) || new Date();
    var viewY = cur.getFullYear(), viewM = cur.getMonth();
    var selVal = input.value || '';

    var pop = document.createElement('div');
    pop.className = 'pdp-pop';

    function render() {
      var today = new Date(); today.setHours(0,0,0,0);
      var first = new Date(viewY, viewM, 1);
      var startDow = first.getDay();
      var daysInMonth = new Date(viewY, viewM + 1, 0).getDate();
      var daysInPrev = new Date(viewY, viewM, 0).getDate();

      var prevDisabled = min && (viewY < min.getFullYear() || (viewY === min.getFullYear() && viewM <= min.getMonth()));
      var nextDisabled = max && (viewY > max.getFullYear() || (viewY === max.getFullYear() && viewM >= max.getMonth()));

      var html = '<div class="pdp-hd">'
        + '<button type="button" class="pdp-navb" data-nav="-1"' + (prevDisabled ? ' disabled' : '') + '><i class="fas fa-chevron-left"></i></button>'
        + '<span class="pdp-mon">' + MON[viewM] + ' ' + viewY + '</span>'
        + '<button type="button" class="pdp-navb" data-nav="1"' + (nextDisabled ? ' disabled' : '') + '><i class="fas fa-chevron-right"></i></button>'
        + '</div><div class="pdp-dow">' + DOW.map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div><div class="pdp-grid">';

      var cells = [];
      for (var i = 0; i < startDow; i++) cells.push({ d: daysInPrev - startDow + 1 + i, out: true, y: viewM === 0 ? viewY - 1 : viewY, m: viewM === 0 ? 11 : viewM - 1 });
      for (var d = 1; d <= daysInMonth; d++) cells.push({ d: d, out: false, y: viewY, m: viewM });
      var rem = 42 - cells.length;
      for (var j = 1; j <= rem; j++) cells.push({ d: j, out: true, y: viewM === 11 ? viewY + 1 : viewY, m: viewM === 11 ? 0 : viewM + 1 });

      cells.forEach(function (c) {
        var val = iso(c.y, c.m, c.d);
        var dt = new Date(c.y, c.m, c.d);
        var isToday = dt.getTime() === today.getTime();
        var isSel = val === selVal;
        var disabled = (min && dt < min) || (max && dt > max);
        var cls = 'pdp-day' + (c.out ? ' out' : '') + (isToday ? ' today' : '') + (isSel ? ' sel' : '');
        html += '<button type="button" class="' + cls + '" data-val="' + val + '"' + (disabled ? ' disabled' : '') + '>' + c.d + '</button>';
      });

      html += '</div><div class="pdp-ft">'
        + '<button type="button" class="pdp-ftb" data-today="1">Today</button>'
        + '<button type="button" class="pdp-ftb clr" data-clear="1">Clear</button>'
        + '</div>';
      pop.innerHTML = html;
    }
    render();

    pop.addEventListener('click', function (e) {
      var nav = e.target.closest('[data-nav]');
      if (nav) { viewM += (+nav.dataset.nav); if (viewM < 0) { viewM = 11; viewY--; } else if (viewM > 11) { viewM = 0; viewY++; } render(); return; }
      var day = e.target.closest('.pdp-day');
      if (day && !day.disabled) {
        input.value = day.dataset.val;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        closePop();
        return;
      }
      if (e.target.closest('[data-today]')) {
        var t = new Date();
        var v = iso(t.getFullYear(), t.getMonth(), t.getDate());
        if ((!min || t >= min) && (!max || t <= max)) {
          input.value = v;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
          closePop();
        } else { viewY = t.getFullYear(); viewM = t.getMonth(); render(); }
        return;
      }
      if (e.target.closest('[data-clear]')) {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        closePop();
      }
    });

    return pop;
  }

  function position(pop, anchor) {
    var r = anchor.getBoundingClientRect();
    var w = 272, vw = window.innerWidth, vh = window.innerHeight;
    var left = Math.min(r.left, vw - w - 12); if (left < 8) left = 8;
    var top = r.bottom + 6;
    pop.style.left = left + 'px';
    pop.style.top = top + 'px';
    // flip above if not enough room below (popup height ~320px)
    requestAnimationFrame(function () {
      var ph = pop.offsetHeight || 320;
      if (top + ph > vh - 10 && r.top - ph - 6 > 0) pop.style.top = (r.top - ph - 6) + 'px';
    });
  }

  function openFor(input) {
    if (openInput === input) { closePop(); return; }
    closePop();
    var pop = buildPopup(input);
    document.body.appendChild(pop);
    position(pop, input.pdpWrap || input);
    requestAnimationFrame(function () { pop.classList.add('show'); });
    openPop = pop; openInput = input;
    document.addEventListener('mousedown', onOutside, true);
    document.addEventListener('keydown', onKey, true);
  }

  function enhance(input) {
    if (input.dataset.pdpInit || input.disabled || input.readOnly || input.hasAttribute('data-no-premium-date')) return;
    input.dataset.pdpInit = '1';

    var wrap = document.createElement('span');
    wrap.className = 'pdp-field';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var trig = document.createElement('button');
    trig.type = 'button';
    trig.className = 'pdp-trig';
    trig.setAttribute('aria-label', 'Open calendar');
    trig.innerHTML = '<i class="fas fa-calendar-days"></i>';
    wrap.appendChild(trig);
    input.pdpTrig = trig;
    input.pdpWrap = wrap;

    trig.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openFor(input); });
    input.addEventListener('click', function (e) { e.preventDefault(); openFor(input); });
  }

  function scan(root) { (root || document).querySelectorAll('input[type="date"]').forEach(enhance); }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(document); });
  else scan(document);

  if (window.MutationObserver) {
    new MutationObserver(function (muts) {
      muts.forEach(function (mu) {
        mu.addedNodes && mu.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          if (n.matches && n.matches('input[type="date"]')) enhance(n);
          if (n.querySelectorAll) scan(n);
        });
      });
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
})();
