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

/* The reporter portal does not use a role session at all: it runs on a guest
   session keyed by email, checked by becGuestSessionActive(). Seeding a role
   here would have bounced straight back to the sign-in screen. */
if ($role === 'guest') {
    startPublicSession();
    $_SESSION['guest_email'] = 'ui.smoke@bec.edu.ph';
    $_SESSION['guest_name']  = 'UI Smoke';
    $_SESSION['guest_since'] = time();
    $_SESSION['guest_last']  = time();
    header('Location: ' . ($_GET['to'] ?? 'student_dashboard.php'));
    exit;
}

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
function fetchPage(string $url, string $cookie = ''): string {
    $opts = ['http' => ['timeout' => 20, 'ignore_errors' => true]];
    if ($cookie !== '') { $opts['http']['header'] = "Cookie: {$cookie}\r\n"; }
    return (string) @file_get_contents($url, false, stream_context_create($opts));
}

/**
 * Sign in through the seeder over HTTP and return the session cookie.
 *
 * For pages that are fully server-rendered: their content does not depend on a
 * script running, so HTTP sees exactly what a browser would, and it sidesteps
 * the headless engine returning an empty document for some URLs. Pages whose
 * behaviour is produced by JavaScript stay in the browser, where they belong.
 */
function sessionCookieFor(string $base, string $role): string {
    $ctx = stream_context_create(['http' => [
        'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    @file_get_contents($base . '/_ui_smoke_session.php?role=' . $role . '&to=index.php', false, $ctx);
    foreach ($http_response_header ?? [] as $h) {
        if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*(BECSESSID_[A-Z]+=[^;]+)/i', $h, $m)) {
            return $m[1];
        }
    }
    return '';
}

/* Each check: [label, role, page, [assertions]].
   role 'none' means "fetch it signed out over HTTP"; any other role signs in
   first and renders it in the browser so its scripts run.
   An assertion is [name, regex, shouldMatch]. Keep them about behaviour the
   browser produced, not text PHP printed — that is what e2e_smoke already sees. */
/* A report the seeded technician actually owns — the completion, cost and
   service-report pages all key off ?report=, and a report belonging to someone
   else would be refused by the guard rather than rendered. Resolved live so the
   suite keeps working as the demo data changes. */
$techReport = '';
try {
    require_once $root . '/config/database.php';
    $pdo = getPgsqlPdoConnection();
    $techReport = (string) $pdo->query(
        "SELECT d.report_id FROM public.defect_reports d
           JOIN public.users u ON u.user_id = d.assigned_to
          WHERE u.role = 'technician' AND u.status = 'active'
          ORDER BY u.user_id, d.report_date DESC LIMIT 1"
    )->fetchColumn();
} catch (Throwable $e) { /* the per-report pages are skipped below */ }

/* Venue reservation is hidden while it is excluded from the capstone study
   (see config/features.php). Its pages then redirect, so the checks that walk
   them are skipped rather than failing — and come back when the flag does. */
$venueOn = true;
$__featFile = __DIR__ . '/../config/features.php';
if (is_file($__featFile)) {
    require_once $__featFile;
    if (function_exists('becVenueEnabled')) { $venueOn = becVenueEnabled(); }
}

$checks = [
    ['Landing', 'admin', 'index.php', [
        ['renders',                  '/<\/html>/i',                 true],
        // With venue reservation excluded from the study, the landing page must
        // offer no way into it. With the flag on, the entry point must be there.
        ['reserve-a-venue entry',    '/reserve_venue\.php/',        $venueOn],
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
        ['follow-up filter',         '/id="fsn"/',                  true],
        ['filter bar',               '/id="fsq"/',                  true],
    ]],
    /* The list pages in the database now, so a second page has to render on its
       own rather than being a slice JavaScript reveals. #repPager is the hook
       because it appears whenever there are results at all — asserting on a
       'page=3' link instead would start failing the day the backlog drops below
       three pages, which is not a regression. */
    /* 'http:admin', not 'admin': the browser path reaches a page through the
       seeder's redirect, which drops the query string and lands on a 404. Every
       other check with a query string here uses the http: form for that reason.
       The pager is server-rendered anyway, so no JS needs to run for this. */
    ['Defect reports paging', 'http:admin', 'admin_defect_reports.php?page=2', [
        ['pager rendered',           '/id="repPager"/',             true],
        ['no PHP error leaked',      '/Fatal error|Undefined variable/',  false],
    ]],
    /* The notification bell assertions are here rather than on the dashboard on
       purpose: this is a page with no header bell of its own, and the placement
       script used to delete the floating one outright, so Work Orders, Backup
       and Preventive showed no unread indicator at all. */
    ['Work Orders', 'admin', 'admin_work_orders.php', [
        ['renders',                  '/<\/html>/i',               true],
        ['ledger rows carry data',   '/data-wo=/',                  true],
        ['service record dialog',    '/id="woOvl"/',               true],
        ['notification bell survives','/aia-bell/',                 true],
        // Four columns nothing in the system writes were rendered in the service
        // record, so they could only ever show an em dash. They must not return.
        ['no dead Findings field',   "/field\('Findings'/",         false],
        ['no dead Recommendations',  "/field\('Recommendations'/",  false],
        ['what the technician records is shown', "/field\('Diagnosis'/", true],
        ['state legend present',     '/class="legend"/',            true],
        ['ledger can be exported',   '/export=csv/',                true],
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
    /* The rest of the admin surface. These carry the heaviest queries and the
       most markup, so a fatal here is both likely and loud. */
    ['User management', 'admin', 'admin_users.php', [
        ['roster renders',           '/<table|urow/',               true],
        ['search present',           '/id="fsq"/',                  true],
    ]],
    ['Inventory', 'admin', 'admin_inventory.php', [
        ['renders',                  '/<\/html>/i',                 true],
    ]],
    ['Analytics', 'admin', 'admin_analytics.php', [
        ['renders',                  '/<\/html>/i',                 true],
        ['charts vendored, not CDN', '/cdnjs|jsdelivr/',            false],
    ]],
    ['BEC directory', 'admin', 'admin_bec_directory.php', [
        ['renders',                  '/<\/html>/i',                 true],
    ]],
    /* Recovery is the feature nobody exercises until the worst possible day.
       The one-step "type RESTORE and hope" form is gone: the page must offer a
       preview first, and it must keep a notification bell (it has no header
       bell of its own, so the placement script used to delete it). */
    ['Recovery flow', 'admin', 'admin_backup.php', [
        ['renders',                  '/<\/html>/i',                 true],
        ['preview comes before restore', '/value="restore_preview"/', true],
        ['import form present',      '/name="archive"/',             true],
        ['notification bell survives','/aia-bell/',                   true],
    ]],
    ['Notifications', 'admin', 'admin_notifications.php', [
        ['renders',                  '/<\/html>/i',                 true],
    ]],
    /* The dashboard's bell used to carry .npip — a dot that pulsed whether or
       not anything was unread. The real count replaces it in the DOM, so if the
       dot is still there after scripts run, the count did not land. */
    ['Dashboard bell shows a real count', 'admin', 'admin_dashboard.php', [
        ['decorative dot replaced',  '/class="npip"/',              false],
        ['bell present',             '/fa-bell/',                   true],
    ]],
    /* No reservation exists in a clean database, so the useful assertion is the
       one that would otherwise be found by a panellist: that asking for a
       record which is not there gives a civil answer, not a stack trace. */
    ['VRF print (missing record)', 'http:admin', 'admin_reservation_print.php?id=0', [
        ['answers civilly',          '/no longer exists/i',         true],
        ['no stack trace',           '/Stack trace|PDOException/',  false],
    ]],

    /* The reporter portal — the surface most people actually use. Runs on a
       guest session, not a role. */
    ['Reporter dashboard', 'guest', 'student_dashboard.php', [
        ['renders',                  '/<\/html>/i',                 true],
        ['CSRF token injected',      '/name="csrf_token"/',         true],
        ['not bounced to sign-in',   '/student_index\.php["\']\s*;?\s*<\/script>/', false],
    ]],

    ['Admin sign-in (signed out)', 'none', 'admin/admin_login_otp.html', [
        ['renders',                  '/<\/html>/i',                 true],
        ['no dead remember-me box',  '/rememberMe/',                false],
        // The working one, on the code step. Unlike the dead box above it is
        // posted with the verification and remembers the browser for a day.
        ['keep-me-signed-in offered','/id="rememberDevice"/',       true],
        ['and actually submitted',   '/remember_device/',           true],
        ['forgot-password offered',  '/forgotPassword\(\)/',        true],
    ]],
    ['Technician sign-in (signed out)', 'none', 'technician/login.html', [
        ['renders',                  '/<\/html>/i',                 true],
        ['no dead remember-me box',  '/rememberMe/',                false],
        ['forgot-password offered',  '/forgotPassword\(\)/',        true],
    ]],
];

/* Skip the venue walkthroughs while the module is switched off: the pages
   redirect to index.php, so every assertion about their markup would fail on
   a page that is behaving exactly as intended. */
if (!$venueOn) {
    $checks = array_values(array_filter($checks, static function ($c) {
        return $c[0] !== 'Venue reservation form'
            && $c[0] !== 'Venue reservations queue'
            && $c[0] !== 'VRF print (missing record)';
    }));
}

/* The technician's own paperwork — where a repair actually gets written up.
   Appended rather than inlined because all three need a real report id. */
if ($techReport !== '') {
    /* technician_complete_task.php is deliberately not listed. It is a JSON
       endpoint, not a page: it answers GET with 400 and calls requireCsrf(true)
       on POST. A browser wraps that JSON in <html><pre>, so a "renders" check
       passed while proving nothing at all. */
    $checks[] = ['Cost estimate', 'http:tech', 'technician_cost_estimate.php?report=' . rawurlencode($techReport), [
        ['renders',                  '/<\/html>/i',                 true],
    ]];
    $checks[] = ['Service report', 'http:tech', 'technician_service_report.php?report=' . rawurlencode($techReport), [
        ['renders',                  '/<\/html>/i',                 true],
    ]];
    $checks[] = ['Printable ticket', 'http:tech', 'defect_report_ticket.php?report=' . rawurlencode($techReport), [
        ['renders',                  '/<\/html>/i',                 true],
        ['ticket number shown',      '/' . preg_quote($techReport, '/') . '/', true],
    ]];
}

$pass = 0; $fail = 0; $lines = [];

try {
    echo str_repeat('=', 62), "\n  UI SMOKE — ", $base, "\n", str_repeat('=', 62), "\n\n";

    foreach ($checks as [$label, $role, $page, $assertions]) {
        if ($role === 'none') {
            $dom = fetchPage($base . '/' . $page);
        } elseif (str_starts_with($role, 'http:')) {
            // "http:admin" / "http:tech" — server-rendered, fetched with a session.
            $r = substr($role, 5);
            static $cookies = [];
            if (!isset($cookies[$r])) { $cookies[$r] = sessionCookieFor($base, $r); }
            $dom = fetchPage($base . '/' . $page, $cookies[$r]);
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
