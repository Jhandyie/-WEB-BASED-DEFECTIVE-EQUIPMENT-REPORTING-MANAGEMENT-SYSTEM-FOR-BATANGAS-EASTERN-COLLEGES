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

echo "backup ok: " . basename($out) . " — " . count($manifest['tables']) . " tables, {$totalRows} rows, "
   . round(strlen($zipBytes) / 1024) . " KB (keeping newest {$keep})\n";
