/*!
 * photo_shrink.js — downscale camera photos in the browser before they upload.
 *
 * A phone camera writes 4000-5000px JPEGs; one report photo in this system is
 * 5120x2880 and 8.4 MB. Nothing ever displays them larger than about 900px, so
 * every viewer afterwards — the PMO, the technician standing in the corridor on
 * mobile data — pays for detail no screen shows. This resizes each picture to
 * MAX_EDGE on its longest side and re-encodes it before the form is submitted.
 *
 * Runs entirely on the device (canvas), so it needs no image library on the
 * server. If anything goes wrong — an unreadable file, no canvas support — the
 * original file is left exactly as it was and the upload proceeds.
 *
 * Usage: <input type="file" data-shrink> inside a form; include this script.
 */
(function () {
  'use strict';

  var MAX_EDGE = 1600;      // plenty for full-screen viewing on any display
  var QUALITY  = 0.82;      // visually clean for photographs
  // Only a genuinely small file is waved through without being measured. Byte
  // size alone is the wrong test: a 5120x2880 shot can compress to a few hundred
  // KB and still cost ~59 MB of memory to decode on the phone showing it.
  var SKIP_UNDER = 120 * 1024;

  if (!window.HTMLCanvasElement || !window.FileReader || !window.DataTransfer) { return; }

  function shrink(file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png|webp)$/i.test(file.type) || file.size < SKIP_UNDER) {
        resolve(file); return;
      }
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        try {
          var scale = Math.min(1, MAX_EDGE / Math.max(img.naturalWidth, img.naturalHeight));
          if (scale === 1) { URL.revokeObjectURL(url); resolve(file); return; }
          var c = document.createElement('canvas');
          c.width  = Math.round(img.naturalWidth * scale);
          c.height = Math.round(img.naturalHeight * scale);
          var ctx = c.getContext('2d');
          ctx.imageSmoothingQuality = 'high';
          ctx.drawImage(img, 0, 0, c.width, c.height);
          c.toBlob(function (blob) {
            URL.revokeObjectURL(url);
            if (!blob || blob.size >= file.size) { resolve(file); return; }
            var name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
            resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
          }, 'image/jpeg', QUALITY);
        } catch (e) { URL.revokeObjectURL(url); resolve(file); }
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function wire(input) {
    if (input.__shrinkWired) { return; }
    input.__shrinkWired = true;
    var form = input.form;
    if (!form) { return; }

    var busy = false;
    form.addEventListener('submit', function (ev) {
      if (busy || !input.files || !input.files.length) { return; }
      var needs = [].slice.call(input.files).some(function (f) {
        return f.size >= SKIP_UNDER && /^image\/(jpeg|png|webp)$/i.test(f.type);
      });
      if (!needs) { return; }

      ev.preventDefault();
      ev.stopImmediatePropagation();   // hold the form until the files are ready
      busy = true;

      Promise.all([].slice.call(input.files).map(shrink)).then(function (files) {
        var dt = new DataTransfer();
        files.forEach(function (f) { dt.items.add(f); });
        input.files = dt.files;
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
        else { form.submit(); }
      }).catch(function () {
        busy = false;
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
        else { form.submit(); }
      });
    }, true);   // capture, so this runs before the page's own submit handler
  }

  function init() { document.querySelectorAll('input[type="file"][data-shrink]').forEach(wire); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
