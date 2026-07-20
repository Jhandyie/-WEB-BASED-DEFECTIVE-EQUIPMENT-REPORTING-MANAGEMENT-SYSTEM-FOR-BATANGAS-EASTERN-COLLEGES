/*
 * file_upload_premium.js — compact "premium" file picker for plain
 * <input type="file"> document uploads (CSV/XLSX/etc.), replacing the bare
 * native control with a styled drop target + selected-file chip.
 * Purely additive: the native input keeps its name/required/accept/value —
 * nothing about existing multipart form submission changes.
 * Opt-in only (never auto-applies): add data-premium-upload to the input.
 * Optional data-hint="..." overrides the helper text under the icon.
 * Usage: <script src="assets/file_upload_premium.js"></script>
 */
(function () {
  if (window.__becFileUpload) return; window.__becFileUpload = true;

  var MAROON = '#7B1D1D', MAROON_D = '#4A0E0E', GREEN = '#15803D';

  var css = ''
    + '.fup-wrap{display:inline-flex;align-items:center;gap:.4rem;max-width:100%;vertical-align:middle;}'
    + '.fup-btn{position:relative;display:inline-flex;align-items:center;gap:.6rem;padding:.55rem .85rem;'
    +   'border:1.5px dashed #D8CCBD;border-radius:10px;background:#FBF8F3;cursor:pointer;'
    +   'transition:border-color .15s,background .15s;max-width:100%;box-sizing:border-box;}'
    + '.fup-btn:hover{border-color:' + MAROON + ';background:#FBF3EC;}'
    + '.fup-wrap.drag .fup-btn{border-color:' + MAROON + ';background:#FBF3EC;border-style:solid;}'
    + '.fup-btn.has{border-style:solid;border-color:#A7D8B8;background:#F0FDF4;}'
    + '.fup-input{position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;}'
    + '.fup-ic{flex-shrink:0;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;'
    +   'font-size:.8rem;background:linear-gradient(135deg,' + MAROON + ',' + MAROON_D + ');color:#fff;}'
    + '.fup-ic.file{background:linear-gradient(135deg,' + GREEN + ',#22C55E);}'
    + '.fup-tx{display:flex;flex-direction:column;min-width:0;line-height:1.3;}'
    + '.fup-tx strong{font-size:.78rem;font-weight:700;color:#2D0505;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;}'
    + '.fup-tx small{font-size:.66rem;color:#9A7A7A;font-weight:600;white-space:nowrap;}'
    + '.fup-rm{flex-shrink:0;width:1.9rem;height:1.9rem;border:none;border-radius:8px;background:#FEF2F2;color:#DC2626;'
    +   'cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.8rem;transition:background .15s;}'
    + '.fup-rm:hover{background:#FEE2E2;}'
    + '.fup-rm[hidden]{display:none;}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  function humanSize(b) {
    if (b < 1024) return b + ' B';
    if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1024 / 1024).toFixed(1) + ' MB';
  }
  var EXT_ICON = { csv: 'fa-file-csv', txt: 'fa-file-lines', xlsx: 'fa-file-excel', xls: 'fa-file-excel', pdf: 'fa-file-pdf', doc: 'fa-file-word', docx: 'fa-file-word' };
  function extIcon(name) {
    var ext = (name.split('.').pop() || '').toLowerCase();
    return EXT_ICON[ext] || 'fa-file';
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  function render(input, ic, tx, rm) {
    var files = input.files;
    var btn = ic.closest('.fup-btn');
    if (!files || !files.length) {
      ic.className = 'fup-ic fas fa-cloud-arrow-up';
      tx.innerHTML = '<strong>Choose a file</strong><small>' + (input.dataset.hint || 'or drag &amp; drop') + '</small>';
      btn.classList.remove('has');
      rm.hidden = true;
      return;
    }
    var f = files[0];
    ic.className = 'fup-ic file fas ' + extIcon(f.name);
    tx.innerHTML = '<strong>' + esc(f.name) + '</strong><small>' + humanSize(f.size) + ' &middot; ready to upload</small>';
    btn.classList.add('has');
    rm.hidden = false;
  }

  function enhance(input) {
    if (input.dataset.fupInit) return;
    input.dataset.fupInit = '1';

    var wrap = document.createElement('span'); wrap.className = 'fup-wrap';
    input.parentNode.insertBefore(wrap, input);

    var label = document.createElement('label'); label.className = 'fup-btn';
    var ic = document.createElement('i');
    var tx = document.createElement('span'); tx.className = 'fup-tx';
    label.appendChild(input);
    label.appendChild(ic);
    label.appendChild(tx);
    input.classList.add('fup-input');

    var rm = document.createElement('button');
    rm.type = 'button'; rm.className = 'fup-rm'; rm.hidden = true;
    rm.setAttribute('aria-label', 'Remove selected file');
    rm.innerHTML = '<i class="fas fa-xmark"></i>';

    wrap.appendChild(label);
    wrap.appendChild(rm);

    render(input, ic, tx, rm);
    input.addEventListener('change', function () { render(input, ic, tx, rm); });
    rm.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      input.value = '';
      render(input, ic, tx, rm);
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
      wrap.addEventListener(ev, function (e) { e.preventDefault(); wrap.classList.add('drag'); });
    });
    ['dragleave', 'dragend'].forEach(function (ev) {
      wrap.addEventListener(ev, function () { wrap.classList.remove('drag'); });
    });
    wrap.addEventListener('drop', function (e) {
      e.preventDefault();
      wrap.classList.remove('drag');
      var dt = e.dataTransfer;
      if (!dt || !dt.files || !dt.files.length) return;
      var f = dt.files[0];
      var accept = (input.getAttribute('accept') || '').split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
      if (accept.length) {
        var name = f.name.toLowerCase();
        var ok = accept.some(function (a) {
          return a.charAt(0) === '.' ? name.slice(-a.length) === a : (f.type && f.type.indexOf(a.replace('*', '')) === 0);
        });
        if (!ok) return;
      }
      try {
        var ndt = new DataTransfer(); ndt.items.add(f); input.files = ndt.files;
      } catch (_) { return; }
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  function scan(root) { (root || document).querySelectorAll('input[type="file"][data-premium-upload]').forEach(enhance); }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(document); });
  else scan(document);

  if (window.MutationObserver) {
    new MutationObserver(function (muts) {
      muts.forEach(function (mu) {
        mu.addedNodes && mu.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          if (n.matches && n.matches('input[type="file"][data-premium-upload]')) enhance(n);
          if (n.querySelectorAll) scan(n);
        });
      });
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
})();
