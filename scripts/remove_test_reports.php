<?php
/**
 * remove_test_reports.php — take the joke and "just testing" reports off the
 * live system before the defense.
 *
 * Found during the September 2026 UI review: reports titled "Heart ko po",
 * "lolo mo", "testing testing", "Try out lang po", a preventive-maintenance
 * schedule whose instructions are literally "This is a test" (and the task it
 * generates every week), and so on. A panelist reading the Priority Alerts
 * card sees these as the system's data, not as a demo.
 *
 * Everything below is listed by ticket number, not by pattern, so nothing is
 * deleted on a guess. Real faults written in Filipino ("Walang lock",
 * "walangg tubiggg") were reviewed and deliberately kept.
 *
 * A full database snapshot is taken first, into backups/, and named on the
 * way out — so this is reversible from the Backup & Recovery page.
 *
 *   c:\xampp\php\php.exe scripts\remove_test_reports.php           # dry run: lists, deletes nothing
 *   c:\xampp\php\php.exe scripts\remove_test_reports.php --apply   # snapshot, then delete
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/backup_restore.php';

$tickets = [
    'BEC-2026-000251', // "test"
    'BEC-2026-000249', // weekly PM task generated from the "This is a test" schedule
    'BEC-2026-000238', // "test"
    'BEC-2026-000219', // "Heart nya po" / "Sya lang sapat na"
    'BEC-2026-000218', // "Heart ko po" / "Broken"
    'BEC-2026-000217', // "lolo mo"
    'BEC-2026-000210', // "Hindi gumagana, sample lang po"
    'BEC-2026-000171', // "Testing, testing"
    'BEC-2026-000169', // "Try out lang po at the moment"
    'BEC-2026-000168', // "testing testing"
    'BEC-2026-000167', // "Try out lng po ito."
    // Second pass, found on the public board after the first run (2026-09-18).
    // "Problem Details" is the form's own section heading typed in as the
    // description, four times by three different reporters. Tickets already
    // removed are simply absent from the listing; the deletes are by number.
    'BEC-2026-000247', // "piano"
    'BEC-2026-000231', // "Worst"
    'BEC-2026-000224', // "Ang galing"
    'BEC-2026-000223', // "becpmo.online/student_index.php"
    'BEC-2026-000222', // "becpmo"
    'BEC-2026-000221', // "becpmo.online/student_index.php"
    'BEC-2026-000216', // "mamamo"
    'BEC-2026-000214', // "Problem Details"
    'BEC-2026-000211', // "Problem Details"
    'BEC-2026-000208', // "Problem Details"
    'BEC-2026-000206', // "ioyhuioghjg;luktg"
    'BEC-2026-000205', // "KUNG ANO"
    'BEC-2026-000201', // "Problem Details"
    // Third pass: the older pages of the public board, once the first two
    // batches were gone. Keyboard mashes and jokes; "mahangin sobra" and
    // "hindi abot sa akin ang aircon" read as real complaints and stay.
    'BEC-2026-000187', // "Problem Details"
    'BEC-2026-000183', // "Problem Details"
    'BEC-2026-000174', // "gwjqkqg"
    'BEC-2026-000170', // "Papoi"
    'BEC-2026-000161', // "Nothing"
    'BEC-2026-000159', // "gejanna"
    'BEC-2026-000158', // "gsjanabau"
    'BEC-2026-000157', // "hskana"
    'BEC-2026-000156', // "masakit ang talab boss"
    'BEC-2026-000148', // "Hakdog ni de castro"
];
$testScheduleId = 5;   // title "Maintenance", instructions "This is a test", every 7 days

$apply = in_array('--apply', $argv, true);
$pdo   = getPgsqlPdoConnection();
$in    = implode(',', array_map([$pdo, 'quote'], $tickets));

echo $apply ? "== APPLYING ==\n" : "== DRY RUN (add --apply to delete) ==\n";
foreach ($pdo->query("SELECT report_id, status, COALESCE(equipment_name,'') eq, LEFT(issue_description,50) d
                        FROM public.defect_reports WHERE report_id IN ($in) ORDER BY report_id") as $r) {
    printf("  %-16s %-10s %-20s %s\n", $r['report_id'], $r['status'], $r['eq'], str_replace("\n", ' ', $r['d']));
}
$sched = $pdo->query("SELECT id, title, instructions FROM public.preventive_schedules WHERE id = {$testScheduleId}")->fetch(PDO::FETCH_ASSOC);
echo $sched ? "  schedule #{$sched['id']}: {$sched['title']} — {$sched['instructions']}\n" : "  schedule #{$testScheduleId}: already gone\n";
if (!$apply) { exit(0); }

$b = becCreateDatabaseBackup($pdo, 'bec_db_backup', 14);
echo "snapshot: {$b['file']} ({$b['rows']} rows)\n";

$pdo->beginTransaction();
$n1 = $pdo->exec("DELETE FROM public.notifications WHERE related_id IN ($in)");
$n2 = $pdo->exec("DELETE FROM public.maintenance_history WHERE report_id IN ($in)");
$n3 = $pdo->exec("DELETE FROM public.defect_reports WHERE report_id IN ($in)");
$n4 = $pdo->exec("DELETE FROM public.preventive_schedules WHERE id = {$testScheduleId} AND instructions = 'This is a test'");
$pdo->commit();

$files = 0;
foreach ($tickets as $t) { foreach (glob(dirname(__DIR__) . "/uploads/reports/{$t}_*") ?: [] as $f) { @unlink($f); $files++; } }

echo "deleted: {$n3} reports, {$n1} notifications, {$n2} history rows, {$n4} schedule, {$files} uploaded files\n";
echo "reports remaining: " . $pdo->query("SELECT COUNT(*) FROM public.defect_reports")->fetchColumn() . "\n";
