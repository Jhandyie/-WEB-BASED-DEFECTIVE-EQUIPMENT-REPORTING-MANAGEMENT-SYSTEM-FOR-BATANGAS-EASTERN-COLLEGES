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

$base = rtrim($argv[1] ?? 'http://localhost/-WEB-BASED', '/');
$root = dirname(__DIR__);

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
