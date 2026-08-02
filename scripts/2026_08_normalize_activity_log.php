<?php
/**
 * 2026-08-02 — normalize the audit rows written before logActivity() was fixed.
 *
 * Rows written by the 3-argument call shape landed shifted: the action code went
 * into user_role, the human message into action_type, and action_description was
 * left empty. New rows are written correctly (see config/database.php), so this
 * brings the history into the same shape:
 *
 *     user_role          = the actor's real role (from users, else 'system')
 *     action_type        = the action code   ('auth.login')
 *     action_description = the human message ('Admin login (2FA) for …')
 *
 * Only rows that actually show the shifted pattern are touched: user_role holds
 * something that is not a role word, and action_description is empty. Rows the
 * 4-argument callers wrote are already correct and are left alone.
 *
 * Writes a JSON snapshot of the whole table to backups/ first — the UPDATE is
 * not otherwise reversible.
 *
 *     c:\xampp\php\php.exe scripts\2026_08_normalize_activity_log.php [--apply]
 *
 * Without --apply it only reports what it would change.
 */
require_once __DIR__ . '/../config/database.php';

$apply = in_array('--apply', $argv, true);
$pdo = getPgsqlPdoConnection();

$roleWords = ['admin', 'reporter', 'technician', 'system', 'pmo', 'student',
              'dean', 'finance', 'handler', 'faculty', 'staff'];
$inList = "'" . implode("','", $roleWords) . "'";

$where = "COALESCE(user_role,'') <> ''
          AND LOWER(user_role) NOT IN ($inList)
          AND COALESCE(action_description,'') = ''";

$total = (int)$pdo->query("SELECT COUNT(*) FROM public.activity_log")->fetchColumn();
$shifted = (int)$pdo->query("SELECT COUNT(*) FROM public.activity_log WHERE $where")->fetchColumn();

echo "activity_log rows: $total\n";
echo "rows written in the shifted shape: $shifted\n";

if ($shifted === 0) { echo "nothing to do\n"; exit(0); }

$sample = $pdo->query("SELECT user_role, action_type FROM public.activity_log WHERE $where ORDER BY log_id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
echo "\nexample of what changes:\n";
foreach ($sample as $s) {
    echo "  before: user_role='{$s['user_role']}'  action_type='" . mb_substr((string)$s['action_type'], 0, 55) . "'\n";
    echo "  after : action_type='{$s['user_role']}'  action_description='" . mb_substr((string)$s['action_type'], 0, 45) . "'\n";
}

if (!$apply) {
    echo "\nDry run. Re-run with --apply to write the changes.\n";
    exit(0);
}

// Snapshot first.
$dir = __DIR__ . '/../backups';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
$snapshot = $dir . '/activity_log_before_normalize_' . date('Ymd_His') . '.json';
$rows = $pdo->query("SELECT * FROM public.activity_log ORDER BY log_id")->fetchAll(PDO::FETCH_ASSOC);
file_put_contents($snapshot, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nsnapshot written: " . basename($snapshot) . ' (' . count($rows) . " rows)\n";

$pdo->beginTransaction();
try {
    // The actor's role comes from their account when it still exists; anything
    // else (deleted accounts, cron, the public portal) is recorded as 'system'.
    $sql = "UPDATE public.activity_log a
               SET action_description = a.action_type,
                   action_type = a.user_role,
                   user_role = COALESCE(NULLIF((SELECT u.role FROM public.users u WHERE u.user_id = a.user_id), ''), 'system')
             WHERE $where";
    $n = $pdo->exec($sql);
    $pdo->commit();
    echo "normalized $n row(s)\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "FAILED, rolled back: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nafter:\n";
foreach ($pdo->query("SELECT user_role, action_type, action_description FROM public.activity_log ORDER BY log_id DESC LIMIT 4") as $r) {
    printf("  %-12s %-24s %s\n", $r['user_role'], $r['action_type'], mb_substr((string)$r['action_description'], 0, 55));
}
