<?php
/**
 * demo_preflight.php — pre-demo health check.
 *
 * Run this BEFORE opening the public tunnel. It verifies the things that have
 * actually broken a demo before: Apache not started, the database unreachable,
 * PHP notices printing into the page, wrong clock, unwritable upload folder.
 *
 * Exit code 0 = safe to demo, 1 = something needs fixing.
 *
 *   php scripts/demo_preflight.php [base-url]
 */

$root = dirname(__DIR__);

/**
 * Where is this install actually served from?
 *
 * On the XAMPP laptop the project sits in a subfolder, so it answers on
 * /bec-pmo. On the Ubuntu host it IS the document root, so it answers on /.
 * The default used to be the laptop's path, which meant that on the server this
 * script reported four failures and "NOT READY" against a site that was serving
 * perfectly — the worst possible message to get an hour before a defense.
 *
 * So ask rather than assume: try the folder path, then the document root, and
 * keep whichever actually answers. An explicit argument still wins, and if
 * neither responds the folder path is kept so the failure is reported against a
 * sensible URL.
 */
function preflightDetectBase(string $root): string {
    $candidates = [
        'http://localhost/' . basename($root),
        'http://localhost',
    ];
    foreach ($candidates as $candidate) {
        [$code] = httpGet(rtrim($candidate, '/') . '/index.php', 8);
        if ($code >= 200 && $code < 400) { return rtrim($candidate, '/'); }
    }
    return rtrim($candidates[0], '/');
}

$baseGiven = isset($argv[1]) && $argv[1] !== '';
$base = $baseGiven ? rtrim($argv[1], '/') : '';

// Load first: this is what pins the timezone and the error-display policy,
// both of which this script then reports on.
require_once $root . '/config/database.php';

$pass = 0; $fail = 0; $warn = 0;
$W = 46;
$ok   = function (string $what, string $detail = '') use (&$pass, $W) { $pass++; printf("  [ OK ]  %-{$W}s %s\n", $what, $detail); };
$bad  = function (string $what, string $detail = '') use (&$fail, $W) { $fail++; printf("  [FAIL]  %-{$W}s %s\n", $what, $detail); };
$note = function (string $what, string $detail = '') use (&$warn, $W) { $warn++; printf("  [WARN]  %-{$W}s %s\n", $what, $detail); };

function httpGet(string $url, int $timeout = 20): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $secs = (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);
    return [$code, $body, $secs];
}

echo "\n";
echo "============================================================\n";
echo "  BEC PMO — Pre-Demo Health Check\n";
echo "  " . date('l, F j, Y \a\t g:i A') . "\n";
echo "============================================================\n\n";

/* ── 1. Web server ─────────────────────────────────────────── */
echo "Web server\n";
if ($base === '') { $base = preflightDetectBase($root); }
echo "  checking ", $base, $baseGiven ? "\n" : "  (detected)\n";

[$code, $body, $secs] = httpGet($base . '/index.php');
if ($code === 200) {
    $ok('Apache is serving the landing page', sprintf('HTTP 200 in %.2fs', $secs));
} elseif ($code === 0) {
    $bad('Apache is NOT responding', 'start XAMPP Apache, then re-run');
} else {
    $bad('Landing page returned HTTP ' . $code, $base . '/index.php');
}

foreach (['student_index.php' => 'Reporter sign-in page',
          'admin/admin_login_otp.html' => 'Admin sign-in page',
          'technician/login.html' => 'Technician sign-in page'] as $path => $label) {
    [$c, , $t] = httpGet($base . '/' . $path);
    $c === 200 ? $ok($label, sprintf('HTTP 200 in %.2fs', $t))
               : $bad($label . ' broken', 'HTTP ' . $c);
}

/* ── 2. No PHP errors leaking into pages ───────────────────── */
echo "\nPage output is clean\n";
// Match the actual SHAPE of PHP error output, not just the words. A bare
// "Notice:" also appears in ordinary page copy — a CSS comment reading
// "Data Privacy Notice: ..." was enough to trip the old pattern and report a
// clean page as broken.
// html_errors=On wraps the level and the line number in <b> tags
// ("<b>Notice</b>:  Undefined variable ... on line <b>42</b>"), so allow tags
// around both. Requiring the "... in <file> on line <n>" tail is what keeps
// ordinary prose containing the word "Notice" or "Warning" from matching.
$errorShapes = '/('
    . '(?:Fatal error|Parse error|Warning|Notice|Deprecated)\s*(?:<\/b>)?\s*:\s*'
    . '.{0,300}?\bin\b.{0,300}?\bon line\s*(?:<b>)?\s*\d+'
    . '|Uncaught\s+\w*(?:Exception|Error)\b'
    . '|SQLSTATE\['
    . ')/s';

$leaky = [];
foreach (['index.php', 'student_index.php'] as $p) {
    [, $b] = httpGet($base . '/' . $p);
    if (preg_match($errorShapes, $b, $m)) {
        $leaky[] = $p . ' (' . trim(substr($m[1], 0, 60)) . ')';
    }
}
$leaky ? $bad('PHP messages visible to visitors', implode(', ', $leaky))
       : $ok('No PHP warnings or errors rendered', 'public pages checked');

/* ── 2b. Every image the demo pages ask for actually exists ──
   An HTTP 200 on the page says nothing about what the page then loads. When
   the background images were re-encoded, both .html sign-in pages kept
   pointing at the deleted file: the pages still returned 200 and this script
   still passed, while the panel would have seen a blank backdrop on two of
   the three portals. Resolve what each page references and confirm it's there. */
echo "\nPage assets resolve\n";
$broken = [];
$checkedPages = 0;
$checkedRefs  = 0;
foreach (['index.php', 'student_index.php',
          'admin/admin_login_otp.html', 'technician/login.html'] as $page) {
    [, $html] = httpGet($base . '/' . $page);
    // A page that did not load tells us nothing about its assets. Skipping it
    // silently would let this check report "all clear" having verified nothing
    // — which is exactly what it did the first time Apache was down.
    if ($html === '') continue;
    $checkedPages++;

    // CSS url(...) plus <img src=> — the references that render. The lookbehind
    // matters: without it, JavaScript's `new URL(a.href, location.href)` also
    // matches `url(` and gets checked as if it were a filename.
    preg_match_all('/(?<![\w.-])url\(\s*[\'"]?([^\'")]+?)[\'"]?\s*\)/i', $html, $m1);
    preg_match_all('/<img\b[^>]*\bsrc\s*=\s*[\'"]([^\'"]+)[\'"]/i', $html, $m2);
    $refs = array_unique(array_merge($m1[1] ?? [], $m2[1] ?? []));

    // '' for root-level pages. dirname() returns a Windows '\' for the root
    // here, which would otherwise be glued onto the front of every path.
    $dir = trim(str_replace('\\', '/', dirname('/' . $page)), '/');
    foreach ($refs as $ref) {
        // Paths written inside JS string literals arrive escaped ("\/assets\/…").
        $ref = str_replace('\\/', '/', trim($ref));
        if ($ref === '' || preg_match('~^(data:|https?:|//|\#)~i', $ref)) continue;

        // Only judge things that are actually files on this server. Anything
        // without a known asset extension is a dynamic or computed value.
        if (!preg_match('/\.(png|jpe?g|gif|webp|svg|ico|woff2?|ttf|otf|eot)(\?|$)/i', $ref)) continue;
        $ref = strtok($ref, '?#');

        // Resolve against the page's own directory, then flatten any '../'.
        $path = $ref[0] === '/' ? ltrim($ref, '/') : ($dir === '' ? $ref : $dir . '/' . $ref);
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') array_pop($parts);
            elseif ($seg !== '' && $seg !== '.') $parts[] = $seg;
        }
        $path = implode('/', $parts);

        $url = $base . '/' . implode('/', array_map('rawurlencode', $parts));
        [$c] = httpGet($url, 10);
        $checkedRefs++;
        if ($c !== 200) $broken[] = $page . ' -> ' . $path . ' (HTTP ' . $c . ')';
    }
}
if ($broken) {
    $bad(count($broken) . ' missing image/asset reference(s)', $broken[0]);
} elseif ($checkedPages === 0) {
    $bad('Could not check page assets', 'no page loaded — see the web server failures above');
} elseif ($checkedRefs === 0) {
    $note('No image references found to check', 'the extraction may have stopped matching');
} else {
    $ok('All referenced images load', "$checkedRefs assets across $checkedPages pages");
}
foreach (array_slice($broken, 1) as $b) printf("          %-{$W}s %s\n", '', $b);

/* ── 3. Database ───────────────────────────────────────────── */
echo "\nDatabase\n";
try {
    $t0 = microtime(true);
    $pdo = getPgsqlPdoConnection();
    $pdo->query('SELECT 1')->fetchColumn();
    $ms = (microtime(true) - $t0) * 1000;
    $ms < 1500 ? $ok('Database reachable', sprintf('%.0f ms to connect', $ms))
               : $note('Database reachable but slow', sprintf('%.0f ms — check the internet link', $ms));

    $n = (int)$pdo->query('SELECT COUNT(*) FROM public.defect_reports')->fetchColumn();
    $u = (int)$pdo->query('SELECT COUNT(*) FROM public.users')->fetchColumn();
    $e = (int)$pdo->query('SELECT COUNT(*) FROM public.equipment')->fetchColumn();
    $ok('Demo data present', "$n reports · $u users · $e equipment");
    if ($n === 0 || $e === 0) $note('Little or no demo data', 'the panel will see empty tables');

    $skew = abs(strtotime((string)$pdo->query('SELECT now()')->fetchColumn()) - time());
    $skew <= 120 ? $ok('App clock matches the database', $skew . 's difference')
                 : $bad('Clock mismatch: dates will print wrong', $skew . 's difference');
} catch (Throwable $ex) {
    $bad('Database unreachable', substr($ex->getMessage(), 0, 60));
}

/* ── 4. Configuration ──────────────────────────────────────── */
echo "\nConfiguration\n";
$tz = date_default_timezone_get();
$tz === 'Asia/Manila' ? $ok('Timezone', $tz)
                      : $bad('Timezone is ' . $tz, 'should be Asia/Manila — printed dates will be wrong');

$dbg = strtolower((string)(getenv('APP_DEBUG') ?: 'false'));
$dbg === 'true' ? $note('APP_DEBUG is true', 'errors will show on screen — set false for the demo')
                : $ok('APP_DEBUG off', 'errors are logged, not displayed');

/* ── 5. Files the demo writes to ───────────────────────────── */
echo "\nWritable folders\n";
foreach (['uploads/reports' => 'Report photos',
          'uploads/completed_work' => 'Completion photos',
          'data' => 'Rate-limit / cache store',
          'logs' => 'Error log'] as $rel => $label) {
    $p = $root . '/' . $rel;
    if (!is_dir($p)) { @mkdir($p, 0775, true); }
    is_dir($p) && is_writable($p) ? $ok($label, $rel . '/')
                                  : $bad($label . ' not writable', $rel . '/');
}

/* ── 6. Offline-safe demo assets ───────────────────────────── */
echo "\nOffline safety\n";
is_file($root . '/assets/qrcode.min.js')
    ? $ok('QR library bundled locally', 'equipment QR works with no internet')
    : $bad('assets/qrcode.min.js missing', 'QR codes will not generate offline');

/* ── Verdict ───────────────────────────────────────────────── */
echo "\n============================================================\n";
printf("  %d passed", $pass);
if ($warn) printf(" · %d warning(s)", $warn);
if ($fail) printf(" · %d FAILURE(S)", $fail);
echo "\n";
echo $fail === 0
    ? "  READY TO DEMO" . ($warn ? " (review the warnings above)" : " — all clear")
    : "  NOT READY — fix the failures above before presenting";
echo "\n============================================================\n\n";

exit($fail === 0 ? 0 : 1);
