/*
 * camera_capture.js — "Take Photo / Record Video" for the evidence uploaders.
 *
 * Opens the device's NATIVE camera (via a hidden capture input) and feeds the
 * result straight into an existing <input type="file">, so all the page's
 * current preview / limit / remove logic keeps working. Built for phones —
 * reporters and technicians capture evidence on their mobile device. On a
 * desktop with no camera the same button simply opens a file chooser.
 *
 * Markup: a button with
 *     data-camera="photo|video"  data-camera-target="#photo-input"
 * The target is the real file input the captured File is appended to.
 *
 * Usage: <script src="assets/camera_capture.js"></script>
 */
(function () {
  if (window.__becCamera) return; window.__becCamera = true;

  var CSS = ''
    + '.cam-trigger{display:inline-flex;align-items:center;gap:.45rem;padding:.5rem .9rem;border-radius:10px;cursor:pointer;'
    +   'font-family:inherit;font-size:.8rem;font-weight:600;border:1.5px solid var(--maroon,#7B1D1D);'
    +   'background:var(--maroon,#7B1D1D);color:#fff;transition:filter .15s,transform .12s;}'
    + '.cam-trigger:hover{filter:brightness(1.08);}'
    + '.cam-trigger:active{transform:translateY(1px);}'
    + '.cam-trigger i{font-size:.85rem;}'
    + '.cam-trigger.ghost{background:transparent;color:var(--maroon,#7B1D1D);}'
    + '.cam-row{display:flex;justify-content:center;gap:.55rem;margin:.65rem 0 .2rem;flex-wrap:wrap;}';
  var st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);

  /* append a captured File to the target input, keeping existing files, then fire change */
  function deliver(targetSel, file) {
    var inp = document.querySelector(targetSel);
    if (!inp) return;
    try {
      var dt = new DataTransfer();
      if (inp.files) { for (var i = 0; i < inp.files.length; i++) dt.items.add(inp.files[i]); }
      dt.items.add(file);
      inp.files = dt.files;
    } catch (e) { /* older browsers can't set files programmatically */ }
    inp.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function openCamera(mode, targetSel) {
    var cin = document.createElement('input');
    cin.type = 'file';
    cin.accept = mode === 'video' ? 'video/*' : 'image/*';
    cin.setAttribute('capture', 'environment'); // rear camera on phones; ignored on desktop
    cin.style.display = 'none';
    document.body.appendChild(cin);
    cin.addEventListener('change', function () {
      Array.prototype.forEach.call(cin.files || [], function (f) { deliver(targetSel, f); });
      setTimeout(function () { cin.remove(); }, 0);
    });
    cin.click();
  }

  function onTrigger(e) {
    e.preventDefault();
    var btn = e.currentTarget;
    var mode = btn.getAttribute('data-camera') === 'video' ? 'video' : 'photo';
    openCamera(mode, btn.getAttribute('data-camera-target'));
  }

  function scan(root) {
    (root || document).querySelectorAll('[data-camera]').forEach(function (b) {
      if (b.dataset.camInit) return; b.dataset.camInit = '1';
      b.addEventListener('click', onTrigger);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(document); });
  else scan(document);

  if (window.MutationObserver) {
    new MutationObserver(function (m) {
      m.forEach(function (u) {
        u.addedNodes && u.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          if (n.matches && n.matches('[data-camera]')) { if (!n.dataset.camInit) { n.dataset.camInit = '1'; n.addEventListener('click', onTrigger); } }
          if (n.querySelectorAll) scan(n);
        });
      });
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
})();
