<?php
/**
 * includes/site_transitions.php — smooth page fade in/out (shared).
 * Fades the page in on load and fades out before navigating to a same-origin
 * link, for a premium SPA-like feel. Fully progressive: if JS/motion is off,
 * pages just render normally. Include once before </body>.
 */
if (defined('SITE_TRANSITIONS_RENDERED')) { return; }
define('SITE_TRANSITIONS_RENDERED', true);
?>
<style>
@media (prefers-reduced-motion: no-preference) {
  body.page-fade { animation: pageFadeIn .5s cubic-bezier(.22,1,.36,1) both; }
  @keyframes pageFadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
  html.leaving body { opacity: 0; transition: opacity .24s ease; }
}
</style>
<script>
(function () {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  document.body.classList.add('page-fade');
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
    document.body.classList.remove('page-fade');
    document.documentElement.classList.add('leaving');
    setTimeout(function () { window.location.href = a.href; }, 230);
  });
  window.addEventListener('pageshow', function (ev) { if (ev.persisted) document.documentElement.classList.remove('leaving'); });
})();
</script>
