<?php
/**
 * includes/site_reveal.php — shared scroll-reveal (subtle, professional).
 * Include once before </body>. Auto-reveals common content blocks; always
 * falls back to visible (safety timeout) so nothing can get stuck hidden.
 */
if (defined('SITE_REVEAL_RENDERED')) { return; }
define('SITE_REVEAL_RENDERED', true);
?>
<style>
.sr-reveal{opacity:0;transform:translateY(24px);transition:opacity .6s cubic-bezier(.22,1,.36,1),transform .6s cubic-bezier(.22,1,.36,1);}
.sr-reveal.in{opacity:1;transform:none;}
@media (prefers-reduced-motion:reduce){.sr-reveal{opacity:1!important;transform:none!important;transition:none;}}
</style>
<script>
(function(){
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var sel = '.card,.panel,.stat,.stats,.page-header,.brand-hero,.brand-process,.brand-foot,.panel-eyebrow,.panel-title,.panel-sub,.pills,.notice,.fg,.action-row,.result,.rep-card,.sbhero-in';
  var els = [].slice.call(document.querySelectorAll(sel));
  if (!els.length) return;
  els.forEach(function(el){ el.classList.add('sr-reveal'); });
  function showAll(){ els.forEach(function(el){ el.classList.add('in'); }); }
  if (reduce || !('IntersectionObserver' in window)) { showAll(); return; }
  try {
    var io = new IntersectionObserver(function(en){
      en.forEach(function(e){ if(e.isIntersecting){ e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: .1, rootMargin: '0px 0px -40px 0px' });
    els.forEach(function(el){ io.observe(el); });
  } catch(e){ showAll(); return; }
  setTimeout(showAll, 1500); // safety net
})();
</script>
