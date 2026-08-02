<?php
/**
 * Download the exact woff2 files Google Fonts serves for the families and
 * weights this project uses, and emit a local @font-face stylesheet.
 * Run once; the result is committed so the app never depends on the CDN.
 */
$root = 'C:/xampp/htdocs/-WEB-BASED';
$dir  = $root . '/assets/vendor/fonts';
if (!is_dir($dir)) mkdir($dir, 0775, true);

// A modern desktop UA makes Google return woff2 (latin + latin-ext only).
$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

// Every weight and style any page actually asks for. The admin screens request
// DM Sans italic and Outfit 400/500, which the first build left out — so those
// pages fell back to a synthesised face once they stopped using the CDN.
$families = [
    'DM+Sans'  => 'ital,wght@0,400;0,500;0,600;0,700;1,400',
    // Landing and reporter pages also use Fraunces italic and regular.
    'Fraunces' => 'ital,wght@0,400;0,600;0,700;1,400',
    'Outfit'   => 'wght@400;500;600;700;800;900',
];

function fetch(string $url, string $ua): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_USERAGENT=>$ua,
        CURLOPT_TIMEOUT=>90, CURLOPT_FOLLOWLOCATION=>true]);
    $b = (string)curl_exec($ch); curl_close($ch);
    return $b;
}

$css = "/* Self-hosted webfonts — generated, do not edit by hand.\n"
     . "   The sign-in and workspace screens used to load these from Google Fonts;\n"
     . "   with no venue internet every heading fell back to a generic sans.\n"
     . "   Rebuild with scratchpad/getfonts.php if the families ever change. */\n\n";

$total = 0;
foreach ($families as $fam => $axis) {
    $url = "https://fonts.googleapis.com/css2?family={$fam}:{$axis}&display=swap";
    $src = fetch($url, $UA);
    if ($src === '') { echo "FAILED to fetch CSS for {$fam}\n"; continue; }

    // Each @font-face block: capture family, weight, unicode-range and the woff2 url.
    preg_match_all('/@font-face\s*\{([^}]*)\}/s', $src, $blocks);
    $n = 0;
    foreach ($blocks[1] as $b) {
        if (!preg_match('/src:\s*url\((https:[^)]+\.woff2)\)/', $b, $u)) continue;
        preg_match('/font-family:\s*\'([^\']+)\'/', $b, $f);
        preg_match('/font-weight:\s*(\d+)/', $b, $w);
        preg_match('/font-style:\s*(\w+)/', $b, $s);
        preg_match('/unicode-range:\s*([^;]+);/', $b, $r);

        $family = $f[1] ?? $fam;
        $weight = $w[1] ?? '400';
        $style  = $s[1] ?? 'normal';
        $slug   = strtolower(preg_replace('/[^a-z0-9]+/i','-',$family)) . "-{$weight}-{$style}-" . substr(md5($u[1]),0,6);
        $file   = "{$slug}.woff2";

        $bin = fetch($u[1], $UA);
        if ($bin === '' || strlen($bin) < 500) { echo "  skip {$family} {$weight} (empty)\n"; continue; }
        file_put_contents($dir . '/' . $file, $bin);
        $total += strlen($bin);
        $n++;

        $css .= "@font-face{font-family:'{$family}';font-style:{$style};font-weight:{$weight};font-display:swap;"
              . "src:url('{$file}') format('woff2');"
              . (isset($r[1]) ? "unicode-range:" . trim($r[1]) . ";" : "")
              . "}\n";
    }
    printf("  %-10s %d face file(s)\n", $fam, $n);
}

file_put_contents($dir . '/fonts.css', $css);
printf("\nwrote %s/fonts.css  (%d font files, %.0f KB total)\n", $dir, count(glob($dir.'/*.woff2')), $total/1024);
