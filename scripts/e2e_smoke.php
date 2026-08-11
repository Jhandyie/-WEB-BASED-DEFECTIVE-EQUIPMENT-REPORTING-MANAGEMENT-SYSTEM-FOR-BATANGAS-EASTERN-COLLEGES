<?php
/**
 * scripts/e2e_smoke.php — full-lifecycle smoke test.
 *
 * Walks ONE test report through the entire workflow over real HTTP:
 *   reporter submit → admin receive → approve → assign technician →
 *   technician accept → start → completion w/ photo →
 *   admin verify → reporter satisfaction
 * asserting every status transition, then removes ALL test data.
 *
 * Run before a demo or after any change:
 *     c:\xampp\php\php.exe scripts\e2e_smoke.php
 *
 * Notes:
 *  - Requires Apache running at http://localhost/bec-pmo/
 *  - Sends a handful of REAL emails to the smoke mailbox below (evidence!)
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$BASE  = 'http://localhost/bec-pmo';
$ROOT  = realpath(__DIR__ . '/..');
$SMOKE_MAIL = 'jhanmarkdecastro128@gmail.com';   // where smoke emails land
$JAR_R = tempnam(sys_get_temp_dir(), 'smk_r');
$JAR_A = tempnam(sys_get_temp_dir(), 'smk_a');
$JAR_T = tempnam(sys_get_temp_dir(), 'smk_t');

require_once $ROOT . '/config/database.php';

$results = [];
$fail = 0;
function step(string $name, bool $ok, string $detail = ''): void {
    global $results, $fail;
    $results[] = [$ok, $name, $detail];
    echo ($ok ? '  PASS  ' : '! FAIL  ') . $name . ($detail !== '' ? "  — $detail" : '') . "\n";
    if (!$ok) $fail++;
}
function http(string $jar, string $method, string $url, array $fields = [], array $headers = [], bool $multipart = false): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function seedFile(string $root, string $name, string $php): string {
    $p = $root . '/' . $name;
    file_put_contents($p, $php);
    return $p;
}
function csrfFrom(string $html): string {
    if (preg_match('/var CSRF_TOKEN = "([^"]+)"/', $html, $m)) return $m[1];
    if (preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}
function dbStatus(string $rid): string {
    try { return (string) getPgsqlPdoConnection()->query("SELECT status FROM defect_reports WHERE report_id=" . getPgsqlPdoConnection()->quote($rid))->fetchColumn(); }
    catch (\Throwable $e) { return 'ERR:' . $e->getMessage(); }
}

echo "== BEC PMO lifecycle smoke test ==\n";
$pdo = null; $rid = ''; $seeds = []; $settingsBackup = null;
$settingsFile = $ROOT . '/data/system_settings.json';

try {
    // ── 0) preconditions ──────────────────────────────────────────────
    [$c] = http($JAR_R, 'GET', "$BASE/index.php");
    step('Apache serving the app', $c === 200, "HTTP $c");
    if ($c !== 200) throw new Exception('server down');

    $pdo = getPgsqlPdoConnection();
    step('Database reachable', true);

    // temp technician (email = smoke mailbox)
    $pdo->exec("DELETE FROM users WHERE user_id='TECH-SMOKE1'");
    $st = $pdo->prepare("INSERT INTO users (user_id, username, email, fullname, password, role, status, created_at)
                         VALUES ('TECH-SMOKE1','smoke_technician',?, 'Smoke Test Technician', ?, 'technician','active',NOW())");
    $st->execute([$SMOKE_MAIL, password_hash('Smoke!2026x', PASSWORD_DEFAULT)]);
    $eq = $pdo->query("SELECT equipment_id FROM equipment WHERE LOWER(COALESCE(status,''))<>'deleted' LIMIT 1")->fetchColumn();
    step('Fixtures ready (technician + equipment)', (bool)$eq, "equipment $eq");

    // session seeders
    // guest_email/guest_name are the reporter portal's identity (student_index.php).
    // track_report.php now requires them to match the report before it will accept
    // a follow-up or a satisfaction verdict, so the seeded reporter carries them.
    $seeds[] = seedFile($ROOT, '_smoke_seed_r.php', "<?php\nsession_start();\n\$_SESSION['user_id']='SMOKE-REPORTER';\$_SESSION['fullname']='Smoke Reporter';\$_SESSION['email']='$SMOKE_MAIL';\$_SESSION['user_email']=\$_SESSION['email'];\$_SESSION['role']='student';\n\$_SESSION['guest_email']='$SMOKE_MAIL';\$_SESSION['guest_name']='Smoke Reporter';\$_SESSION['guest_since']=time();\nrequire __DIR__.'/includes/csrf.php';\n\$__t=csrf_token();\nsession_write_close();\nrequire __DIR__.'/includes/session_bootstrap.php';\nstartRoleSession('student');\n\$_SESSION['user_id']='SMOKE-REPORTER';\$_SESSION['fullname']='Smoke Reporter';\$_SESSION['email']='$SMOKE_MAIL';\$_SESSION['user_email']=\$_SESSION['email'];\$_SESSION['role']='student';\necho \$__t;");
    $seeds[] = seedFile($ROOT, '_smoke_seed_a.php', "<?php\nrequire __DIR__.'/includes/session_bootstrap.php';\nstartRoleSession('admin');\nrequire __DIR__.'/config/database.php';\n\$c=getDBConnection();\$r=\$c->query(\"SELECT user_id,fullname FROM users WHERE role IN ('admin','pmo') AND status='active' LIMIT 1\")->fetch_assoc();\n\$_SESSION['user_id']=\$r['user_id'];\$_SESSION['fullname']=\$r['fullname'];\$_SESSION['role']='admin';\$_SESSION['logged_in']=true;\necho 'ok';");
    $seeds[] = seedFile($ROOT, '_smoke_seed_t.php', "<?php\nrequire __DIR__.'/includes/session_bootstrap.php';\nstartRoleSession('technician');\n\$_SESSION['user_id']='TECH-SMOKE1';\$_SESSION['fullname']='Smoke Test Technician';\$_SESSION['user_email']='$SMOKE_MAIL';\$_SESSION['email']='$SMOKE_MAIL';\$_SESSION['role']='technician';\$_SESSION['logged_in']=true;\necho 'ok';");
    // The seeder returns the reporter session's CSRF token; the submit endpoint
    // now requires it, exactly as the real form does.
    [, $reporterToken] = http($JAR_R, 'GET', "$BASE/_smoke_seed_r.php");
    $reporterToken = trim($reporterToken);
    http($JAR_A, 'GET', "$BASE/_smoke_seed_a.php");
    http($JAR_T, 'GET', "$BASE/_smoke_seed_t.php");

    // ── 1) reporter submits ──────────────────────────────────────────
    [, $b] = http($JAR_R, 'POST', "$BASE/api/student_dashboard_api.php", [
        'action' => 'submit_report', 'equipment_id' => $eq, 'duplicate_override' => '1',
        'issue_description' => 'SMOKE TEST: automated lifecycle check — safe to ignore.',
        'location' => 'Smoke Test Room',
    ], ["X-CSRF-Token: $reporterToken"], true);
    $j = json_decode($b, true);
    $rid = (string)($j['report_id'] ?? '');
    step('Reporter submit', ($j['success'] ?? false) && $rid !== '', $rid ?: substr($b, 0, 120));
    if ($rid === '') throw new Exception('no report id');

    // ── 2) admin receive + approve ───────────────────────────────────
    [, $page] = http($JAR_A, 'GET', "$BASE/admin_defect_reports.php");
    $tok = csrfFrom($page);
    http($JAR_A, 'POST', "$BASE/admin_defect_reports.php", ['action' => 'mark_received', 'report_id' => $rid], ["X-CSRF-Token: $tok"]);
    step('Admin: mark received → pmo_review', dbStatus($rid) === 'pmo_review', dbStatus($rid));
    http($JAR_A, 'POST', "$BASE/admin_defect_reports.php", ['action' => 'approve', 'report_id' => $rid, 'department_assigned' => 'PMO', 'priority' => 'high', 'admin_notes' => 'smoke'], ["X-CSRF-Token: $tok"]);
    step('Admin: approve → assigned', dbStatus($rid) === 'assigned', dbStatus($rid));

    // ── 3) assign technician ─────────────────────────────────────────
    [, $page] = http($JAR_A, 'GET', "$BASE/admin_assign_technicians.php");
    $tok2 = csrfFrom($page);
    http($JAR_A, 'POST', "$BASE/admin_assign_technicians.php", ['action' => 'assign', 'report_id' => $rid, 'technician_id' => 'TECH-SMOKE1', 'priority' => 'high', 'instructions' => 'smoke', 'department' => 'PMO'], ["X-CSRF-Token: $tok2"]);
    $assigned = $pdo->query("SELECT assigned_to FROM defect_reports WHERE report_id=" . $pdo->quote($rid))->fetchColumn();
    step('Assign technician (email fires)', $assigned === 'TECH-SMOKE1', "assigned_to=$assigned");

    // ── 4) technician workflow ───────────────────────────────────────
    [, $page] = http($JAR_T, 'GET', "$BASE/technician_dashboard.php");
    $tok3 = csrfFrom($page);
    http($JAR_T, 'POST', "$BASE/technician_dashboard.php", ['action' => 'accept', 'report_id' => $rid, 'technician_notes' => ''], ["X-CSRF-Token: $tok3"]);
    step('Technician: receive → accepted', dbStatus($rid) === 'accepted', dbStatus($rid));
    http($JAR_T, 'POST', "$BASE/technician_dashboard.php", ['action' => 'start', 'report_id' => $rid, 'technician_notes' => 'smoke start'], ["X-CSRF-Token: $tok3"]);
    step('Technician: start → in_progress', dbStatus($rid) === 'in_progress', dbStatus($rid));

    // NOTE: the budget-request / Finance-acknowledgment step was removed from the
    // system (feature retired) — the technician now goes straight to completion.

    // completion with a photo
    $png = sys_get_temp_dir() . '/smoke_photo.png';
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    [, $b] = http($JAR_T, 'POST', "$BASE/technician_complete_task.php", [
        'report_id' => $rid, 'action' => 'complete',
        'date_started' => date('Y-m-d\TH:i'), 'repair_duration' => '5m', 'repair_cost' => '10',
        'diagnosis' => 'smoke diag', 'actions_performed' => 'smoke actions', 'work_performed' => 'smoke summary',
        'before_photos[]' => new CURLFile($png, 'image/png', 'before.png'),
    ], ["X-CSRF-Token: $tok3"], true);
    $j = json_decode($b, true);
    step('Completion report (photo upload)', ($j['success'] ?? false) && dbStatus($rid) === 'completed', dbStatus($rid));

    // ── 5) verify + satisfaction ─────────────────────────────────────
    [, $page] = http($JAR_A, 'GET', "$BASE/admin_defect_reports.php");
    $tok4 = csrfFrom($page);
    http($JAR_A, 'POST', "$BASE/admin_defect_reports.php", ['action' => 'verify_completion', 'report_id' => $rid, 'verification_notes' => 'smoke verified'], ["X-CSRF-Token: $tok4"]);
    step('Admin: verify & close → closed', dbStatus($rid) === 'closed', dbStatus($rid));
    [, $trackPage] = http($JAR_R, 'GET', "$BASE/track_report.php?q=" . urlencode($rid));
    $tok5 = csrfFrom($trackPage);
    http($JAR_R, 'POST', "$BASE/track_report.php", ['action' => 'confirm_satisfaction', 'report_id' => $rid, 'verdict' => 'yes', 'satisfaction_note' => 'smoke ok'], ["X-CSRF-Token: $tok5"]);
    $sat = $pdo->query("SELECT COALESCE(satisfaction,'') FROM defect_reports WHERE report_id=" . $pdo->quote($rid))->fetchColumn();
    step('Reporter satisfaction recorded', $sat === 'satisfied', "satisfaction=$sat");

    // The same verdict from someone who is not the reporter must be refused.
    $jarOther = tempnam(sys_get_temp_dir(), 'smk_o');
    $pdo->exec("UPDATE defect_reports SET satisfaction=NULL, satisfaction_at=NULL WHERE report_id=" . $pdo->quote($rid));
    http($jarOther, 'POST', "$BASE/track_report.php", ['action' => 'confirm_satisfaction', 'report_id' => $rid, 'verdict' => 'yes']);
    $satAnon = (string)$pdo->query("SELECT COALESCE(satisfaction,'') FROM defect_reports WHERE report_id=" . $pdo->quote($rid))->fetchColumn();
    step('Stranger cannot record satisfaction', $satAnon === '', $satAnon === '' ? 'refused' : "WROTE '$satAnon'");
    @unlink($jarOther);

} catch (\Throwable $e) {
    step('UNEXPECTED ERROR', false, $e->getMessage());
}

// ── cleanup (always) ─────────────────────────────────────────────────
echo "-- cleanup --\n";
try {
    if ($pdo) {
        // Each statement stands on its own. They used to share one try, so a
        // single failure — a table that no longer exists, a changed column —
        // aborted the rest and left the report behind.
        $wipe = static function (PDO $pdo, string $sql): void {
            try { $pdo->exec($sql); } catch (\Throwable $e) { /* keep cleaning */ }
        };
        if ($rid) {
            $q = $pdo->quote($rid);
            $wipe($pdo, "DELETE FROM notifications WHERE related_id={$q}");
            $wipe($pdo, "DELETE FROM maintenance_history WHERE report_id={$q}");
            $wipe($pdo, "DELETE FROM work_orders WHERE report_id={$q}");
            $wipe($pdo, "DELETE FROM defect_reports WHERE report_id={$q}");
        }
        // Belt and braces: the delete above only runs when $rid was captured, so
        // a crash between submitting and reading the ticket number left a report
        // titled "SMOKE TEST: …" sitting on the PUBLIC transparency board. It
        // did. Sweep by the markers only this script ever writes.
        $wipe($pdo, "DELETE FROM defect_reports
                      WHERE issue_description LIKE 'SMOKE TEST:%'
                         OR reporter_name = 'Smoke Reporter'");
        $wipe($pdo, "DELETE FROM users WHERE user_id='TECH-SMOKE1'");
    }
    if ($settingsBackup !== null) { file_put_contents($settingsFile, $settingsBackup); }
    foreach ($seeds as $sfile) { @unlink($sfile); }
    foreach (glob($ROOT . '/uploads/completed_work/*/*.png') ?: [] as $p) { if (filesize($p) < 1024) @unlink($p); }
    @unlink($JAR_R); @unlink($JAR_A); @unlink($JAR_T);
    echo "  cleanup done (test data removed, mail settings restored)\n";
} catch (\Throwable $e) {
    echo "  CLEANUP WARNING: " . $e->getMessage() . "\n";
}

$total = count($results);
$passed = $total - $fail;
echo "== RESULT: $passed/$total steps passed" . ($fail ? "  ***FAILURES: $fail***" : "  — ALL GREEN ✔") . " ==\n";
exit($fail ? 1 : 0);
