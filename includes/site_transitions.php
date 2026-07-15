<?php
/**
 * includes/site_transitions.php — branded page loading screen + smooth transitions.
 * Shows a BEC-branded loader overlay when navigating to another page, and fades
 * the incoming page in. Progressive: if JS/motion is off, pages render normally.
 * Include once before </body>.
 */
if (defined('SITE_TRANSITIONS_RENDERED')) { return; }
define('SITE_TRANSITIONS_RENDERED', true);
?>
<style>
/* reserve the scrollbar gutter on every page so the viewport width — and thus
   the perfectly-centered loader — is identical whether a page scrolls or not */
html{scrollbar-gutter:stable;font-size:106.25%;} /* global ~+6% readability bump (16→17px); all rem sizes scale */
.page-loader{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;
  background:radial-gradient(120% 120% at 50% 30%,#4A0E0E 0%,#2D0505 100%);
  opacity:0;visibility:hidden;transition:opacity .15s ease,visibility .15s ease;}
.page-loader.show{opacity:1;visibility:visible;}
.pl-box{display:flex;flex-direction:column;align-items:center;gap:1.05rem;text-align:center;}
.pl-emblem{position:relative;width:84px;height:84px;display:flex;align-items:center;justify-content:center;}
.pl-ring{position:absolute;inset:0;border-radius:50%;border:3px solid rgba(201,150,12,.22);border-top-color:#C9960C;animation:plSpin .8s linear infinite;}
.pl-logo{width:58px;height:58px;border-radius:50%;overflow:hidden;background:#fff;display:flex;align-items:center;justify-content:center;
  box-shadow:0 0 0 2.5px rgba(201,150,12,.35),0 7px 20px rgba(0,0,0,.45);}
.pl-logo img{width:100%;height:100%;object-fit:cover;display:block;}
.pl-text{color:rgba(255,255,255,.85);font-family:'DM Sans',sans-serif;font-size:.74rem;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;}
@keyframes plSpin{to{transform:rotate(360deg);}}
/* opacity-only page fade — NO transform on <body>, so it never becomes a
   containing block for the fixed loader (which would shift its center). */
@media (prefers-reduced-motion: no-preference){
  body.page-fade{animation:pageFadeIn .45s ease both;}
  @keyframes pageFadeIn{from{opacity:0;}to{opacity:1;}}
}
@media (prefers-reduced-motion: reduce){ .pl-ring{animation:none;} }
</style>

<div id="pageLoader" class="page-loader" role="status" aria-live="polite" aria-hidden="true">
  <div class="pl-box">
    <div class="pl-emblem">
      <div class="pl-ring"></div>
      <div class="pl-logo"><img src="assets/logs.png" alt="BEC" onerror="this.style.display='none';this.parentElement.innerHTML='<i class=&quot;fas fa-graduation-cap&quot; style=&quot;color:#7B1D1D;font-size:1.8rem&quot;></i>'"></div>
    </div>
    <div class="pl-text">Loading&hellip;</div>
  </div>
</div>

<script>
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var loader = document.getElementById('pageLoader');
  var textEl = loader ? loader.querySelector('.pl-text') : null;
  // Make the loader a direct child of <body> so position:fixed is ALWAYS relative
  // to the viewport (never trapped inside a transformed / overflow-hidden wrapper).
  if (loader && loader.parentNode !== document.body) { document.body.appendChild(loader); }
  document.body.classList.add('page-fade');
  // Reset the loader whenever a page is shown (covers back/forward cache too).
  window.addEventListener('pageshow', function () { if (loader) loader.classList.remove('show'); });

  // Task-specific labels for admin action submits (keyed by the form's action value).
  var LABELS = {
    mark_received:'Marking as received', approve:'Approving report', reject:'Rejecting report',
    verify_completion:'Verifying completion', return_to_progress:'Reopening task', delete:'Deleting',
    delete_all:'Clearing notifications', assign:'Assigning technician', reassign:'Reassigning technician',
    unassign:'Unassigning', mark_read:'Updating notifications', mark_all_read:'Marking all read',
    broadcast:'Sending announcement', backup:'Creating backup', restore:'Recovering data',
    create_user:'Creating user', update_user:'Saving changes', add_user:'Adding user',
    reset_password:'Resetting password', import:'Importing directory', verify:'Verifying & closing'
  };
  function prettify(s){ s=String(s||'').replace(/[_-]+/g,' ').trim(); return s?s.charAt(0).toUpperCase()+s.slice(1):''; }
  function showLoader(text){
    if (!loader) return;
    if (textEl) textEl.textContent = (text || 'Loading') + '…';
    loader.classList.add('show'); // stays until the next page paints
  }
  window.becShowLoader = showLoader; // exposed for pages that submit programmatically

  var NAV_DELAY = reduce ? 0 : 140; // was 350ms — snappier while the loader still paints

  // Link navigations.
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a');
    if (!a) return;
    if (a.target === '_blank' || a.hasAttribute('download') || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    var href = a.getAttribute('href') || '';
    if (href === '' || href.charAt(0) === '#' || /^(mailto:|tel:|javascript:)/i.test(href)) return;
    var url; try { url = new URL(a.href, location.href); } catch (_) { return; }
    if (url.origin !== location.origin) return;
    if (url.pathname === location.pathname && url.hash) return; // in-page anchor
    e.preventDefault();
    showLoader('Loading');
    setTimeout(function () { window.location.href = a.href; }, NAV_DELAY);
  });

  // Form submits (POST actions like mark-received / assign) — show the loader with a
  // task-specific label. Skipped for AJAX/search forms and for pages that run their
  // own action loader (the technician workspace defines window.actionLoader).
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM') return;
    if (e.defaultPrevented) return; // a page/handler already cancelled the native submit
    if (typeof window.actionLoader === 'function') return; // technician page: has its own loader
    if (f.matches('.tech-ajax, .search, [data-no-loader]') || f.hasAttribute('data-ajax')) return;
    var sub = e.submitter;
    var action = (sub && sub.name === 'action' && sub.value) ? sub.value
               : (f.querySelector('input[name="action"]') || {}).value || '';
    var label = LABELS[action] || (sub && (sub.textContent || '').trim()) || prettify(action) || 'Working';
    showLoader(label);
    // Safety net: if the submit didn't actually navigate (unexpected AJAX), release it.
    setTimeout(function () { if (loader) loader.classList.remove('show'); }, 12000);
  });
})();
</script>
