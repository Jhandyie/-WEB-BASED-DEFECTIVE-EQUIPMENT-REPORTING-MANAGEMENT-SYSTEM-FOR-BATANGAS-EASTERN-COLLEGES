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
.bsfoot-social a:hover{background:#C9960C;color:#2D0505;border-color:#C9960C;transform:none;}
.bsfoot-div{height:1px;background:rgba(255,255,255,.12);margin:1.8rem 0 1.1rem;}
.bsfoot-note{font-size:.72rem;line-height:1.7;color:rgba(255,255,255,.45);text-align:center;}
@media(max-width:900px){.bsfoot-cols{grid-template-columns:1fr 1fr;gap:1.6rem;}}
@media(max-width:640px){
  .bsfoot-cols{grid-template-columns:1fr;gap:1.6rem;}
  /* roomier tap targets for the footer nav links on phones. The padding above
     left these at 39px; 44px is what WCAG 2.5.5 and both platform guidelines
     ask for. Shared footer, so every public page gets it. */
  .bsfoot-col ul{gap:.15rem;}
  .bsfoot-col ul a{display:inline-flex;align-items:center;min-height:44px;padding:.35rem 0;}
  .bsfoot-contact a{display:inline-flex;align-items:center;min-height:44px;}
  .bsfoot-social a{min-width:44px;min-height:44px;}
  /* Clear of the iPhone home indicator. */
  .bsfoot{padding-bottom:max(1.5rem,env(safe-area-inset-bottom));}
}
</style>
<footer class="bsfoot">
  <div class="bsfoot-in">
    <div class="bsfoot-cols">
      <div class="bsfoot-col">
        <div class="bsfoot-brand">
          <span class="bsfoot-seal"><img src="assets/logs.png" alt="BEC logo" width="192" height="192" loading="lazy" decoding="async"></span>
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
          <li><a href="technician/login.html">Technician sign-in</a></li>
          <li><a href="admin/admin_login_otp.html">Admin sign-in (PMO / ITSO)</a></li>
        </ul>
      </div>
      <div class="bsfoot-col">
        <h4>Contact</h4>
        <ul class="bsfoot-contact">
          <li><i aria-hidden="true" class="fas fa-location-dot"></i> 02 Javier Street, Poblacion, San Juan, Batangas 4226</li>
          <li><i aria-hidden="true" class="fas fa-phone"></i> 043-575-3616</li>
          <li><i aria-hidden="true" class="fas fa-envelope"></i> info@bec.edu.ph</li>
          <li><i aria-hidden="true" class="fas fa-globe"></i> <a href="https://bec.edu.ph" target="_blank" rel="noopener">bec.edu.ph</a></li>
        </ul>
        <div class="bsfoot-social">
          <!-- Inline marks rather than icon-font glyphs: these two were the only
               brand icons in the whole system, and loading them from the font
               pulled a 115 KB webfont onto every page that shows this footer.
               Paths from Font Awesome Free (CC BY 4.0), the same source as the
               icon font this replaces. -->
          <a href="https://www.facebook.com/OfficialBEABEC/" target="_blank" rel="noopener" aria-label="Facebook">
            <svg viewBox="0 0 320 512" width="1em" height="1em" fill="currentColor" aria-hidden="true" focusable="false"><path d="M80 299.3V512h116V299.3h86.5l18-97.8H196v-34.6c0-51.7 20.3-71.5 72.7-71.5 16.3 0 29.4 .4 37 1.2V7.9C291.4 4 256.4 0 236.2 0 129.3 0 80 50.5 80 159.4v42.1H14v97.8h66z"/></svg>
          </a>
          <a href="https://www.youtube.com/OfficialBatangasEasternColleges" target="_blank" rel="noopener" aria-label="YouTube">
            <svg viewBox="0 0 576 512" width="1.15em" height="1.15em" fill="currentColor" aria-hidden="true" focusable="false"><path d="M549.7 124.1c-6.3-23.7-24.8-42.3-48.3-48.6C458.8 64 288 64 288 64S117.2 64 74.6 75.5c-23.5 6.3-42 24.9-48.3 48.6-11.4 42.9-11.4 132.3-11.4 132.3s0 89.4 11.4 132.3c6.3 23.7 24.8 41.5 48.3 47.8C117.2 448 288 448 288 448s170.8 0 213.4-11.5c23.5-6.3 42-24.1 48.3-47.8 11.4-42.9 11.4-132.3 11.4-132.3s0-89.4-11.4-132.3zm-317.5 213.5V175.2l142.7 81.2-142.7 81.2z"/></svg>
          </a>
        </div>
      </div>
    </div>
    <div class="bsfoot-div"></div>
    <p class="bsfoot-note">&copy; <?php echo $bsfYear; ?> Batangas Eastern Colleges &middot; Property Management Office. This is an official institutional system — for authorized use by the BEC community.</p>
  </div>
</footer>
