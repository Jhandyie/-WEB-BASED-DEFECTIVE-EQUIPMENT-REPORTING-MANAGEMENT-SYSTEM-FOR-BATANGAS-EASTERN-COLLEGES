/*
 * table_paginate.js — reusable client-side pagination for long admin lists.
 *
 * Usage: mark the list element with data-paginate="<per-page>".
 *   - On a <table>: paginates the rows of its first <tbody>
 *     (rows carrying data-norow, or containing an .empty placeholder, are ignored).
 *   - On any other container: add data-paginate-rows="<css-selector>" to say
 *     which children are the paginated items (e.g. ".ucard").
 *
 * A pager (info line + Prev / numbered / Next) is inserted right after the element.
 * If the list has fewer items than the page size, nothing is rendered.
 * Server-side filtering/search is unaffected: it only ever sees the rows the
 * server rendered, exactly like a normal reload.
 */
(function () {
  var STYLE_ID = 'tp-pager-style';
  function injectStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var css =
      '.tp-pager{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;' +
      'gap:.85rem;margin:1.1rem .1rem .3rem;font-family:inherit;}' +
      '.tp-pager .tp-info{font-size:.76rem;color:var(--t3,#6b7280);font-weight:600;letter-spacing:.01em;}' +
      '.tp-pager .tp-info b{color:var(--t1,#1f2937);font-weight:800;}' +
      // the numbered buttons + nav sit inside a soft inset "rail"
      '.tp-pager .tp-btns{display:inline-flex;flex-wrap:wrap;align-items:center;gap:.25rem;' +
      'padding:.28rem;background:var(--s2,#f6f4f0);border:1px solid var(--bdr,#e5e7eb);border-radius:999px;}' +
      '.tp-pager .tp-gap{padding:0 .1rem;color:var(--t4,#9ca3af);font-size:.85rem;font-weight:800;user-select:none;align-self:center;}' +
      '.tp-pager button{display:inline-flex;align-items:center;justify-content:center;min-width:2rem;' +
      'height:2rem;padding:0 .6rem;border:none;background:transparent;' +
      'color:var(--t2,#374151);border-radius:999px;cursor:pointer;font-size:.78rem;font-weight:700;' +
      'line-height:1;transition:color .16s,background .16s,box-shadow .16s,transform .16s;}' +
      '.tp-pager button:hover:not(:disabled):not(.on){background:var(--s1,#fff);color:var(--m3,#7a1220);' +
      'box-shadow:0 1px 5px rgba(0,0,0,.09);transform:translateY(-1px);}' +
      '.tp-pager button.on{background:linear-gradient(135deg,var(--m4,#4A0E0E),var(--m3,#7a1220));color:#fff;' +
      'box-shadow:0 3px 9px rgba(122,18,32,.34);transform:translateY(-1px);}' +
      // circular chevron nav buttons
      '.tp-pager button.tp-nav{min-width:2rem;width:2rem;padding:0;font-size:1rem;color:var(--t3,#6b7280);}' +
      '.tp-pager button.tp-nav:hover:not(:disabled){color:var(--m3,#7a1220);}' +
      '.tp-pager button:disabled{opacity:.35;cursor:default;box-shadow:none;transform:none;}' +
      '@media(max-width:640px){.tp-pager{justify-content:center;}.tp-pager .tp-info{width:100%;text-align:center;}}';
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    document.head.appendChild(s);
  }

  function setup(el) {
    var per = parseInt(el.getAttribute('data-paginate'), 10) || 10;
    var rows;
    if (el.tagName === 'TABLE') {
      var tb = el.tBodies[0];
      if (!tb) return;
      rows = Array.prototype.filter.call(tb.rows, function (r) {
        return !r.hasAttribute('data-norow') && !r.querySelector('.empty');
      });
    } else {
      var sel = el.getAttribute('data-paginate-rows') || ':scope > *';
      rows = Array.prototype.slice.call(el.querySelectorAll(sel));
    }
    if (rows.length <= per) return;

    var pages = Math.ceil(rows.length / per), cur = 1, noun = el.getAttribute('data-paginate-noun') || 'items';
    var pager = document.createElement('div');
    pager.className = 'tp-pager';
    el.parentNode.insertBefore(pager, el.nextSibling);

    function render() {
      rows.forEach(function (r, i) {
        r.style.display = (Math.floor(i / per) + 1 === cur) ? '' : 'none';
      });
      var start = (cur - 1) * per + 1, end = Math.min(cur * per, rows.length), btns = '';
      // window the numbered buttons so huge lists don't produce hundreds of buttons
      var lo = Math.max(1, cur - 2), hi = Math.min(pages, cur + 2);
      if (lo > 1) { btns += btn(1) + (lo > 2 ? '<span class="tp-gap">…</span>' : ''); }
      for (var p = lo; p <= hi; p++) { btns += btn(p); }
      if (hi < pages) { btns += (hi < pages - 1 ? '<span class="tp-gap">…</span>' : '') + btn(pages); }
      pager.innerHTML =
        '<span class="tp-info">Showing <b>' + start + '–' + end + '</b> of <b>' + rows.length + '</b> ' + noun + '</span>' +
        '<div class="tp-btns"><button type="button" class="tp-nav" data-p="prev" aria-label="Previous page"' + (cur === 1 ? ' disabled' : '') + '>‹</button>' +
        btns + '<button type="button" class="tp-nav" data-p="next" aria-label="Next page"' + (cur === pages ? ' disabled' : '') + '>›</button></div>';
    }
    function btn(p) { return '<button type="button" data-p="' + p + '"' + (p === cur ? ' class="on"' : '') + '>' + p + '</button>'; }

    pager.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      var p = b.getAttribute('data-p');
      if (p === 'prev') cur = Math.max(1, cur - 1);
      else if (p === 'next') cur = Math.min(pages, cur + 1);
      else cur = parseInt(p, 10) || 1;
      render();
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    render();
  }

  function init() {
    var els = document.querySelectorAll('[data-paginate]');
    if (!els.length) return;
    injectStyle();
    els.forEach(setup);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
