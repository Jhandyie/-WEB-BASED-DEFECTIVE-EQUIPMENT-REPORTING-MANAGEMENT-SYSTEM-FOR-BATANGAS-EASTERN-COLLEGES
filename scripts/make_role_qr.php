<?php
/**
 * make_role_qr.php — one printable A4 page per portal, as separate files.
 *
 * The demo sheet (make_demo_qr.php) points everyone at the landing page and
 * lets them find their own way in. In front of a panel that costs a minute per
 * person, so this gives each audience its own code.
 *
 * A page each rather than one shared sheet, because they are used in different
 * places: the reporter code goes on a wall where equipment breaks, the
 * technician code goes to the maintenance team, the administrator code stays in
 * the office. A whole page also means a code roughly four times the area, which
 * is what lets someone scan it from across a corridor.
 *
 * Self-contained: the QR library, the seal and all styling are embedded, so
 * each page renders and prints on a laptop with no internet.
 *
 * Usage:
 *   php scripts/make_role_qr.php [base-url] [output-directory]
 *
 * Pass the base URL without a trailing path:
 *   php scripts/make_role_qr.php https://becpmo.online
 *
 * Re-run it whenever the address changes — the codes encode it directly, so a
 * printed page outlives the URL it was built from.
 */

$base = rtrim($argv[1] ?? 'https://becpmo.online', '/');
$dir  = rtrim($argv[2] ?? ((getenv('USERPROFILE') ?: getenv('HOME')) . '/OneDrive/Desktop'), '/\\');
$root = dirname(__DIR__);

$lib = @file_get_contents($root . '/assets/qrcode.min.js');
if ($lib === false || $lib === '') {
    fwrite(STDERR, "ERROR: assets/qrcode.min.js is missing — cannot build an offline-safe page.\n");
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

/* Ordered the way a report actually travels: someone reports it, a technician
   repairs it, an administrator oversees it. */
$roles = [
    [
        'file'  => 'BEC-QR-1-Students-and-Faculty.html',
        'name'  => 'Students &amp; Faculty',
        'sub'   => 'Report defective equipment',
        'url'   => $base . '/student_index.php',
        'steps' => [
            'Scan the code above with your phone camera and tap the link.',
            'Enter your official BEC email address and your name.',
            'Type the 6-digit code sent to your email, then describe the problem.',
        ],
        'note'  => 'Open to every student and member of staff. No account is needed — the code sent to your BEC email is what confirms it is you.',
    ],
    [
        'file'  => 'BEC-QR-2-Technicians.html',
        'name'  => 'Technicians',
        'sub'   => 'Receive and complete repair tasks',
        'url'   => $base . '/technician/login.html',
        'steps' => [
            'Scan the code above with your phone camera and tap the link.',
            'Sign in with the account issued to you by the Property Management Office.',
            'Accept your assigned task, then record the repair and upload a photo when done.',
        ],
        'note'  => 'Install this on your phone for quicker access: Android Chrome — menu, then Install app. iPhone Safari — Share, then Add to Home Screen.',
    ],
    [
        'file'  => 'BEC-QR-3-Administrators.html',
        'name'  => 'Administrators',
        'sub'   => 'Review, assign and verify reports',
        'url'   => $base . '/admin/admin_login_otp.html',
        'steps' => [
            'Scan the code above, or open the address on a desktop computer.',
            'Sign in with your administrator account.',
            'Enter the verification code sent to your email to continue.',
        ],
        'note'  => 'The administrator portal is designed for a desktop screen. Reports are shown according to the unit your account belongs to.',
    ],
];

$built = date('F j, Y');
$host  = preg_replace('#^https?://#', '', $base);
$made  = [];

foreach ($roles as $i => $r) {
    $n    = $i + 1;
    $path = preg_replace('#^https?://[^/]+#', '', $r['url']);

    $stepsHtml = '';
    foreach ($r['steps'] as $s) { $stepsHtml .= "        <li>{$s}</li>\n"; }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BEC PMO — {$r['name']} · Portal Access</title>
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
         padding:16mm 18mm 13mm;display:flex;flex-direction:column;
         box-shadow:0 10px 40px rgba(44,10,10,.16);}

  /* letterhead — same language as the printed BEC exports */
  .lh{display:flex;align-items:center;gap:13px;padding-bottom:11px;}
  .lh img{height:58px;width:58px;object-fit:contain;}
  .lh .n{font-family:'Times New Roman',Georgia,serif;font-size:21px;font-weight:800;
         letter-spacing:.2px;line-height:1.1;color:var(--maroon-d);}
  .lh .o{font-family:'Times New Roman',Georgia,serif;font-style:italic;font-size:13.5px;
         color:var(--ink3);margin-top:2px;}
  .rule{height:3px;background:linear-gradient(90deg,var(--maroon-d),var(--maroon) 55%,var(--gold));
        border-radius:2px;}

  .head{text-align:center;padding:15px 0 0;}
  .sys{font-size:11px;font-weight:800;letter-spacing:1.7px;text-transform:uppercase;
       color:var(--ink3);}
  .badge{display:inline-block;margin-top:9px;background:var(--maroon);color:#fff;
         font-size:10px;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;
         padding:5px 15px;border-radius:20px;}
  .head h1{font-size:34px;font-weight:800;color:var(--maroon-d);line-height:1.1;margin-top:11px;}
  .head .sub{font-size:15px;color:var(--ink2);margin-top:5px;}

  .qwrap{display:flex;justify-content:center;margin-top:16px;}
  .qbox{background:#fff;border:2px solid var(--border);border-radius:14px;padding:10px;}
  .qr{width:96mm;height:96mm;display:flex;align-items:center;justify-content:center;}
  .qr img,.qr canvas{width:100%!important;height:100%!important;display:block;}
  .qfail{font-size:13px;color:#B42318;text-align:center;padding:26px;line-height:1.5;}

  .addr{text-align:center;margin-top:13px;}
  .addr .lbl{font-size:9.5px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;
             color:var(--ink3);}
  .addr .u{font-size:15px;font-weight:700;color:var(--maroon);margin-top:3px;
           word-break:break-all;line-height:1.35;}
  .addr .u span{color:var(--ink3);font-weight:600;}

  .steps{margin-top:17px;background:var(--paper);border:1px solid var(--border);
         border-radius:11px;padding:14px 18px;}
  .steps b{font-size:9.5px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;
           color:var(--maroon);display:block;margin-bottom:7px;}
  .steps ol{margin:0;padding-left:19px;}
  .steps li{font-size:13px;color:var(--ink2);line-height:1.75;}

  .note{margin-top:11px;font-size:12px;color:var(--ink3);line-height:1.6;
        border-left:3px solid var(--gold);padding-left:11px;}

  .foot{display:flex;justify-content:space-between;gap:10px;font-size:9.5px;
        color:var(--ink3);margin-top:auto;padding-top:11px;border-top:1px solid var(--border);}

  .bar{max-width:210mm;margin:12px auto 0;display:flex;gap:8px;justify-content:center;}
  .bar button{background:var(--maroon);color:#fff;border:0;padding:9px 20px;border-radius:7px;
              font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;}
  .bar button.sec{background:#6b5b50;}
  .bar button:hover{background:var(--maroon-d);}

  @media print{
    @page{size:A4 portrait;margin:0;}
    body{background:#fff;padding:0;}
    .sheet{width:auto;min-height:auto;margin:0;box-shadow:none;padding:14mm 16mm;}
    .bar{display:none!important;}
    .lh,.rule,.badge,.steps,.note{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
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

    <div class="head">
      <div class="sys">Equipment Reporting &amp; Maintenance System</div>
      <div class="badge">Portal {$n} of 3</div>
      <h1>{$r['name']}</h1>
      <div class="sub">{$r['sub']}</div>
    </div>

    <div class="qwrap"><div class="qbox"><div class="qr" id="qr"></div></div></div>

    <div class="addr">
      <div class="lbl">Or type this address</div>
      <div class="u">{$host}<span>{$path}</span></div>
    </div>

    <div class="steps">
      <b>How to use this</b>
      <ol>
{$stepsHtml}      </ol>
    </div>

    <div class="note">{$r['note']}</div>

    <div class="foot">
      <span>Prepared {$built}</span>
      <span>Batangas Eastern Colleges &middot; Property Management Office</span>
    </div>
  </div>

  <div class="bar">
    <button onclick="window.print()">Print this page</button>
    <button class="sec" onclick="location.reload()">Reload</button>
  </div>

<!-- QR library embedded (no CDN) so this page works with no internet. -->
<script>{$lib}</script>
<script>
(function(){
  // Level H: still scans if the printout is creased, smudged or partly covered.
  var box = document.getElementById('qr');
  try {
    if (!window.QRCode) throw new Error('library missing');
    new QRCode(box, { text: "{$r['url']}", width: 700, height: 700,
      colorDark: '#1C1008', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.H });
  } catch (e) {
    box.innerHTML = '<div class="qfail"><b>QR could not be generated.</b><br>'
      + 'Please type the address printed below.</div>';
  }
})();
</script>
</body>
</html>
HTML;

    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $out = $dir . '/' . $r['file'];
    if (@file_put_contents($out, $html) === false) {
        fwrite(STDERR, "ERROR: could not write $out\n");
        exit(1);
    }
    $made[] = [$r['file'], $r['url'], strlen($html)];
}

printf("Built %d portal pages (A4, one per role)\n  Base: %s\n  Folder: %s\n\n", count($made), $base, $dir);
foreach ($made as [$f, $u, $len]) { printf("  %-36s %-52s %.0f KB\n", $f, $u, $len / 1024); }
echo "\n  Each page is self-contained — printing needs no internet.\n";
