<?php
/**
 * includes/site_hero.php — compact campus photo-hero band (shared).
 * Set $hero_title (and optional $hero_sub, $hero_eyebrow) before requiring.
 * Uses assets/Landing Page Background.jpg if present, else falls back to the campus photo.
 */
$__ht = isset($hero_title) ? $hero_title : 'Batangas Eastern Colleges';
$__hs = isset($hero_sub) ? $hero_sub : '';
$__he = isset($hero_eyebrow) ? $hero_eyebrow : 'Property Management Office';
?>
<style>
.sbhero{position:relative;overflow:hidden;color:#fff;text-align:center;padding:3.4rem 1.5rem 3rem;}
.sbhero::before{content:'';position:absolute;inset:0;z-index:0;
  background:linear-gradient(180deg,rgba(44,10,10,.60),rgba(74,14,14,.85)),
    url('assets/Landing Page Background.jpg') center 42%/cover no-repeat,
    url('assets/bec background (2).png') center/cover no-repeat;
  transform:scale(1.03);}
.sbhero-in{position:relative;z-index:1;max-width:820px;margin:0 auto;}
.sbhero-eyebrow{display:inline-flex;align-items:center;gap:.45rem;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:1.6px;color:#F0C040;margin-bottom:.6rem;}
.sbhero h1{font-family:'Fraunces',serif;font-weight:700;font-size:2.05rem;line-height:1.15;letter-spacing:-.02em;color:#fff;text-shadow:0 2px 20px rgba(0,0,0,.4);margin:0;}
.sbhero p{font-size:.92rem;line-height:1.6;color:rgba(255,255,255,.9);margin:.55rem auto 0;max-width:62ch;text-shadow:0 1px 10px rgba(0,0,0,.35);}
@media(max-width:640px){.sbhero{padding:2.3rem 1.1rem;}.sbhero h1{font-size:1.55rem;}}
</style>
<section class="sbhero">
  <div class="sbhero-in">
    <div class="sbhero-eyebrow"><i class="fas fa-building-shield"></i> <?php echo htmlspecialchars($__he); ?></div>
    <h1><?php echo htmlspecialchars($__ht); ?></h1>
    <?php if ($__hs !== ''): ?><p><?php echo htmlspecialchars($__hs); ?></p><?php endif; ?>
  </div>
</section>
