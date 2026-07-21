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
    // full-width, matches the drop zone above it, so it reads as an intentional alternative
    + '.cam-row{display:flex;margin:.35rem 0 .2rem;}'
    + '.cam-trigger{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;width:100%;'
    +   'padding:.68rem 1rem;border-radius:12px;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;'
    +   'border:1.5px solid var(--maroon,#7B1D1D);background:var(--maroon-soft,rgba(123,29,29,.07));color:var(--maroon,#7B1D1D);'
    +   'transition:background .16s,color .16s,transform .12s,box-shadow .16s;}'
    + '.cam-trigger:hover{background:var(--maroon,#7B1D1D);color:#fff;transform:translateY(-1px);box-shadow:0 5px 14px rgba(123,29,29,.22);}'
    + '.cam-trigger:active{transform:translateY(0);box-shadow:none;}'
    + '.cam-trigger i{font-size:.9rem;}'
    // "or" divider that separates the drop zone from the camera button
    + '.cam-sep{display:flex;align-items:center;gap:.7rem;margin:.75rem 0;color:var(--ink3,#8A7466);'
    +   'font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;}'
    + '.cam-sep::before,.cam-sep::after{content:"";flex:1;height:1px;background:var(--border,#E2D9CC);}'
    // compact variant for the technician\x27s tight 3-column photo fields
    + '.cam-trigger.compact{padding:.5rem .7rem;font-size:.76rem;border-radius:10px;}';
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
