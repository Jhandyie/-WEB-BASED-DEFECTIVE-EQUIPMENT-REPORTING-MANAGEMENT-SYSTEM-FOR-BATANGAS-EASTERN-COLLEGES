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
.bsnav{position:sticky;top:0;z-index:300;background:rgba(248,243,234,.9);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-bottom:1px solid #E8DDD0;font-family:'DM Sans',sans-serif;}
.bsnav-in{max-width:1140px;margin:0 auto;padding:0 1.5rem;height:62px;display:flex;align-items:center;justify-content:space-between;gap:1rem;}
.bsnav-brand{display:flex;align-items:center;gap:.6rem;text-decoration:none;}
.bsnav-seal{width:38px;height:38px;border-radius:50%;background:#fff;flex-shrink:0;display:flex;align-items:center;justify-content:center;overflow:hidden;box-shadow:0 0 0 3px rgba(201,150,12,.28);}
.bsnav-seal img{width:100%;height:100%;object-fit:cover;}
.bsnav-brand b{display:block;font-size:.95rem;font-weight:700;color:#1C1008;letter-spacing:-.01em;line-height:1.1;}
.bsnav-brand small{display:block;font-size:.56rem;text-transform:uppercase;letter-spacing:1.5px;color:#9E8070;}
.bsnav-links{display:flex;align-items:center;gap:.25rem;}
.bsnav-link{padding:.5rem .8rem;border-radius:9px;font-size:.84rem;font-weight:600;color:#5C3838;text-decoration:none;transition:all .15s;}
.bsnav-link:hover,.bsnav-link.active{color:#7B1D1D;background:rgba(123,29,29,.08);}
.bsnav-cta{display:inline-flex;align-items:center;gap:.45rem;margin-left:.35rem;padding:.55rem 1.05rem;border-radius:9px;background:#4A0E0E;color:#fff;text-decoration:none;font-size:.84rem;font-weight:600;transition:background .15s;}
.bsnav-cta:hover{background:#7B1D1D;}
@media(max-width:640px){.bsnav-link{display:none;}.bsnav-in{height:56px;}}
</style>
<nav class="bsnav">
  <div class="bsnav-in">
    <a class="bsnav-brand" href="index.php">
      <span class="bsnav-seal"><img src="assets/logs.png" alt="BEC logo" onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-graduation-cap\' style=\'color:#7B1D1D\'></i>'"></span>
      <span><b>BEC PMO</b><small>Property Management Office</small></span>
    </a>
    <div class="bsnav-links">
      <a class="bsnav-link <?php echo $nav_active === 'home' ? 'active' : ''; ?>" href="index.php">Home</a>
      <a class="bsnav-link <?php echo $nav_active === 'public' ? 'active' : ''; ?>" href="public_reports.php">Public Reports</a>
      <a class="bsnav-link <?php echo $nav_active === 'track' ? 'active' : ''; ?>" href="track_report.php">Track Report</a>
      <a class="bsnav-cta" href="student_index.php"><i class="fas fa-plus"></i> Report defect</a>
    </div>
  </div>
</nav>
