<?php
/**
 * ui_smoke.php — does the interface actually work in a browser?
 *
 *   c:\xampp\php\php.exe scripts\ui_smoke.php          (needs Apache up)
 *   c:\xampp\php\php.exe scripts\ui_smoke.php --keep   (leave the dumps for inspection)
 *
 * e2e_smoke.php posts over raw HTTP and never executes a line of JavaScript, so
 * it stays green while every button on the page is dead — which has shipped
 * twice (see CLAUDE.md: CSS inside a <script> block throws a SyntaxError and
 * takes every function in that block with it). This is the other half: load the
 * real pages in headless Edge, let their scripts run, and assert against the
 * DOM the browser ended up with.
 *
 * What it checks, per page:
 *   • the page renders at all, and no PHP warning/fatal leaked into the HTML
 *   • the scripts ran — proven by DOM the browser produced, not markup PHP
 *     printed (a CSRF token input injected by csrf_inject.php, a date field
 *     wrapped by date_picker.js, a recurrence preview filled in by the page)
 *   • the landmarks that page is for are present
 *
 * Signed-in pages need a session. Rather than leaving a _diag_*.php seeder on
 * the web root — a file that hands anyone an admin session — this writes one,
 * uses it, and deletes it in a finally block even if a check explodes.
 *
 * Exit code 0 = everything passed, 1 = something failed. Safe to run before a
 * demo, and it changes no data.
 */

$root    = dirname(__DIR__);
$base    = getenv('BEC_SMOKE_BASE') ?: 'http://localhost/bec-pmo';
$keep    = in_array('--keep', $argv, true);
$tmpDir  = sys_get_temp_dir() . '/bec_ui_smoke';
$seeder  = $root . '/_ui_smoke_session.php';   // deleted in the finally below
@mkdir($tmpDir, 0777, true);

/** Locate headless Edge, then Chrome — whichever this machine has. */
function findBrowser(): ?string {
    foreach ([
        'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
    ] as $p) { if (is_file($p)) return $p; }
    return null;
}

$browser = findBrowser();
if ($browser === null) {
    fwrite(STDERR, "No headless browser found (looked for Edge and Chrome).\n");
    exit(1);
}

/**
 * Render a URL with scripts executed and return the resulting DOM.
 *
 * Headless Edge occasionally returns nothing at all for a page it serves fine
 * on the next attempt — an empty dump is not evidence the page is broken, so it
 * is retried once before being believed. (Reporting a working sign-in page as
 * failed is worse than a slow run: a suite that cries wolf gets ignored.)
 */
function renderDom(string $browser, string $url, string $profileDir): string {
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $cmd = '"' . $browser . '"'
             . ' --headless=new --disable-gpu --no-sandbox --no-first-run'
             . ' --user-data-dir=' . escapeshellarg($profileDir . '_' . $attempt)
             . ' --virtual-time-budget=20000 --dump-dom ' . escapeshellarg($url)
             . ' 2>NUL';
        $dom = (string) shell_exec($cmd);
        if (trim($dom) !== '') { return $dom; }
    }
    return '';
}

/* The seeder. Named _ui_smoke_session.php, not _diag_*.php, so it is obvious in
   a directory listing what created it and that it is not part of the app. */
file_put_contents($seeder, <<<'PHP'
<?php
/* TEMPORARY — written and deleted by scripts/ui_smoke.php. If you are reading
   this in a deployed tree, the script died before cleaning up: delete it now. */
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/config/database.php';
$role = $_GET['role'] ?? 'admin';
startRoleSession($role === 'tech' ? 'technician' : 'admin');
$pdo = getPgsqlPdoConnection();
$want = $role === 'tech' ? 'technician' : 'admin';
$st = $pdo->prepare("SELECT user_id, fullname, email, department FROM public.users
                      WHERE role = :r AND status = 'active' ORDER BY user_id LIMIT 1");
$st->execute(['r' => $want]);
$u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$_SESSION['user_id']    = $u['user_id']  ?? '';
$_SESSION['fullname']   = $u['fullname'] ?? 'Smoke Test';
$_SESSION['user_email'] = $u['email']    ?? '';
$_SESSION['department'] = $u['department'] ?? '';
$_SESSION['role']       = $want;
$_SESSION['logged_in']  = true;
header('Location: ' . ($_GET['to'] ?? 'admin_dashboard.php'));
exit;
PHP);

/**
 * Fetch a page over plain HTTP, with no session and no browser.
 *
 * Used for the sign-in screens. Two reasons, and both matter: they must be
 * tested *signed out*, which is the state a visitor is actually in, and headless
 * Edge returns an empty document for admin/admin_login_otp.html every time while
 * curl and a real browser serve it fine — an engine quirk, not a fault in the
 * page. What is asserted there is server-rendered markup, so HTTP sees exactly
 * what the browser would.
 */
function fetchPage(string $url): string {
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    return (string) @file_get_contents($url, false, $ctx);
}

/* Each check: [label, role, page, [assertions]].
   role 'none' means "fetch it signed out over HTTP"; any other role signs in
   first and renders it in the browser so its scripts run.
   An assertion is [name, regex, shouldMatch]. Keep them about behaviour the
   browser produced, not text PHP printed — that is what e2e_smoke already sees. */
$checks = [
    ['Landing', 'admin', 'index.php', [
        ['renders',                  '/<\/html>/i',                 true],
        ['reserve-a-venue entry',    '/reserve_venue\.php/',        true],
        ['no CDN dependency',        '/cdnjs|jsdelivr|fonts\.googleapis/', false],
    ]],
    ['Venue reservation form', 'admin', 'reserve_venue.php', [
        ['venue list populated',     '/<datalist id="venueList">\s*<option/', true],
        ['CSRF token injected',      '/name="csrf_token" value="[a-f0-9]{32,}"/', true],
        ['date picker enhanced',     '/pdp-field/',                 true],
        ['materials row present',    '/material_item/',             true],
    ]],
    ['Preventive maintenance', 'admin', 'admin_preventive.php', [
        ['recurrence preview ran',   '/id="cPreview"[^>]*>\s*<i/',  true],
        ['equipment combobox',       '/id="eqList"/',               true],
        ['CSRF token injected',      '/name="csrf_token"/',         true],
    ]],
    ['Venue reservations queue', 'admin', 'admin_reservations.php', [
        ['filter bar',               '/id="fq"/',                   true],
        ['detail dialog',            '/id="rvOvl"/',                true],
    ]],
    ['Backup & recovery', 'admin', 'admin_backup.php', [
        ['import form',              '/type="file" name="archive"/', true],
        ['actions row',              '/class="actions"/',           true],
        ['no stray back button',     '/class="back"/',              false],
    ]],
    ['Defect reports', 'admin', 'admin_defect_reports.php', [
        ['origin filter',            '/id="fsk"/',                  true],
        ['filter bar',               '/id="fsq"/',                  true],
    ]],
    ['Assign technicians', 'admin', 'admin_assign_technicians.php', [
        ['queue row is clickable',   '/class="pick-row"/',          true],
        ['dispatch drawer',          '/id="asgDw"/',                true],
    ]],
    ['Technician dashboard', 'tech', 'technician_dashboard.php', [
        ['renders',                  '/<\/html>/i',                 true],
        ['CSRF token injected',      '/name="csrf_token"/',         true],
    ]],

    /* The public surfaces matter most of all: they are the ones a stranger
       reaches without a login, so a script error there is visible to everyone
       and reported by no one.

       issue_report.php is deliberately absent from this list. It is a JSON
       endpoint, not a page — it answers GET with 405 — so loading it in a
       browser proves nothing. Its protections (per-IP rate limiting, uploads
       validated by content) are the sort a POST test would have to exercise. */
    ['Track a report (public)', 'admin', 'track_report.php', [
        ['renders',                  '/<\/html>/i',                 true],
        ['tracking form present',    '/<form|<input/',              true],
    ]],
    ['Public reports list', 'admin', 'public_reports.php', [
        ['renders',                  '/<\/html>/i',                 true],
        ['no CDN dependency',        '/cdnjs|jsdelivr|fonts\.googleapis/', false],
    ]],
    ['Reporter sign-in', 'admin', 'student_index.php', [
        ['renders',                  '/<\/html>/i',                 true],
    ]],
    ['Admin sign-in (signed out)', 'none', 'admin/admin_login_otp.html', [
        ['renders',                  '/<\/html>/i',                 true],
        ['no dead remember-me box',  '/rememberMe/',                false],
        ['forgot-password offered',  '/forgotPassword\(\)/',        true],
    ]],
    ['Technician sign-in (signed out)', 'none', 'technician/login.html', [
        ['renders',                  '/<\/html>/i',                 true],
        ['no dead remember-me box',  '/rememberMe/',                false],
        ['forgot-password offered',  '/forgotPassword\(\)/',        true],
    ]],
];

$pass = 0; $fail = 0; $lines = [];

try {
    echo str_repeat('=', 62), "\n  UI SMOKE — ", $base, "\n", str_repeat('=', 62), "\n\n";

    foreach ($checks as [$label, $role, $page, $assertions]) {
        if ($role === 'none') {
            $dom = fetchPage($base . '/' . $page);
        } else {
            $url = $base . '/_ui_smoke_session.php?role=' . $role . '&to=' . rawurlencode($page);
            $dom = renderDom($browser, $url, $tmpDir . '/p' . md5($page));
        }

        if ($keep) { file_put_contents($tmpDir . '/' . preg_replace('/\W+/', '_', $page) . '.html', $dom); }

        echo "  ", $label, "\n";
        if (trim($dom) === '') {
            echo "    [FAIL] page rendered nothing (is Apache up?)\n";
            $fail++; $lines[] = "$label: empty render";
            continue;
        }
        /* A leaked warning means the page "works" but is printing its guts at a
           panel — worth failing on, not just noting.

           The pattern has to be the *shape* of a PHP error, not the word: a bare
           /Notice:/ flagged the reporter sign-in page, where the match was its
           data-privacy copy ("Notice: short consent line..."). Real PHP output
           always carries the file and line, either as "... on line 12" or, with
           html_errors on, as "<b>Warning</b>:". */
        $assertions[] = ['no PHP error leaked',
            '/(?:<b>\s*(?:Fatal error|Parse error|Warning|Notice|Deprecated)\s*<\/b>|(?:Fatal error|Parse error|Warning|Notice|Deprecated):[^<\n]{0,300}? on line \d+)/i',
            false];

        foreach ($assertions as [$name, $rx, $want]) {
            $got = (bool) preg_match($rx, $dom);
            if ($got === $want) { echo "    [ ok ] ", $name, "\n"; $pass++; }
            else { echo "    [FAIL] ", $name, "\n"; $fail++; $lines[] = "$label: $name"; }
        }
        echo "\n";
    }
} finally {
    // Always: this file grants a session, and must never outlive the run.
    if (is_file($seeder)) { @unlink($seeder); }
    if (!$keep) { @array_map('unlink', glob($tmpDir . '/*.html') ?: []); }
}

echo str_repeat('=', 62), "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
if ($fail) { echo "\n  Failures:\n"; foreach ($lines as $l) { echo "    - ", $l, "\n"; } }
echo "  ", ($fail ? 'UI SMOKE FAILED' : 'UI OK'), "\n", str_repeat('=', 62), "\n";
echo is_file($seeder) ? "  WARNING: {$seeder} still exists — delete it.\n" : "";
exit($fail ? 1 : 0);
