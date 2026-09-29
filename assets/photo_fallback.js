/*!
 * photo_fallback.js — an uploaded photo that no longer exists shows a plain
 * "Photo no longer available" box instead of the browser's broken-image icon.
 *
 * Why: the server moved on 2026-09-29, and 13 photos from 10 reports existed
 * only on the old machine's disk. The database still lists them, so every page
 * that shows evidence - the admin report view, the technician's task, Track
 * Report, the public board's details, the printed repair form - asked for a
 * file that answers 404 and drew a torn-image icon where a photo should be.
 * The pages build their photo lists five different ways, so this catches the
 * failure where it happens instead: any <img> pointing into uploads/ that fails
 * to load, now or later (the public board adds its images after a tap).
 *
 * Only uploads/ images are touched; logos and icons are left alone. The box
 * takes the photo's place at the size the page gave it, and is announced to
 * screen readers as "Photo no longer available".
 */
(function () {
  'use strict';

  function isUpload(img) {
    return /(^|\/)uploads\//.test(img.getAttribute('src') || '');
  }

  function swap(img) {
    if (!img.parentNode || img.getAttribute('data-photo-gone') === '1') return;
    img.setAttribute('data-photo-gone', '1');
    var cs = window.getComputedStyle(img);
    var box = document.createElement('div');
    box.className = 'photo-gone';
    box.setAttribute('role', 'img');
    box.setAttribute('aria-label', 'Photo no longer available');
    box.style.cssText =
      'display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;' +
      'box-sizing:border-box;min-width:110px;min-height:84px;padding:8px;vertical-align:top;' +
      'border:1px dashed #D9C6C0;border-radius:10px;background:#FBF8F1;color:#8A6E6E;' +
      'font:600 12px/1.3 system-ui,-apple-system,"Segoe UI",sans-serif;text-align:center;';
    // Keep the slot the page drew for the photo, so a grid does not reflow.
    if (cs.width && cs.width !== 'auto' && parseFloat(cs.width) > 40) box.style.width = cs.width;
    if (cs.height && cs.height !== 'auto' && parseFloat(cs.height) > 40) box.style.height = cs.height;
    box.innerHTML =
      '<svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/>' +
      '<circle cx="9" cy="10" r="1.6"/><path d="M21 16l-5-5-8 8"/><path d="M3 3l18 18"/></svg>' +
      '<span>Photo no longer available</span>';
    img.parentNode.replaceChild(box, img);
  }

  // Load errors do not bubble, but they do pass through the capture phase.
  document.addEventListener('error', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG' && isUpload(t)) swap(t);
  }, true);

  // Photos that had already failed before this script ran.
  function sweep() {
    var imgs = document.querySelectorAll('img');
    for (var i = 0; i < imgs.length; i++) {
      var img = imgs[i];
      if (isUpload(img) && img.complete && img.naturalWidth === 0 && img.getAttribute('src')) swap(img);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sweep);
  else sweep();
  window.addEventListener('load', sweep);
})();
