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
require_once __DIR__ . '/../includes/xlsx_writer.php'; // becXlsxZip(): dependency-free ZIP builder

$keep = 14;
$dir  = __DIR__ . '/../backups';
if (!is_dir($dir)) { mkdir($dir, 0755, true); }

try {
    $pdo = getPgsqlPdoConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "backup: cannot connect to database: " . $e->getMessage() . "\n");
    exit(1);
}

$tables = $pdo->query(
    "SELECT table_name FROM information_schema.tables
     WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
     ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

$files = [];
$manifest = ['created_at' => date('c'), 'tables' => []];
$totalRows = 0;

foreach ($tables as $t) {
    // table names come from information_schema — quote defensively anyway
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $t)) { continue; }
    try {
        $rows = $pdo->query('SELECT * FROM public."' . $t . '"')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $manifest['tables'][$t] = 'ERROR: ' . $e->getMessage();
        continue;
    }
    $files["tables/{$t}.json"] = json_encode($rows, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    $manifest['tables'][$t] = count($rows);
    $totalRows += count($rows);
}

$files['manifest.json'] = json_encode($manifest, JSON_PRETTY_PRINT);

$zipBytes = becXlsxZip($files);
$out = $dir . '/bec_db_backup_' . date('Ymd_His') . '.zip';
file_put_contents($out, $zipBytes);

// rotate: keep the newest $keep archives
$archives = glob($dir . '/bec_db_backup_*.zip') ?: [];
rsort($archives);
foreach (array_slice($archives, $keep) as $old) { @unlink($old); }

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

echo "backup ok: " . basename($out) . " — " . count($manifest['tables']) . " tables, {$totalRows} rows, "
   . round(strlen($zipBytes) / 1024) . " KB (keeping newest {$keep})\n"
   . "log rotation: {$rotated} file(s) rolled; pruned {$pruned['activity_log']} audit rows (>1y), "
   . "{$pruned['notifications']} read notifications (>180d)\n";
