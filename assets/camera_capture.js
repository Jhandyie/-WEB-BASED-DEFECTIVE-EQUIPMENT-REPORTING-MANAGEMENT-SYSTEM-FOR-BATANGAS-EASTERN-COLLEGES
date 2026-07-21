/*
 * camera_capture.js — "Take Photo / Record Video" for the evidence uploaders.
 *
 * Adds camera capture that feeds straight into an existing <input type="file">
 * (so all the page's existing preview / limit / remove logic keeps working):
 *   • Phones/tablets: opens the NATIVE camera via a hidden capture input.
 *   • Desktop: opens an in-page live webcam modal (getUserMedia) — snapshot for
 *     photos, MediaRecorder for video — with a device switch when >1 camera.
 *
 * Markup: a button with
 *     data-camera="photo|video"  data-camera-target="#photo-input"
 * The target is the real file input the captured File is appended to.
 * Non-breaking: if the camera is unavailable it falls back to a file dialog.
 *
 * Usage: <script src="assets/camera_capture.js"></script>
 */
(function () {
  if (window.__becCamera) return; window.__becCamera = true;

  var isMobile = /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent)
    || (('ontouchstart' in window) && window.matchMedia('(max-width:860px)').matches);
  var hasGUM = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);

  /* ---------- styles ---------- */
  var CSS = ''
    + '.camx-ov{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;'
    +   'background:rgba(10,3,3,.86);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);opacity:0;'
    +   'visibility:hidden;transition:opacity .2s ease,visibility .2s ease;padding:1rem;}'
    + '.camx-ov.on{opacity:1;visibility:visible;}'
    + '.camx-card{background:#140404;border-radius:18px;overflow:hidden;width:100%;max-width:520px;'
    +   'box-shadow:0 24px 60px rgba(0,0,0,.55);border:1px solid rgba(201,150,12,.28);'
    +   'display:flex;flex-direction:column;font-family:"DM Sans",system-ui,sans-serif;transform:scale(.96);transition:transform .25s cubic-bezier(.34,1.56,.64,1);}'
    + '.camx-ov.on .camx-card{transform:scale(1);}'
    + '.camx-hd{display:flex;align-items:center;justify-content:space-between;padding:.7rem .9rem;color:#fff;}'
    + '.camx-hd b{font-family:"Outfit",sans-serif;font-weight:700;font-size:.92rem;display:flex;align-items:center;gap:.5rem;}'
    + '.camx-hd b i{color:#C9960C;}'
    + '.camx-x{width:34px;height:34px;border:none;border-radius:9px;background:rgba(255,255,255,.1);color:#fff;'
    +   'cursor:pointer;font-size:.95rem;display:flex;align-items:center;justify-content:center;transition:background .15s;}'
    + '.camx-x:hover{background:rgba(255,255,255,.2);}'
    + '.camx-stage{position:relative;background:#000;aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;overflow:hidden;}'
    + '.camx-stage video,.camx-stage img,.camx-stage>canvas{width:100%;height:100%;object-fit:cover;display:block;}'
    + '.camx-msg{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.6rem;color:#E9D9C9;text-align:center;padding:1.5rem;font-size:.85rem;line-height:1.5;}'
    + '.camx-msg i{font-size:1.8rem;color:#C9960C;}'
    + '.camx-rec{position:absolute;top:.6rem;left:.6rem;display:none;align-items:center;gap:.4rem;background:rgba(0,0,0,.5);'
    +   'padding:.25rem .6rem;border-radius:999px;color:#fff;font-size:.72rem;font-weight:700;}'
    + '.camx-rec.on{display:inline-flex;}'
    + '.camx-rec .dot{width:9px;height:9px;border-radius:50%;background:#EF4444;animation:camxBlink 1s steps(2,start) infinite;}'
    + '@keyframes camxBlink{50%{opacity:.2;}}'
    + '.camx-ft{display:flex;align-items:center;justify-content:center;gap:1.3rem;padding:.9rem;background:#140404;}'
    + '.camx-shutter{width:64px;height:64px;border-radius:50%;border:4px solid #fff;background:#C9960C;cursor:pointer;'
    +   'transition:transform .12s,background .15s;flex-shrink:0;}'
    + '.camx-shutter:hover{transform:scale(1.06);}'
    + '.camx-shutter:active{transform:scale(.94);}'
    + '.camx-shutter.rec{background:#EF4444;border-radius:14px;width:56px;height:56px;}'
    + '.camx-side{width:44px;height:44px;border-radius:50%;border:none;background:rgba(255,255,255,.12);color:#fff;'
    +   'cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;transition:background .15s;flex-shrink:0;}'
    + '.camx-side:hover{background:rgba(255,255,255,.22);}'
    + '.camx-side[hidden]{display:none;}'
    + '.camx-confirm{display:none;gap:.7rem;width:100%;padding:0 .3rem;}'
    + '.camx-confirm.on{display:flex;}'
    + '.camx-btn{flex:1;padding:.72rem;border-radius:11px;border:none;font-family:inherit;font-weight:700;font-size:.86rem;cursor:pointer;transition:filter .15s,transform .12s;}'
    + '.camx-btn:active{transform:translateY(1px);}'
    + '.camx-btn.use{background:linear-gradient(135deg,#C9960C,#A9780A);color:#1C1008;}'
    + '.camx-btn.retake{background:rgba(255,255,255,.12);color:#fff;}'
    + '.camx-btn:hover{filter:brightness(1.06);}'
    /* trigger buttons on the host page */
    + '.cam-trigger{display:inline-flex;align-items:center;gap:.45rem;padding:.5rem .9rem;border-radius:10px;cursor:pointer;'
    +   'font-family:inherit;font-size:.8rem;font-weight:600;border:1.5px solid var(--maroon,#7B1D1D);'
    +   'background:var(--maroon,#7B1D1D);color:#fff;transition:filter .15s,transform .12s;}'
    + '.cam-trigger:hover{filter:brightness(1.08);}'
    + '.cam-trigger:active{transform:translateY(1px);}'
    + '.cam-trigger i{font-size:.85rem;}'
    + '.cam-trigger.ghost{background:transparent;color:var(--maroon,#7B1D1D);}'
    + '.cam-row{display:flex;justify-content:center;gap:.55rem;margin:.65rem 0 .2rem;flex-wrap:wrap;}';
  var st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);

  function ts() { return new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14); }

  /* append a captured File to the target input, keeping existing files, then fire change */
  function deliver(targetSel, file) {
    var inp = typeof targetSel === 'string' ? document.querySelector(targetSel) : targetSel;
    if (!inp) return;
    try {
      var dt = new DataTransfer();
      if (inp.files) { for (var i = 0; i < inp.files.length; i++) dt.items.add(inp.files[i]); }
      dt.items.add(file);
      inp.files = dt.files;
    } catch (e) { /* older browsers: can't programmatically set files */ }
    inp.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /* ---------- native (mobile) path ---------- */
  function native(mode, targetSel) {
    var cin = document.createElement('input');
    cin.type = 'file';
    cin.accept = mode === 'video' ? 'video/*' : 'image/*';
    cin.setAttribute('capture', 'environment');
    cin.style.display = 'none';
    document.body.appendChild(cin);
    cin.addEventListener('change', function () {
      Array.prototype.forEach.call(cin.files || [], function (f) { deliver(targetSel, f); });
      setTimeout(function () { cin.remove(); }, 0);
    });
    cin.click();
  }

  /* ---------- desktop webcam modal ---------- */
  var modal = null;
  function buildModal() {
    if (modal) return modal;
    var ov = document.createElement('div');
    ov.className = 'camx-ov';
    ov.innerHTML =
      '<div class="camx-card">' +
        '<div class="camx-hd"><b><i class="fas fa-camera"></i> <span class="camx-title">Camera</span></b>' +
          '<button type="button" class="camx-x" aria-label="Close"><i class="fas fa-times"></i></button></div>' +
        '<div class="camx-stage">' +
          '<video playsinline autoplay muted></video>' +
          '<canvas hidden></canvas>' +
          '<img hidden alt="capture preview">' +
          '<video class="camx-vprev" hidden playsinline controls></video>' +
          '<div class="camx-rec"><span class="dot"></span><span class="camx-time">0:00</span></div>' +
          '<div class="camx-msg" hidden></div>' +
        '</div>' +
        '<div class="camx-ft">' +
          '<button type="button" class="camx-side camx-switch" hidden aria-label="Switch camera"><i class="fas fa-camera-rotate"></i></button>' +
          '<button type="button" class="camx-shutter" aria-label="Capture"></button>' +
          '<button type="button" class="camx-side" style="visibility:hidden"></button>' +
          '<div class="camx-confirm"><button type="button" class="camx-btn retake"><i class="fas fa-rotate-left"></i> Retake</button>' +
            '<button type="button" class="camx-btn use"><i class="fas fa-check"></i> Use</button></div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(ov);
    modal = ov;
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    ov.querySelector('.camx-x').addEventListener('click', close);
    return ov;
  }

  var stream = null, devices = [], devIdx = 0, recorder = null, chunks = [], recTimer = null, recStart = 0;
  var curMode = 'photo', curTarget = null, captured = null;

  function stopStream() {
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    if (recTimer) { clearInterval(recTimer); recTimer = null; }
  }
  function close() {
    if (recorder && recorder.state === 'recording') { try { recorder.stop(); } catch (e) {} }
    recorder = null;
    stopStream();
    if (modal) modal.classList.remove('on');
    captured = null;
  }

  function showMsg(html) {
    var m = modal.querySelector('.camx-msg');
    m.innerHTML = html; m.hidden = false;
  }
  function hideMsg() { modal.querySelector('.camx-msg').hidden = true; }

  async function startStream() {
    stopStream();
    var vid = modal.querySelector('video:not(.camx-vprev)');
    try {
      var constraints = { audio: curMode === 'video', video: { facingMode: 'environment' } };
      if (devices.length && devices[devIdx]) constraints.video = { deviceId: { exact: devices[devIdx].deviceId } };
      stream = await navigator.mediaDevices.getUserMedia(constraints);
      vid.srcObject = stream;
      hideMsg();
      // enumerate after permission so labels/ids are available
      if (!devices.length && navigator.mediaDevices.enumerateDevices) {
        var all = await navigator.mediaDevices.enumerateDevices();
        devices = all.filter(function (d) { return d.kind === 'videoinput'; });
        modal.querySelector('.camx-switch').hidden = devices.length < 2;
      }
    } catch (err) {
      var msg = err && err.name === 'NotAllowedError'
        ? 'Camera permission was blocked. Allow camera access in your browser, or use “Choose file” instead.'
        : (err && err.name === 'NotFoundError' ? 'No camera was found on this device.'
          : 'Could not start the camera. You can still choose a file to upload.');
      showMsg('<i class="fas fa-video-slash"></i><div>' + msg + '</div>');
    }
  }

  function resetControls() {
    modal.querySelector('.camx-confirm').classList.remove('on');
    modal.querySelector('.camx-shutter').style.display = '';
    modal.querySelector('.camx-shutter').classList.remove('rec');
    modal.querySelector('.camx-rec').classList.remove('on');
    modal.querySelector('img').hidden = true;
    modal.querySelector('.camx-vprev').hidden = true;
    modal.querySelector('video:not(.camx-vprev)').hidden = false;
    captured = null;
  }

  function snapPhoto() {
    var vid = modal.querySelector('video:not(.camx-vprev)');
    var cv = modal.querySelector('canvas');
    cv.width = vid.videoWidth || 1280; cv.height = vid.videoHeight || 960;
    cv.getContext('2d').drawImage(vid, 0, 0, cv.width, cv.height);
    cv.toBlob(function (blob) {
      captured = new File([blob], 'camera-photo-' + ts() + '.jpg', { type: 'image/jpeg' });
      var img = modal.querySelector('img');
      img.src = URL.createObjectURL(blob);
      img.hidden = false; vid.hidden = true;
      modal.querySelector('.camx-shutter').style.display = 'none';
      modal.querySelector('.camx-confirm').classList.add('on');
    }, 'image/jpeg', 0.9);
  }

  function pickMime() {
    var types = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'];
    for (var i = 0; i < types.length; i++) { if (window.MediaRecorder && MediaRecorder.isTypeSupported(types[i])) return types[i]; }
    return '';
  }
  function startRec() {
    chunks = [];
    var mime = pickMime();
    try { recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream); }
    catch (e) { showMsg('<i class="fas fa-video-slash"></i><div>Recording is not supported in this browser.</div>'); return; }
    recorder.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
    recorder.onstop = function () {
      var ext = (recorder.mimeType || 'video/webm').indexOf('mp4') !== -1 ? 'mp4' : 'webm';
      var blob = new Blob(chunks, { type: recorder.mimeType || 'video/webm' });
      captured = new File([blob], 'camera-video-' + ts() + '.' + ext, { type: blob.type });
      var pv = modal.querySelector('.camx-vprev');
      pv.src = URL.createObjectURL(blob); pv.hidden = false;
      modal.querySelector('video:not(.camx-vprev)').hidden = true;
      modal.querySelector('.camx-confirm').classList.add('on');
    };
    recorder.start();
    recStart = Date.now();
    modal.querySelector('.camx-shutter').classList.add('rec');
    modal.querySelector('.camx-rec').classList.add('on');
    recTimer = setInterval(function () {
      var s = Math.floor((Date.now() - recStart) / 1000);
      modal.querySelector('.camx-time').textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
      if (s >= 60) stopRec(); // safety cap at 60s (keeps file within limits)
    }, 250);
  }
  function stopRec() {
    if (recTimer) { clearInterval(recTimer); recTimer = null; }
    modal.querySelector('.camx-shutter').classList.remove('rec');
    modal.querySelector('.camx-rec').classList.remove('on');
    modal.querySelector('.camx-shutter').style.display = 'none';
    if (recorder && recorder.state === 'recording') { try { recorder.stop(); } catch (e) {} }
  }

  function openModal(mode, targetSel) {
    curMode = mode; curTarget = targetSel; devices = []; devIdx = 0;
    buildModal();
    resetControls();
    modal.querySelector('.camx-title').textContent = mode === 'video' ? 'Record video' : 'Take photo';
    modal.querySelector('.camx-switch').hidden = true;
    var shutter = modal.querySelector('.camx-shutter');
    shutter.onclick = function () {
      if (mode === 'photo') { snapPhoto(); }
      else { if (recorder && recorder.state === 'recording') stopRec(); else startRec(); }
    };
    modal.querySelector('.camx-switch').onclick = function () {
      if (devices.length < 2) return; devIdx = (devIdx + 1) % devices.length; startStream();
    };
    modal.querySelector('.camx-confirm .retake').onclick = function () { resetControls(); startStream(); };
    modal.querySelector('.camx-confirm .use').onclick = function () {
      if (captured) deliver(curTarget, captured);
      close();
    };
    requestAnimationFrame(function () { modal.classList.add('on'); });
    startStream();
  }

  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal && modal.classList.contains('on')) close(); });

  /* ---------- wire up trigger buttons ---------- */
  function onTrigger(e) {
    var btn = e.currentTarget;
    var mode = btn.getAttribute('data-camera') === 'video' ? 'video' : 'photo';
    var target = btn.getAttribute('data-camera-target');
    e.preventDefault();
    if (hasGUM && !isMobile) openModal(mode, target);
    else native(mode, target);            // phones (native camera) or GUM-less desktop (file dialog)
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
    new MutationObserver(function (m) { m.forEach(function (u) { u.addedNodes && u.addedNodes.forEach(function (n) { if (n.nodeType === 1) { if (n.matches && n.matches('[data-camera]')) scan(n.parentNode || document); else if (n.querySelectorAll) scan(n); } }); }); })
      .observe(document.documentElement, { childList: true, subtree: true });
  }
})();
