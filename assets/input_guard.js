/*
 * input_guard.js — consistent, strict input rules across the system.
 *
 * Applies automatically (no per-field wiring needed) to:
 *   - <input type="tel">                → digits only
 *   - <input type="number">             → digits (+ one dot when step allows decimals)
 *   - any input/textarea with maxlength → hard-capped as you type (also for type=number,
 *                                         where the browser ignores maxlength natively)
 *   - text inputs                       → leading/trailing whitespace trimmed on blur
 *
 * Opt-in overrides via a data-guard attribute:
 *   data-guard="digits"   digits only
 *   data-guard="int"      digits only (no sign)
 *   data-guard="decimal"  digits and a single dot
 *   data-guard="alpha"    letters and spaces (names)
 *   data-guard="off"      leave this field alone
 *
 * It only filters keystrokes/paste — it never blocks submission; server-side checks remain the
 * source of truth. New fields added later are picked up automatically via a MutationObserver.
 */
(function () {
  function mode(el) {
    var g = (el.getAttribute('data-guard') || '').toLowerCase();
    if (g) return g;
    var type = (el.getAttribute('type') || '').toLowerCase();
    if (type === 'tel') return 'phone';
    if (type === 'number') {
      var step = (el.getAttribute('step') || '').toLowerCase();
      return (step && step !== 'any' && parseFloat(step) < 1) || step === 'any' ? 'decimal' : 'int';
    }
    return '';
  }

  function clean(val, m) {
    switch (m) {
      case 'phone':
      case 'digits':
      case 'int':
        return val.replace(/\D+/g, '');
      case 'decimal': {
        var s = val.replace(/[^\d.]/g, '');
        var i = s.indexOf('.');
        if (i !== -1) s = s.slice(0, i + 1) + s.slice(i + 1).replace(/\./g, ''); // keep one dot
        return s;
      }
      case 'alpha':
        return val.replace(/[^\p{L}\s.'-]/gu, '');
      default:
        return val;
    }
  }

  function cap(el, val) {
    var max = parseInt(el.getAttribute('maxlength') || el.getAttribute('data-maxlen') || '', 10);
    if (!isNaN(max) && max > 0 && val.length > max) return val.slice(0, max);
    return val;
  }

  function apply(el) {
    if (el.__guarded) return;
    var m = mode(el);
    if (m === 'off') { el.__guarded = true; return; }
    var caps = !isNaN(parseInt(el.getAttribute('maxlength') || el.getAttribute('data-maxlen') || '', 10));
    if (!m && !caps && el.tagName !== 'INPUT') return; // nothing to enforce
    el.__guarded = true;

    el.addEventListener('input', function () {
      var before = el.value;
      var after = cap(el, m ? clean(before, m) : before);
      if (after !== before) {
        var pos = el.selectionStart, drop = before.length - after.length;
        el.value = after;
        try { el.setSelectionRange(Math.max(0, pos - drop), Math.max(0, pos - drop)); } catch (e) {}
      }
    });
    // Trim/collapse whitespace on blur for plain text fields.
    var t = (el.getAttribute('type') || 'text').toLowerCase();
    if (el.tagName === 'INPUT' && (t === 'text' || t === 'search' || m === 'alpha')) {
      el.addEventListener('blur', function () {
        var v = el.value.replace(/\s{2,}/g, ' ').trim();
        if (v !== el.value) el.value = v;
      });
    }
  }

  function scan(root) {
    (root || document).querySelectorAll('input, textarea').forEach(apply);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(document); });
  else scan(document);

  if (window.MutationObserver) {
    new MutationObserver(function (muts) {
      muts.forEach(function (mu) {
        mu.addedNodes && mu.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          if (n.matches && n.matches('input, textarea')) apply(n);
          if (n.querySelectorAll) scan(n);
        });
      });
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
})();
