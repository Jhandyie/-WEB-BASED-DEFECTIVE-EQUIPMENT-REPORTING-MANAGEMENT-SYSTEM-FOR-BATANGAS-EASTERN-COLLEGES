<?php
/**
 * make_demo_qr.php — build the standalone, printable demo QR sheet.
 *
 * Produces a SINGLE self-contained .html file: the QR library, the BEC seal and
 * all fonts are embedded, so the sheet renders and prints correctly on a laptop
 * with no internet at all (the previous version pulled qrcode.js from a CDN and
 * Google Fonts, so it showed an empty box whenever the venue Wi-Fi was down).
 *
 * Usage:
 *   php scripts/make_demo_qr.php [public-url] [output-path]
 *
 * Defaults to the permanent ngrok demo URL and writes to the Desktop.
 */

$url = $argv[1] ?? 'https://defuse-grafted-hardcover.ngrok-free.dev/-WEB-BASED/';
$out = $argv[2] ?? (getenv('USERPROFILE') ?: getenv('HOME')) . '/OneDrive/Desktop/BEC-demo-QR.html';

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

$display = preg_replace('#^https?://#', '', rtrim($url, '/')) . '/';
$built   = date('F j, Y');

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BEC PMO — Equipment Reporting System · Demo Access</title>
<style>
  :root{--maroon:#7B1D1D;--maroon-d:#4A0E0E;--gold:#C9960C;--ink:#1C1008;
        --ink2:#5C3838;--ink3:#755B4E;--border:#E8DDD0;--paper:#F8F3EA;}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Segoe UI',Arial,Helvetica,sans-serif;color:var(--ink);
       background:#F1EEE8;display:flex;align-items:center;justify-content:center;
       min-height:100vh;padding:24px;}
  .sheet{background:#fff;border:1px solid var(--border);border-radius:14px;
         max-width:460px;width:100%;overflow:hidden;box-shadow:0 14px 44px rgba(44,10,10,.13);}

  /* official letterhead — same language as the printed BEC exports */
  .lh{display:flex;align-items:center;gap:13px;padding:14px 18px;background:var(--maroon);color:#fff;}
  .lh img{height:42px;width:42px;object-fit:contain;background:#fff;border-radius:6px;padding:3px;}
  .lh .n{font-family:'Times New Roman',Georgia,serif;font-size:16px;font-weight:800;
         letter-spacing:.3px;line-height:1.15;}
  .lh .o{font-family:'Times New Roman',Georgia,serif;font-style:italic;font-size:11.5px;
         color:rgba(255,255,255,.85);margin-top:1px;}
  .accent{height:3px;background:linear-gradient(90deg,var(--maroon-d),var(--maroon) 55%,var(--gold));}

  .title{text-align:center;font-size:11px;font-weight:800;letter-spacing:1.5px;
         text-transform:uppercase;color:var(--maroon);padding:14px 16px 3px;}
  .sub{text-align:center;font-size:11.5px;color:var(--ink3);padding-bottom:14px;}

  .qrwrap{text-align:center;padding:0 18px;}
  #qr{display:inline-flex;align-items:center;justify-content:center;
      padding:12px;border:1px solid var(--border);border-radius:12px;background:#fff;}
  #qr img,#qr canvas{display:block;width:230px;height:230px;}
  .qrfail{font-size:12px;color:#B42318;max-width:230px;line-height:1.5;padding:20px;}

  .lbl{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:1.4px;
       color:var(--ink3);margin:14px 0 4px;text-align:center;}
  .url{font-size:12.5px;font-weight:700;color:var(--maroon-d);word-break:break-all;
       line-height:1.5;text-align:center;padding:0 18px 4px;}

  .steps{margin:14px 18px 0;background:var(--paper);border:1px solid var(--border);
         border-radius:10px;padding:12px 15px;font-size:11.5px;line-height:1.75;color:var(--ink2);}
  .steps .sh{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:1.2px;
             color:var(--maroon);margin-bottom:5px;}
  .steps b{color:var(--ink);}
  .steps ol{margin:0;padding-left:17px;}

  .foot{display:flex;justify-content:space-between;gap:10px;font-size:9px;color:var(--ink3);
        padding:12px 18px;margin-top:14px;background:var(--paper);border-top:1px solid var(--border);}

  .bar{display:flex;gap:8px;justify-content:center;padding:14px 18px 0;}
  .bar button{background:var(--maroon);color:#fff;border:0;padding:9px 20px;border-radius:7px;
              font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;}
  .bar button.sec{background:#6b5b50;}
  .bar button:hover{background:var(--maroon-d);}

  @media print{
    @page{size:A4;margin:16mm;}
    body{background:#fff;padding:0;display:block;}
    .sheet{box-shadow:none;border:none;max-width:100%;}
    .bar{display:none!important;}
    .lh,.accent,.foot,.steps{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
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
    <div class="accent"></div>

    <div class="title">Equipment Reporting &amp; Maintenance System</div>
    <div class="sub">Scan to open the system on your phone</div>

    <div class="qrwrap"><div id="qr"></div></div>

    <div class="lbl">Or type this address</div>
    <div class="url">{$display}</div>

    <div class="steps">
      <div class="sh">How to access</div>
      <ol>
        <li>Point your phone camera at the QR code and tap the link.</li>
        <li>If a page says <b>&ldquo;You are about to visit&hellip;&rdquo;</b>, tap <b>Visit Site</b> (once only).</li>
        <li>Sign in with your <b>BEC email</b> to report equipment, track a ticket, or ask Becca.</li>
      </ol>
    </div>

    <div class="bar">
      <button onclick="window.print()">Print this sheet</button>
      <button class="sec" onclick="location.reload()">Reload</button>
    </div>

    <div class="foot">
      <span>Prepared {$built}</span>
      <span>Live only while the presenter&rsquo;s system is running</span>
    </div>
  </div>

<!-- QR library embedded (no CDN) so this sheet works with no internet. -->
<script>{$lib}</script>
<script>
(function(){
  var target = "{$url}";
  var box = document.getElementById('qr');
  try {
    if (!window.QRCode) throw new Error('library missing');
    // Level H: still scans if the printout is creased or partly covered.
    new QRCode(box, { text: target, width: 230, height: 230,
      colorDark: '#1C1008', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.H });
  } catch (e) {
    box.innerHTML = '<div class="qrfail"><b>QR could not be generated.</b><br>'
      + 'Please type the address below into your phone browser.</div>';
  }
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

printf("Built demo QR sheet\n  URL:  %s\n  File: %s\n  Size: %.1f KB (self-contained — no internet needed)\n",
    $url, $out, strlen($html) / 1024);
