<?php
/**
 * make_role_qr.php — one printable A4 sheet with a QR code per portal.
 *
 * The demo sheet (make_demo_qr.php) points everyone at the landing page and
 * lets them find their own way in. In front of a panel that costs a minute per
 * person, so this gives each audience its own code: reporters, technicians and
 * administrators each scan once and land on their own sign-in screen.
 *
 * Self-contained like the demo sheet: the QR library, the seal and all styling
 * are embedded, so it renders and prints on a laptop with no internet.
 *
 * Usage:
 *   php scripts/make_role_qr.php [base-url] [output-path]
 *
 * Pass the base URL without a trailing path:
 *   php scripts/make_role_qr.php https://becpmo.online
 *
 * Re-run it whenever the address changes — the codes encode it directly, so a
 * printed sheet outlives the URL it was built from.
 */

$base = rtrim($argv[1] ?? 'http://187.52.115.45', '/');
$out  = $argv[2] ?? (getenv('USERPROFILE') ?: getenv('HOME')) . '/OneDrive/Desktop/BEC-role-QR.html';
$root = dirname(__DIR__);

$lib = @file_get_contents($root . '/assets/qrcode.min.js');
if ($lib === false || $lib === '') {
    fwrite(STDERR, "ERROR: assets/qrcode.min.js is missing — cannot build an offline-safe sheet.\n");
    exit(1);
}

$sealData = '';
foreach (['pwa-icon-192.png', 'logs.png', 'logo.png'] as $n) {
    $p = $root . '/assets/' . $n;
    if (is_file($p)) {
        $raw = @file_get_contents($p);
        if ($raw !== false && $raw !== '') { $sealData = 'data:image/png;base64,' . base64_encode($raw); break; }
    }
}

/* Each portal, in the order a report actually travels: someone reports it, a
   technician repairs it, an administrator oversees it. */
$roles = [
    [
        'key'   => 'reporter',
        'name'  => 'Students &amp; Faculty',
        'sub'   => 'Report defective equipment',
        'url'   => $base . '/student_index.php',
        'steps' => 'Sign in with your BEC email address. A 6-digit code is sent to confirm it is you.',
    ],
    [
        'key'   => 'technician',
        'name'  => 'Technicians',
        'sub'   => 'Receive and complete repair tasks',
        'url'   => $base . '/technician/login.html',
        'steps' => 'Sign in with the account issued by the PMO. On a phone you can install this as an app.',
    ],
    [
        'key'   => 'admin',
        'name'  => 'Administrators',
        'sub'   => 'Review, assign and verify reports',
        'url'   => $base . '/admin/admin_login_otp.html',
        'steps' => 'Sign in with your administrator account, then enter the code sent to your email.',
    ],
];

$built   = date('F j, Y');
$display = preg_replace('#^https?://#', '', $base);

$cards = '';
foreach ($roles as $i => $r) {
    $n = $i + 1;
    $cards .= <<<CARD
    <section class="role">
      <div class="qbox"><div class="qr" id="qr{$r['key']}"></div></div>
      <div class="meta">
        <div class="rnum">Portal {$n}</div>
        <h2>{$r['name']}</h2>
        <p class="rsub">{$r['sub']}</p>
        <p class="rstep">{$r['steps']}</p>
        <div class="rurl">{$display}<span class="rpath">{$r['url']}</span></div>
      </div>
    </section>

CARD;
    // The path shown under the host, without repeating the host itself.
    $cards = str_replace('<span class="rpath">' . $r['url'] . '</span>',
                         '<span class="rpath">' . preg_replace('#^https?://[^/]+#', '', $r['url']) . '</span>',
                         $cards);
}

$targets = [];
foreach ($roles as $r) { $targets[] = "['qr{$r['key']}'," . json_encode($r['url']) . "]"; }
$targetsJs = '[' . implode(',', $targets) . ']';

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BEC PMO — Equipment Reporting System · Portal Access</title>
<style>
  :root{
    --maroon:#7B1D1D; --maroon-d:#4A0E0E; --gold:#C9960C;
    --ink:#1C1008; --ink2:#5C3838; --ink3:#7A6255;
    --border:#E6DACB; --paper:#FBF7F0;
  }
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Segoe UI',Arial,Helvetica,sans-serif;color:var(--ink);
       background:#EDE9E2;padding:16px;}

  /* A4 portrait. Fixed width on screen so what you see is what prints. */
  .sheet{width:210mm;min-height:297mm;margin:0 auto;background:#fff;
         padding:14mm 15mm 12mm;display:flex;flex-direction:column;
         box-shadow:0 10px 40px rgba(44,10,10,.16);}

  /* letterhead — same language as the printed BEC exports */
  .lh{display:flex;align-items:center;gap:12px;padding-bottom:10px;}
  .lh img{height:52px;width:52px;object-fit:contain;}
  .lh .n{font-family:'Times New Roman',Georgia,serif;font-size:19px;font-weight:800;
         letter-spacing:.2px;line-height:1.1;color:var(--maroon-d);}
  .lh .o{font-family:'Times New Roman',Georgia,serif;font-style:italic;font-size:12.5px;
         color:var(--ink3);margin-top:2px;}
  .rule{height:3px;background:linear-gradient(90deg,var(--maroon-d),var(--maroon) 55%,var(--gold));
        border-radius:2px;}

  .title{text-align:center;padding:13px 0 3px;}
  .title h1{font-size:15px;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;
            color:var(--maroon);}
  .title p{font-size:12px;color:var(--ink3);margin-top:3px;}

  /* The three cards share whatever height is left after the header and the
     footer, so the sheet fills an A4 page instead of trailing off with a gap
     above the footer. Bigger codes are easier to scan, which is the point. */
  .roles{display:flex;flex-direction:column;gap:11px;margin-top:12px;flex:1;}

  .role{display:flex;align-items:center;gap:15px;border:1px solid var(--border);
        border-radius:10px;padding:14px;background:var(--paper);flex:1;
        page-break-inside:avoid;break-inside:avoid;}
  .qbox{background:#fff;border:1px solid var(--border);border-radius:8px;padding:7px;flex-shrink:0;}
  .qr{width:47mm;height:47mm;display:flex;align-items:center;justify-content:center;}
  .qr img,.qr canvas{width:100%!important;height:100%!important;display:block;}
  .qfail{font-size:10px;color:#B42318;text-align:center;padding:8px;line-height:1.4;}

  .meta{min-width:0;}
  .rnum{font-size:9px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;
        color:var(--gold);margin-bottom:2px;}
  .meta h2{font-size:17px;font-weight:800;color:var(--maroon-d);line-height:1.15;}
  .rsub{font-size:12px;color:var(--ink2);margin-top:1px;}
  .rstep{font-size:11px;color:var(--ink3);line-height:1.5;margin-top:6px;max-width:92mm;}
  .rurl{font-size:10.5px;font-weight:700;color:var(--maroon);margin-top:7px;
        word-break:break-all;line-height:1.35;}
  .rpath{color:var(--ink3);font-weight:600;}

  .howto{margin-top:10px;border:1px solid var(--border);border-radius:9px;
         padding:9px 13px;background:#fff;}
  .howto b{font-size:9px;font-weight:800;letter-spacing:1.3px;text-transform:uppercase;
           color:var(--maroon);display:block;margin-bottom:3px;}
  .howto p{font-size:10.5px;color:var(--ink2);line-height:1.55;}

  .foot{display:flex;justify-content:space-between;gap:10px;font-size:9px;
        color:var(--ink3);margin-top:10px;padding-top:7px;border-top:1px solid var(--border);}

  .bar{max-width:210mm;margin:12px auto 0;display:flex;gap:8px;justify-content:center;}
  .bar button{background:var(--maroon);color:#fff;border:0;padding:9px 20px;border-radius:7px;
              font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;}
  .bar button.sec{background:#6b5b50;}
  .bar button:hover{background:var(--maroon-d);}

  @media print{
    @page{size:A4 portrait;margin:0;}
    body{background:#fff;padding:0;}
    .sheet{width:auto;min-height:auto;margin:0;box-shadow:none;padding:12mm 14mm;}
    .bar{display:none!important;}
    .lh,.rule,.role,.howto,.rnum{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  }
</style>
</head>
<body>
  <div class="sheet">
    <div class="lh">
      <img src="{$sealData}" alt="" onerror="this.style.display='none'">
      <div>
        <div class="n">BATANGAS EASTERN COLLEGES</div>
        <div class="o">Property Management Office</div>
      </div>
    </div>
    <div class="rule"></div>

    <div class="title">
      <h1>Equipment Reporting &amp; Maintenance System</h1>
      <p>Scan the code for your role to open the system on your phone</p>
    </div>

    <div class="roles">
{$cards}    </div>

    <div class="howto">
      <b>How to scan</b>
      <p>Point your phone camera at the code for your role and tap the link that appears.
      A page saying &ldquo;You are about to visit&hellip;&rdquo; is normal the first time &mdash; tap
      <strong>Visit Site</strong> to continue. You can also type the address printed beneath
      each code.</p>
    </div>

    <div class="foot">
      <span>Prepared {$built}</span>
      <span>Batangas Eastern Colleges &middot; Property Management Office</span>
    </div>
  </div>

  <div class="bar">
    <button onclick="window.print()">Print this sheet</button>
    <button class="sec" onclick="location.reload()">Reload</button>
  </div>

<!-- QR library embedded (no CDN) so this sheet works with no internet. -->
<script>{$lib}</script>
<script>
(function(){
  // Level H: still scans if the printout is creased, smudged or partly covered.
  var targets = {$targetsJs};
  targets.forEach(function(t){
    var box = document.getElementById(t[0]);
    if (!box) return;
    try {
      if (!window.QRCode) throw new Error('library missing');
      new QRCode(box, { text: t[1], width: 320, height: 320,
        colorDark: '#1C1008', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.H });
    } catch (e) {
      box.innerHTML = '<div class="qfail"><b>QR unavailable.</b><br>Type the address below.</div>';
    }
  });
})();
</script>
</body>
</html>
HTML;

$dir = dirname($out);
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
if (@file_put_contents($out, $html) === false) {
    fwrite(STDERR, "ERROR: could not write $out\n");
    exit(1);
}

printf("Built role QR sheet\n  Base: %s\n  File: %s\n  Size: %.1f KB (self-contained — no internet needed)\n",
    $base, $out, strlen($html) / 1024);
foreach ($roles as $r) { printf("    %-12s %s\n", $r['key'], $r['url']); }
