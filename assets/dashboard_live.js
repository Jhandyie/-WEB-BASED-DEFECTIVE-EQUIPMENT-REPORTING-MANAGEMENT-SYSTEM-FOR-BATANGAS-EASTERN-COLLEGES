/*
 * BEC admin dashboard — "live" auto-refresh.
 * Refreshes the dashboard periodically so KPIs/queues stay current, but only
 * when it is safe: tab visible, not paused, no modal open, and the admin is
 * not typing in a field. Shows a "Live · updated Xs ago" indicator (click to pause).
 */
(function () {
  var INTERVAL_MS = 90000; // 90s

  var style = document.createElement('style');
  style.textContent = '@keyframes becLivePulse{0%{box-shadow:0 0 0 0 rgba(74,222,128,.5)}70%{box-shadow:0 0 0 7px rgba(74,222,128,0)}100%{box-shadow:0 0 0 0 rgba(74,222,128,0)}}';
  document.head.appendChild(style);

  var pill = document.createElement('div');
  pill.id = 'becLivePill';
  pill.title = 'Click to pause / resume live updates';
  pill.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:1200;display:flex;align-items:center;gap:.5rem;background:#2D0505;color:#fff;border:1px solid rgba(201,150,12,.45);padding:.45rem .8rem;border-radius:999px;font:600 12px/1 "DM Sans",system-ui,sans-serif;box-shadow:0 6px 20px rgba(45,5,5,.3);cursor:pointer;user-select:none;';
  pill.innerHTML = '<span id="becLiveDot" style="width:8px;height:8px;border-radius:50%;background:#4ade80;animation:becLivePulse 2s infinite"></span><span id="becLiveTxt">Live</span>';
  document.body.appendChild(pill);

  var started = Date.now();
  var paused = false;

  pill.addEventListener('click', function () {
    paused = !paused;
    document.getElementById('becLiveDot').style.background = paused ? '#9ca3af' : '#4ade80';
    document.getElementById('becLiveDot').style.animation = paused ? 'none' : 'becLivePulse 2s infinite';
    started = Date.now();
  });

  setInterval(function () {
    var s = Math.round((Date.now() - started) / 1000);
    var ago = s < 60 ? s + 's' : Math.floor(s / 60) + 'm';
    document.getElementById('becLiveTxt').textContent = (paused ? 'Paused' : 'Live') + ' · ' + ago + ' ago';
  }, 1000);

  function isBusy() {
    if (paused) return true;
    if (document.visibilityState !== 'visible') return true;
    // any open modal/overlay?
    if (document.querySelector('.modal.open, .mo.open, #chatOverlay.open, [aria-hidden="false"].modal, .modal-overlay.open')) return true;
    // user typing?
    var a = document.activeElement;
    if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return true;
    return false;
  }

  setInterval(function () { if (!isBusy()) { location.reload(); } }, INTERVAL_MS);
})();
