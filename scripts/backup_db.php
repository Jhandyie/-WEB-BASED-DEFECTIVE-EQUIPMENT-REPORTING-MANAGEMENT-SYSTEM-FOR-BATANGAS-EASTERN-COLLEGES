<?php
/**
 * scripts/backup_db.php — automated database backup.
 *
 * Dumps every table in the public schema (Supabase Postgres) to JSON files
 * bundled in a timestamped ZIP under backups/. Keeps the newest 14 archives.
 *
 * Run manually:      c:\xampp\php\php.exe scripts\backup_db.php
 * Scheduled (daily): Windows Task Scheduler task "BEC PMO DB Backup".
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/backup_restore.php'; // becCreateDatabaseBackup(): shared dump core

$keep = 14;
$dir  = __DIR__ . '/../backups';

try {
    $pdo = getPgsqlPdoConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "backup: cannot connect to database: " . $e->getMessage() . "\n");
    exit(1);
}

$backup    = becCreateDatabaseBackup($pdo, 'bec_db_backup', $keep);
$out       = $backup['path'];
$manifest  = $backup['manifest'];
$totalRows = $backup['rows'];
$backupKb  = round($backup['bytes'] / 1024);

// ── Log rotation (runs with the nightly backup) ─────────────────────────────
// Files: any logs/*.log over 2 MB is rolled to *.log.1 (previous roll replaced).
// Database: audit rows older than 1 year and READ notifications older than 180
// days are pruned. Conservative, idempotent, best-effort.
$rotated = 0; $pruned = ['activity_log' => 0, 'notifications' => 0];
foreach (glob(__DIR__ . '/../logs/*.log') ?: [] as $lf) {
    if (filesize($lf) > 2 * 1024 * 1024) {
        @unlink($lf . '.1');
        @rename($lf, $lf . '.1');
        $rotated++;
    }
}
try {
    $pruned['activity_log']  = (int) $pdo->exec("DELETE FROM activity_log  WHERE created_at   < NOW() - INTERVAL '365 days'");
    $pruned['notifications'] = (int) $pdo->exec("DELETE FROM notifications WHERE is_read = true AND created_date < NOW() - INTERVAL '180 days'");
} catch (Throwable $e) {
    fwrite(STDERR, "log prune warning: " . $e->getMessage() . "\n");
}

// Flush any notifications stuck in the retry outbox (failed sends self-heal nightly).
$flushed = 0;
try {
    require_once __DIR__ . '/../includes/mail_helper.php';
    $flushed = flushMailOutbox(50);
} catch (Throwable $e) { fwrite(STDERR, "outbox flush warning: " . $e->getMessage() . "\n"); }

echo "backup ok: " . basename($out) . " — " . count($manifest['tables']) . " tables, {$totalRows} rows, "
   . "{$backupKb} KB (keeping newest {$keep})\n"
   . "log rotation: {$rotated} file(s) rolled; pruned {$pruned['activity_log']} audit rows (>1y), "
   . "{$pruned['notifications']} read notifications (>180d)\n"
   . "mail outbox: {$flushed} queued notification(s) delivered\n";
