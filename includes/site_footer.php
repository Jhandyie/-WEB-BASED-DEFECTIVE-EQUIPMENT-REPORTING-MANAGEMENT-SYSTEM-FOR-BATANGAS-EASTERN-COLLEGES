<?php
/**
 * includes/site_footer.php — shared public footer (self-contained).
 * Scoped .bsfoot-* classes + literal colours. Carries verified BEC
 * institutional details (motto, Est. 1940, address, phone, email, socials).
 * Requires Font Awesome + Fraunces/DM Sans (already loaded by public pages).
 */
$bsfYear = date('Y');
?>
<style>
.bsfoot{background:#2D0505;color:rgba(255,255,255,.62);margin-top:3rem;font-family:'DM Sans',sans-serif;position:relative;z-index:1;}
.bsfoot-in{max-width:1140px;margin:0 auto;padding:2.8rem 1.5rem 1.8rem;}
.bsfoot-cols{display:grid;grid-template-columns:1.5fr 1fr 1.2fr;gap:2rem;}
.bsfoot-brand{display:flex;align-items:flex-start;gap:.7rem;margin-bottom:1rem;}
.bsfoot-seal{width:40px;height:40px;border-radius:50%;background:#fff;flex-shrink:0;display:flex;align-items:center;justify-content:center;overflow:hidden;box-shadow:0 0 0 3px rgba(201,150,12,.35);}
.bsfoot-seal img{width:100%;height:100%;object-fit:cover;}
.bsfoot-brand b{color:#fff;font-size:1.05rem;font-weight:700;}
.bsfoot-brand small{font-size:.6rem;text-transform:uppercase;letter-spacing:1.6px;color:rgba(255,255,255,.5);}
.bsfoot-motto{font-family:'Fraunces',serif;font-style:italic;font-size:.92rem;color:rgba(255,255,255,.72);line-height:1.55;max-width:36ch;}
.bsfoot-motto .est{display:block;font-family:'DM Sans',sans-serif;font-style:normal;font-size:.64rem;text-transform:uppercase;letter-spacing:1.6px;color:#C9960C;margin-top:.6rem;}
.bsfoot-col h4{font-size:.66rem;text-transform:uppercase;letter-spacing:1.4px;color:rgba(255,255,255,.55);margin-bottom:.9rem;font-weight:700;}
.bsfoot-col ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.62rem;}
.bsfoot-col a{font-size:.85rem;color:rgba(255,255,255,.78);text-decoration:none;transition:color .15s;}
.bsfoot-col a:hover{color:#C9960C;}
.bsfoot-contact{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.62rem;}
.bsfoot-contact li{display:flex;align-items:flex-start;gap:.55rem;font-size:.83rem;line-height:1.5;color:rgba(255,255,255,.78);}
.bsfoot-contact i{color:#C9960C;font-size:.82rem;margin-top:.18rem;flex-shrink:0;}
.bsfoot-social{display:flex;gap:.55rem;margin-top:1.05rem;}
.bsfoot-social a{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);color:rgba(255,255,255,.82);font-size:.95rem;text-decoration:none;transition:all .15s;}
.bsfoot-social a:hover{background:#C9960C;color:#2D0505;border-color:#C9960C;transform:translateY(-2px);}
.bsfoot-div{height:1px;background:rgba(255,255,255,.12);margin:1.8rem 0 1.1rem;}
.bsfoot-note{font-size:.72rem;line-height:1.7;color:rgba(255,255,255,.45);text-align:center;}
@media(max-width:900px){.bsfoot-cols{grid-template-columns:1fr 1fr;gap:1.6rem;}}
@media(max-width:640px){.bsfoot-cols{grid-template-columns:1fr;gap:1.6rem;}}
</style>
<footer class="bsfoot">
  <div class="bsfoot-in">
    <div class="bsfoot-cols">
      <div class="bsfoot-col">
        <div class="bsfoot-brand">
          <span class="bsfoot-seal"><img src="assets/logs.png" alt="BEC logo"></span>
          <span><b>BEC PMO</b><br><small>Defective Equipment Reporting System</small></span>
        </div>
        <p class="bsfoot-motto">&ldquo;Beacons of Education, Molders of Educators.&rdquo;
          <span class="est">Batangas Eastern Colleges &middot; Est. 1940</span>
        </p>
      </div>
      <div class="bsfoot-col">
        <h4>System</h4>
        <ul>
          <li><a href="student_index.php">Report equipment</a></li>
          <li><a href="track_report.php">Track a report</a></li>
          <li><a href="public_reports.php">Public reports</a></li>
        </ul>
      </div>
      <div class="bsfoot-col">
        <h4>Contact</h4>
        <ul class="bsfoot-contact">
          <li><i class="fas fa-location-dot"></i> 02 Javier Street, Poblacion, San Juan, Batangas 4226</li>
          <li><i class="fas fa-phone"></i> 043-575-3616</li>
          <li><i class="fas fa-envelope"></i> info@bec.edu.ph</li>
          <li><i class="fas fa-globe"></i> <a href="https://bec.edu.ph" target="_blank" rel="noopener">bec.edu.ph</a></li>
        </ul>
        <div class="bsfoot-social">
          <a href="https://www.facebook.com/OfficialBEABEC/" target="_blank" rel="noopener" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
          <a href="https://www.youtube.com/OfficialBatangasEasternColleges" target="_blank" rel="noopener" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
        </div>
      </div>
    </div>
    <div class="bsfoot-div"></div>
    <p class="bsfoot-note">&copy; <?php echo $bsfYear; ?> Batangas Eastern Colleges &middot; Property Management Office. This is an official institutional system — for authorized use by the BEC community.</p>
  </div>
</footer>
