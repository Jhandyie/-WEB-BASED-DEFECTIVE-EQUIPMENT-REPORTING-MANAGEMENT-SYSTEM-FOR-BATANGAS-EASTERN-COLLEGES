<?php
/**
 * includes/site_nav.php — shared public top navigation (self-contained).
 * Scoped .bsnav-* classes + literal colours so it works on any page
 * regardless of that page's CSS tokens. Requires Font Awesome + the
 * Fraunces/DM Sans fonts (already loaded by the public pages).
 * Optional: set $nav_active = 'home'|'public'|'track' before requiring.
 */
if (!isset($nav_active)) { $nav_active = ''; }
?>
<style>
.bsnav{position:sticky;top:0;z-index:300;background:rgba(248,243,234,.92);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-bottom:1px solid #E8DDD0;font-family:'DM Sans',sans-serif;transition:background .28s ease,box-shadow .28s ease,border-color .28s ease;}
.bsnav.scrolled{background:rgba(248,243,234,.7);backdrop-filter:blur(16px) saturate(1.3);-webkit-backdrop-filter:blur(16px) saturate(1.3);border-bottom-color:rgba(232,221,208,.55);box-shadow:0 6px 26px rgba(44,10,10,.1);}
.bsnav-in{max-width:100%;margin:0 auto;padding:0 2rem;height:62px;display:flex;align-items:center;justify-content:space-between;gap:1rem;transition:height .28s ease;}
@media(max-width:640px){.bsnav-in{padding:0 1.1rem;}
  /* 44px is the smallest comfortable touch target (WCAG 2.5.5 and both platform
     guidelines); the brand link sat at 38px. Padding only, so nothing moves —
     the tappable area just grows. Lives here rather than in one page's stylesheet
     because this nav is shared by every public page. */
  .bsnav-brand{min-height:44px;display:inline-flex;align-items:center;}
}
.bsnav.scrolled .bsnav-in{height:56px;}
.bsnav-brand{display:flex;align-items:center;gap:.6rem;text-decoration:none;}
.bsnav-seal{width:38px;height:38px;border-radius:50%;background:#fff;flex-shrink:0;display:flex;align-items:center;justify-content:center;overflow:hidden;box-shadow:0 0 0 3px rgba(201,150,12,.28);}
.bsnav-seal img{width:100%;height:100%;object-fit:cover;}
.bsnav-brand b{display:block;font-size:.95rem;font-weight:700;color:#1C1008;letter-spacing:-.01em;line-height:1.1;}
/* #9E8070 on the cream header measured 3.29:1 — under the 4.5:1 WCAG AA asks
   for text this size. #6F564A keeps the same muted feel at about 6:1. */
.bsnav-brand small{display:block;font-size:.56rem;text-transform:uppercase;letter-spacing:1.5px;color:#6F564A;}
.bsnav-links{display:flex;align-items:center;gap:.25rem;}
.bsnav-link{padding:.5rem .8rem;border-radius:9px;font-size:.84rem;font-weight:600;color:#5C3838;text-decoration:none;transition:all .15s;}
.bsnav-link:hover,.bsnav-link.active{color:#7B1D1D;background:rgba(123,29,29,.08);}
.bsnav-cta{display:inline-flex;align-items:center;gap:.45rem;margin-left:.35rem;padding:.55rem 1.05rem;border-radius:9px;background:#4A0E0E;color:#fff;text-decoration:none;font-size:.84rem;font-weight:600;transition:background .15s;}
.bsnav-cta:hover{background:#7B1D1D;}
/* hamburger — hidden on desktop */
.bsnav-burger{display:none;background:#fff;border:1px solid #E8DDD0;border-radius:11px;width:44px;height:44px;align-items:center;justify-content:center;color:#4A0E0E;cursor:pointer;flex-shrink:0;transition:background .15s,border-color .15s;padding:0;}
.bsnav-burger:hover{background:#FBF6EE;border-color:#D8C4A8;}
.bsnav-burger span{position:relative;width:20px;height:15px;display:block;}
.bsnav-burger span i{position:absolute;left:0;width:100%;height:2px;border-radius:2px;background:#4A0E0E;transition:transform .28s cubic-bezier(.4,0,.2,1),opacity .2s;}
.bsnav-burger span i:nth-child(1){top:0;}
.bsnav-burger span i:nth-child(2){top:6.5px;}
.bsnav-burger span i:nth-child(3){top:13px;}
.bsnav-burger.open span i:nth-child(1){transform:translateY(6.5px) rotate(45deg);}
.bsnav-burger.open span i:nth-child(2){opacity:0;}
.bsnav-burger.open span i:nth-child(3){transform:translateY(-6.5px) rotate(-45deg);}
.bsnav-scrim{display:none;}

@media(max-width:640px){
  .bsnav-burger{display:inline-flex;}
  /* links become a dropdown drawer under the bar */
  .bsnav-links{position:absolute;top:100%;left:0;right:0;flex-direction:column;align-items:stretch;gap:.25rem;
    padding:.7rem .9rem 1rem;background:rgba(250,246,239,.98);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);
    border-bottom:1px solid #E8DDD0;box-shadow:0 16px 34px rgba(44,10,10,.16);
    transform:translateY(-10px);opacity:0;visibility:hidden;pointer-events:none;
    transition:opacity .22s ease,transform .24s cubic-bezier(.22,1,.36,1),visibility .22s;}
  .bsnav-links.open{opacity:1;visibility:visible;transform:none;pointer-events:auto;}
  .bsnav-link{display:flex;align-items:center;gap:.65rem;padding:.6rem .85rem;border-radius:10px;font-size:.88rem;min-height:44px;letter-spacing:.1px;}
  .bsnav-link i{width:18px;text-align:center;color:#C9960C;font-size:.8rem;}
  .bsnav-link.active{background:rgba(123,29,29,.1);}
  .bsnav-cta{margin-left:0;margin-top:.45rem;justify-content:center;padding:.65rem 1rem;font-size:.88rem;font-weight:700;min-height:44px;border-radius:10px;}
  /* dim the page behind the open drawer */
  .bsnav-scrim{display:block;position:fixed;inset:0;top:56px;z-index:-1;background:rgba(28,16,8,.28);opacity:0;visibility:hidden;transition:opacity .22s,visibility .22s;}
  .bsnav-scrim.open{opacity:1;visibility:visible;}
}
</style>
<nav class="bsnav">
  <div class="bsnav-in">
    <a class="bsnav-brand" href="index.php">
      <span class="bsnav-seal"><img src="assets/logs.png" alt="BEC logo" width="192" height="192" decoding="async" onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-graduation-cap\' style=\'color:#7B1D1D\'></i>'"></span>
      <span><b>BEC PMO</b><small>Property Management Office</small></span>
    </a>
    <div class="bsnav-links" id="bsnavLinks">
      <a class="bsnav-link <?php echo $nav_active === 'home' ? 'active' : ''; ?>" href="index.php"><i class="fas fa-house"></i> Home</a>
      <a class="bsnav-link <?php echo $nav_active === 'public' ? 'active' : ''; ?>" href="public_reports.php"><i class="fas fa-list-ul"></i> Public Reports</a>
      <a class="bsnav-link <?php echo $nav_active === 'track' ? 'active' : ''; ?>" href="track_report.php"><i class="fas fa-magnifying-glass"></i> Track Report</a>
      <a class="bsnav-cta" href="student_index.php"><i class="fas fa-plus"></i> Report defect</a>
    </div>
    <button class="bsnav-burger" id="bsnavBurger" type="button" aria-label="Menu" aria-expanded="false" aria-controls="bsnavLinks">
      <span><i></i><i></i><i></i></span>
    </button>
  </div>
  <div class="bsnav-scrim" id="bsnavScrim"></div>
</nav>
<script>(function(){
  var n=document.querySelector('.bsnav');if(!n)return;
  var f=function(){n.classList.toggle('scrolled',window.scrollY>12);};f();
  window.addEventListener('scroll',f,{passive:true});
  var b=document.getElementById('bsnavBurger'),l=document.getElementById('bsnavLinks'),s=document.getElementById('bsnavScrim');
  if(!b||!l)return;
  var open=function(v){l.classList.toggle('open',v);b.classList.toggle('open',v);if(s)s.classList.toggle('open',v);b.setAttribute('aria-expanded',v?'true':'false');};
  b.addEventListener('click',function(e){e.stopPropagation();open(!l.classList.contains('open'));});
  if(s)s.addEventListener('click',function(){open(false);});
  l.addEventListener('click',function(e){if(e.target.closest('a'))open(false);});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')open(false);});
  window.addEventListener('resize',function(){if(window.innerWidth>640)open(false);});
})();</script>
