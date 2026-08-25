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
 * Against a server where the app is the site root rather than a subfolder -
 * a VPS, say - pass the base URL:
 *     php scripts/e2e_smoke.php http://localhost
 *
 * Notes:
 *  - Requires Apache running at the base URL
 *  - Sends a handful of REAL emails to the smoke mailbox below (evidence!)
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$BASE  = rtrim($argv[1] ?? 'http://localhost/bec-pmo', '/');
$ROOT  = realpath(__DIR__ . '/..');
$smokeArchives = [];   // test snapshots to remove in cleanup, whatever happens
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
$pdo = null; $rid = ''; $seeds = [];

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

    // ── 1b) follow-up, and whose report a tag search resolves to ─────
    // Track used to match report_id OR equipment_id OR asset_tag and then take
    // the newest row, ignoring who was asking. A reporter searching the tag
    // printed on the equipment got a stranger's report and was told to sign in
    // with the address they were already signed in with. The decoy below is
    // deliberately NEWER than the smoke report, so a page that still sorts by
    // date alone fails this.
    $decoy = 'BEC-SMOKE-DECOY';
    $pdo->exec("DELETE FROM defect_reports WHERE report_id=" . $pdo->quote($decoy));
    $ins = $pdo->prepare("INSERT INTO defect_reports (report_id, equipment_id, reporter_email, reporter_name,
                            issue_description, status, priority, report_date)
                          VALUES (:r, :e, 'someone.else@bec.edu.ph', 'Smoke Decoy',
                            'SMOKE TEST: decoy on the same equipment.', 'reported', 'low', now() + interval '1 minute')");
    $ins->execute([':r' => $decoy, ':e' => $eq]);

    [, $tagPage] = http($JAR_R, 'GET', "$BASE/track_report.php?q=" . urlencode($eq));
    step('Tag search resolves to the right reporter',
        str_contains($tagPage, $rid) && !str_contains($tagPage, 'Sign in with your BEC email'),
        str_contains($tagPage, $rid) ? 'own ticket shown' : 'showed the decoy');

    $tokFu = csrfFrom($tagPage);
    http($JAR_R, 'POST', "$BASE/track_report.php", [
        'action' => 'follow_up', 'report_id' => $rid,
        'follow_up_note' => 'SMOKE TEST: still broken.',
    ], ["X-CSRF-Token: $tokFu"]);
    $fu = $pdo->query("SELECT COALESCE(follow_up_count,0) || '|' || COALESCE(follow_up_note,'')
                         FROM defect_reports WHERE report_id=" . $pdo->quote($rid))->fetchColumn();
    step('Reporter follow-up recorded with its message',
        str_starts_with((string)$fu, '1|SMOKE TEST: still broken.'), (string)$fu);

    $notified = (int)$pdo->query("SELECT COUNT(*) FROM notifications
                                   WHERE type='follow_up' AND related_id=" . $pdo->quote($rid))->fetchColumn();
    step('Follow-up reaches the admins', $notified > 0, "$notified admin(s) notified");

    // A follow-up queues branded mail to every admin of the owning unit — real
    // people, real inboxes. Drop it here, before the next workflow step calls
    // sendEmail() and drains the outbox: a smoke run must never put a test
    // ticket in front of the office. Safe to do now because a deferred send only
    // writes the file, it does not flush.
    foreach (glob($ROOT . '/data/mail_outbox/*.json') ?: [] as $queued) {
        $payload = json_decode((string)@file_get_contents($queued), true);
        if (is_array($payload) && str_contains((string)($payload['subject'] ?? ''), $rid)) { @unlink($queued); }
    }

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

    // ── 6) backup round trip ─────────────────────────────────
    // An administrator created a snapshot, downloaded it, uploaded it straight
    // back, and was told it was "not a BEC database snapshot". Recovery is the
    // feature you find out about at the worst possible moment, so the whole
    // loop is walked here: write it, fetch it over real HTTP, and read the
    // fetched bytes back in — including after the natural detour of unpacking
    // it to look inside and zipping it up again.
    require_once $ROOT . '/includes/backup_restore.php';
    $bk = becCreateDatabaseBackup($pdo, 'bec_smoke_backup', 1);
    $smokeArchives[] = $bk['path'];

    [$dlCode, $dlBytes] = http($JAR_A, 'GET', "$BASE/admin_backup.php?dl=" . urlencode($bk['file']));
    $dlPath = sys_get_temp_dir() . '/bec_smoke_downloaded.zip';
    $smokeArchives[] = $dlPath;
    file_put_contents($dlPath, $dlBytes);
    step('Snapshot survives the download byte for byte',
        $dlCode === 200 && md5_file($bk['path']) === md5($dlBytes),
        $dlCode === 200 ? strlen($dlBytes) . ' bytes, hashes match' : "HTTP $dlCode");

    $chk = becInspectBackupArchive($dlPath, $pdo);
    step('The downloaded snapshot imports again',
        $chk['ok'] && $chk['integrity'] === 'verified',
        $chk['ok'] ? 'accepted, fingerprint ' . $chk['integrity'] : $chk['message']);

    // Unpacked and re-zipped by hand — what Windows Explorer produces, and the
    // shape that was being refused.
    $wrapPath = sys_get_temp_dir() . '/bec_smoke_rezipped.zip';
    $smokeArchives[] = $wrapPath;
    $wrapped = [];
    foreach (becXlsxUnzip($bk['path']) as $n => $b) { $wrapped['some_folder/' . $n] = $b; }
    file_put_contents($wrapPath, becXlsxZip($wrapped));
    $rez = becInspectBackupArchive($wrapPath, $pdo);
    step('A re-zipped snapshot is still accepted',
        $rez['ok'] && $rez['wrapped'] === 'some_folder',
        $rez['ok'] ? "unwrapped '{$rez['wrapped']}'" : $rez['message']);

    // And a recovery says what it would do before it is asked to do it.
    $dry = becRestoreFromBackup($pdo, $bk['path'], ['maintenance_technicians'], true);
    step('Recovery can be previewed without writing',
        $dry['ok'] && isset($dry['preview']['maintenance_technicians']),
        $dry['ok'] ? $dry['message'] : $dry['message']);

} catch (\Throwable $e) {
    step('UNEXPECTED ERROR', false, $e->getMessage());
}

// ── cleanup (always) ─────────────────────────────────────────────────
echo "-- cleanup --\n";
/* Test snapshots are not backups anyone wants kept, and one of them is a full
   copy of the live database sitting in the folder the admin page offers for
   download. The retry is not superstition: on Windows a file this size is
   still being scanned when the run reaches here, unlink() fails, and the
   archive survived until the *next* run swept it up. */
$smokeArchives = array_merge($smokeArchives, glob($ROOT . '/backups/bec_smoke_backup_*.zip') ?: []);
foreach (array_unique($smokeArchives) as $__a) {
    for ($__try = 0; $__try < 5; $__try++) {
        clearstatcache(true, $__a);
        if (!file_exists($__a) || @unlink($__a)) { break; }
        usleep(300000);
    }
    if (file_exists($__a)) { echo "  WARNING: could not remove test archive " . basename($__a) . "\n"; }
}
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
            $wipe($pdo, "DELETE FROM defect_reports WHERE report_id='BEC-SMOKE-DECOY'");
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
    foreach ($seeds as $sfile) { @unlink($sfile); }
    foreach (glob($ROOT . '/uploads/completed_work/*/*.png') ?: [] as $p) { if (filesize($p) < 1024) @unlink($p); }
    @unlink($JAR_R); @unlink($JAR_A); @unlink($JAR_T);
    echo "  cleanup done (test data removed)\n";
} catch (\Throwable $e) {
    echo "  CLEANUP WARNING: " . $e->getMessage() . "\n";
}

$total = count($results);
$passed = $total - $fail;
echo "== RESULT: $passed/$total steps passed" . ($fail ? "  ***FAILURES: $fail***" : "  — ALL GREEN ✔") . " ==\n";
exit($fail ? 1 : 0);
