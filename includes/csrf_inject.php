<?php
/**
 * csrf_inject.php — drop-in client-side CSRF wiring for a whole page.
 *
 * Include this once (near the end of <body>, or anywhere after csrf.php
 * is available) on any page that contains state-changing forms or
 * fetch()/XHR calls:
 *
 *     <?php require_once __DIR__ . '/includes/csrf_inject.php'; ?>
 *
 * It:
 *   1. Emits the current CSRF token.
 *   2. Adds a hidden `csrf_token` input to every <form> (now and for
 *      forms added later via a MutationObserver).
 *   3. Patches window.fetch and XMLHttpRequest to attach an
 *      `X-CSRF-Token` header on same-origin unsafe requests.
 *
 * Pair it with requireCsrf() on the server side of each POST handler.
 */
require_once __DIR__ . '/csrf.php';
$__csrf = csrf_token();
?>
<script>
(function () {
  var CSRF_TOKEN = <?php echo json_encode($__csrf); ?>;

  function addFieldToForm(form) {
    if (!form || form.__csrfDone) return;
    if (!form.querySelector('input[name="csrf_token"]')) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'csrf_token';
      input.value = CSRF_TOKEN;
      form.appendChild(input);
    }
    form.__csrfDone = true;
  }

  function wireAllForms() {
    document.querySelectorAll('form').forEach(addFieldToForm);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireAllForms);
  } else {
    wireAllForms();
  }

  // Catch dynamically-added forms.
  if (window.MutationObserver) {
    new MutationObserver(function () { wireAllForms(); })
      .observe(document.documentElement, { childList: true, subtree: true });
  }

  // Patch fetch (same-origin, unsafe methods only).
  if (window.fetch) {
    var origFetch = window.fetch;
    window.fetch = function (input, init) {
      init = init || {};
      var method = (init.method || (typeof input === 'object' && input.method) || 'GET').toUpperCase();
      var url = (typeof input === 'string') ? input : (input && input.url) || '';
      var sameOrigin = !/^https?:\/\//i.test(url) || url.indexOf(window.location.origin) === 0;
      if (sameOrigin && ['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(method) !== -1) {
        var headers = new Headers(init.headers || (typeof input === 'object' ? input.headers : undefined) || {});
        if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', CSRF_TOKEN);
        init.headers = headers;
      }
      return origFetch.call(this, input, init);
    };
  }

  // Patch XMLHttpRequest.
  var origOpen = XMLHttpRequest.prototype.open;
  var origSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__csrfMethod = (method || 'GET').toUpperCase();
    this.__csrfUrl = url || '';
    return origOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    try {
      var sameOrigin = !/^https?:\/\//i.test(this.__csrfUrl) || this.__csrfUrl.indexOf(window.location.origin) === 0;
      if (sameOrigin && ['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(this.__csrfMethod) !== -1) {
        this.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
      }
    } catch (e) { /* header may already be sent; ignore */ }
    return origSend.apply(this, arguments);
  };
})();
</script>
<!-- Consistent strict input rules (digits-only, length caps, trim) across all forms on the page. -->
<script src="assets/input_guard.js" defer></script>
