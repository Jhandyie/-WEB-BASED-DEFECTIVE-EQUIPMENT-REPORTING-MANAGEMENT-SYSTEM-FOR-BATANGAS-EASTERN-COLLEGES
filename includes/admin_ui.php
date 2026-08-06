<?php
/**
 * includes/admin_ui.php — admin-only shell behaviour.
 *
 * The progress bar, fast tooltips and sign-out confirmation are shared with
 * every other portal and live in includes/site_ui.php; this file adds only the
 * parts that need an admin sidebar or an admin modal to mean anything.
 *
 * Include once before </body>.
 *
 * NOTE: `html{font-size:106.25%}` lives in assets/css/admin-shell.css, loaded
 * from each page's <head>. Setting the root font size from an end-of-<body>
 * include re-lays-out the whole page after first paint.
 */
if (defined('ADMIN_UI_RENDERED')) { return; }
define('ADMIN_UI_RENDERED', true);

require __DIR__ . '/site_ui.php';
?>
<style>
/* ---- calm the shell ------------------------------------------------------ */
/* The sidebar seal and the modal-header ornament shipped on infinite rotations.
   Permanently moving chrome reads as unstable on a data-dense admin page.
   Specificity (0,2,0) beats the page rules, so no !important is needed. */
.sb .seal-spin{animation:none;}
.mhd::after{animation:none;}
/* Reserve the sidebar scrollbar gutter so the nav width never jumps per page. */
.sb .sb-nav{scrollbar-gutter:stable;}
/* Modals: a full-viewport backdrop-filter is a real per-frame GPU cost and is
   most of why these "quick pop ups" felt heavy. Flat scrim, shorter entrance.
   becMoUp is defined in site_ui.php. */
.mo{backdrop-filter:none;-webkit-backdrop-filter:none;background:rgba(26,8,8,.55);}
.mo.open{animation:becMoFade .12s ease;}
.mo .mw{animation:becMoUp .14s cubic-bezier(.4,0,.2,1);}
@keyframes becMoFade{from{opacity:0}to{opacity:1}}

@media (prefers-reduced-motion:reduce){
  .mo.open,.mo .mw{animation:none;}
}
</style>

<script>
(function () {
  'use strict';
  /* ---------------- stable sidebar ---------------- */
  // Every admin page is a full reload, so .sb-nav's scroll position reset to
  // top each time — which read as the sidebar jumping around between pages.
  var nav = document.querySelector('.sb .sb-nav');
  if (!nav) return;
  var KEY = 'becSbScroll';
  try {
    var y = parseInt(sessionStorage.getItem(KEY) || '0', 10);
    if (y > 0) { nav.scrollTop = y; }
  } catch (_) {}
  nav.addEventListener('scroll', function () {
    try { sessionStorage.setItem(KEY, String(nav.scrollTop)); } catch (_) {}
  }, { passive: true });
})();
</script>
