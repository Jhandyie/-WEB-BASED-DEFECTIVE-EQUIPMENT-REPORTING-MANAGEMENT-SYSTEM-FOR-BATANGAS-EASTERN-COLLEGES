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
 *  - Requires Apache running at http://localhost/-WEB-BASED/
 *  - Sends a handful of REAL emails to the smoke mailbox below (evidence!)
 *  - Finance/Dean mail is temporarily redirected to the smoke mailbox and
 *    restored afterwards, so no real office receives test messages.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$BASE  = 'http://localhost/-WEB-BASED';
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
$pdo = null; $rid = ''; $brid = ''; $seeds = []; $settingsBackup = null;
$settingsFile = $ROOT . '/data/system_settings.json';

try {
    // ── 0) preconditions ──────────────────────────────────────────────
    [$c] = http($JAR_R, 'GET', "$BASE/index.php");
    step('Apache serving the app', $c === 200, "HTTP $c");
    if ($c !== 200) throw new Exception('server down');

    $pdo = getPgsqlPdoConnection();
    step('Database reachable', true);

    // temp mail redirects (restore in cleanup)
    $settingsBackup = file_get_contents($settingsFile);
    $s = json_decode($settingsBackup, true);
    require_once $ROOT . '/config/finance.php';
    require_once $ROOT . '/config/dean.php';
    $s['mail_redirects'][BEC_FINANCE_EMAIL] = $SMOKE_MAIL;
    $s['mail_redirects'][BEC_DEAN_EMAIL]    = $SMOKE_MAIL;
    file_put_contents($settingsFile, json_encode($s, JSON_PRETTY_PRINT));

    // temp technician (email = smoke mailbox)
    $pdo->exec("DELETE FROM users WHERE user_id='TECH-SMOKE1'");
    $st = $pdo->prepare("INSERT INTO users (user_id, username, email, fullname, password, role, status, created_at)
                         VALUES ('TECH-SMOKE1','smoke_technician',?, 'Smoke Test Technician', ?, 'technician','active',NOW())");
    $st->execute([$SMOKE_MAIL, password_hash('Smoke!2026x', PASSWORD_DEFAULT)]);
    $eq = $pdo->query("SELECT equipment_id FROM equipment WHERE LOWER(COALESCE(status,''))<>'deleted' LIMIT 1")->fetchColumn();
    step('Fixtures ready (technician + equipment)', (bool)$eq, "equipment $eq");

    // session seeders
    $seeds[] = seedFile($ROOT, '_smoke_seed_r.php', "<?php\nsession_start();\n\$_SESSION['user_id']='SMOKE-REPORTER';\$_SESSION['fullname']='Smoke Reporter';\$_SESSION['email']='$SMOKE_MAIL';\$_SESSION['user_email']=\$_SESSION['email'];\$_SESSION['role']='student';\nsession_write_close();\nrequire __DIR__.'/includes/session_bootstrap.php';\nstartRoleSession('student');\n\$_SESSION['user_id']='SMOKE-REPORTER';\$_SESSION['fullname']='Smoke Reporter';\$_SESSION['email']='$SMOKE_MAIL';\$_SESSION['user_email']=\$_SESSION['email'];\$_SESSION['role']='student';\necho 'ok';");
    $seeds[] = seedFile($ROOT, '_smoke_seed_a.php', "<?php\nrequire __DIR__.'/includes/session_bootstrap.php';\nstartRoleSession('admin');\nrequire __DIR__.'/config/database.php';\n\$c=getDBConnection();\$r=\$c->query(\"SELECT user_id,fullname FROM users WHERE role IN ('admin','pmo') AND status='active' LIMIT 1\")->fetch_assoc();\n\$_SESSION['user_id']=\$r['user_id'];\$_SESSION['fullname']=\$r['fullname'];\$_SESSION['role']='admin';\$_SESSION['logged_in']=true;\necho 'ok';");
    $seeds[] = seedFile($ROOT, '_smoke_seed_t.php', "<?php\nrequire __DIR__.'/includes/session_bootstrap.php';\nstartRoleSession('technician');\n\$_SESSION['user_id']='TECH-SMOKE1';\$_SESSION['fullname']='Smoke Test Technician';\$_SESSION['user_email']='$SMOKE_MAIL';\$_SESSION['email']='$SMOKE_MAIL';\$_SESSION['role']='technician';\$_SESSION['logged_in']=true;\necho 'ok';");
    http($JAR_R, 'GET', "$BASE/_smoke_seed_r.php");
    http($JAR_A, 'GET', "$BASE/_smoke_seed_a.php");
    http($JAR_T, 'GET', "$BASE/_smoke_seed_t.php");

    // ── 1) reporter submits ──────────────────────────────────────────
    [, $b] = http($JAR_R, 'POST', "$BASE/api/student_dashboard_api.php", [
        'action' => 'submit_report', 'equipment_id' => $eq, 'duplicate_override' => '1',
        'issue_description' => 'SMOKE TEST: automated lifecycle check — safe to ignore.',
        'location' => 'Smoke Test Room',
    ], [], true);
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
    step('Admin: verify → verified', dbStatus($rid) === 'verified', dbStatus($rid));
    http($JAR_R, 'POST', "$BASE/track_report.php", ['action' => 'confirm_satisfaction', 'report_id' => $rid, 'verdict' => 'yes', 'satisfaction_note' => 'smoke ok']);
    $sat = $pdo->query("SELECT COALESCE(satisfaction,'') FROM defect_reports WHERE report_id=" . $pdo->quote($rid))->fetchColumn();
    step('Reporter satisfaction recorded', $sat === 'satisfied', "satisfaction=$sat");

} catch (\Throwable $e) {
    step('UNEXPECTED ERROR', false, $e->getMessage());
}

// ── cleanup (always) ─────────────────────────────────────────────────
echo "-- cleanup --\n";
try {
    if ($pdo) {
        if ($brid) { $pdo->exec("DELETE FROM budget_request_items WHERE request_id=" . $pdo->quote($brid)); $pdo->exec("DELETE FROM budget_requests WHERE request_id=" . $pdo->quote($brid)); }
        if ($rid)  { $pdo->exec("DELETE FROM notifications WHERE related_id IN (" . $pdo->quote($rid) . ($brid ? "," . $pdo->quote($brid) : '') . ")"); $pdo->exec("DELETE FROM maintenance_history WHERE report_id=" . $pdo->quote($rid)); $pdo->exec("DELETE FROM work_orders WHERE report_id=" . $pdo->quote($rid)); $pdo->exec("DELETE FROM defect_reports WHERE report_id=" . $pdo->quote($rid)); }
        $pdo->exec("DELETE FROM users WHERE user_id='TECH-SMOKE1'");
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
