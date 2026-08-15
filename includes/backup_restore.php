<?php
/**
 * includes/backup_restore.php — database backup & recovery core.
 *
 * Single source of truth shared by:
 *   • scripts/backup_db.php   (nightly Windows Task Scheduler job)
 *   • admin_backup.php        (admin-facing Backup & Restore page)
 *
 * A backup is a timestamped ZIP under backups/ holding one JSON file per
 * public table plus a manifest.json. Restore re-imports those JSON rows with
 * an UPSERT (INSERT … ON CONFLICT DO UPDATE) so records deleted or changed
 * since the backup are recovered while any newer records are left intact —
 * the safe "recover from accidental deletion / corruption" semantics the
 * system documents. A safety snapshot is always taken immediately before a
 * restore so the operation itself is reversible.
 *
 * PostgreSQL / Supabase only (the system's live driver). All work is done
 * through the PDO connection returned by getPgsqlPdoConnection().
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/xlsx_writer.php'; // becXlsxZip()  — dependency-free ZIP builder
require_once __DIR__ . '/xlsx_reader.php'; // becXlsxUnzip() — dependency-free ZIP reader

if (!function_exists('becBackupDir')) {
    /** Absolute path to the backups directory (created on demand). */
    function becBackupDir(): string {
        $dir = dirname(__DIR__) . '/backups';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        return $dir;
    }
}

if (!function_exists('becPublicTableNames')) {
    /** All base tables in the public schema, alphabetical. */
    function becPublicTableNames(PDO $pdo): array {
        $stmt = $pdo->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
             ORDER BY table_name"
        );
        return array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_COLUMN),
            static fn($t) => (bool) preg_match('/^[a-zA-Z0-9_]+$/', (string) $t)
        ));
    }
}

if (!function_exists('becCreateDatabaseBackup')) {
    /**
     * Dump every public table to JSON inside a timestamped ZIP.
     *
     * @param string $prefix  Archive name prefix (default 'bec_db_backup').
     * @param int    $keep    Retain the newest N archives sharing this prefix.
     * @return array{file:string,path:string,tables:int,rows:int,bytes:int,manifest:array}
     */
    function becCreateDatabaseBackup(PDO $pdo, string $prefix = 'bec_db_backup', int $keep = 14): array {
        $dir = becBackupDir();

        $files    = [];
        $manifest = ['created_at' => date('c'), 'prefix' => $prefix, 'tables' => []];
        $totalRows = 0;

        foreach (becPublicTableNames($pdo) as $t) {
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
        $file = $prefix . '_' . date('Ymd_His') . '.zip';
        $out  = $dir . '/' . $file;
        file_put_contents($out, $zipBytes);

        // Rotate: keep only the newest $keep archives that share this prefix.
        $archives = glob($dir . '/' . $prefix . '_*.zip') ?: [];
        rsort($archives);
        foreach (array_slice($archives, $keep) as $old) { @unlink($old); }

        return [
            'file'     => $file,
            'path'     => $out,
            'tables'   => count($manifest['tables']),
            'rows'     => $totalRows,
            'bytes'    => strlen($zipBytes),
            'manifest' => $manifest,
        ];
    }
}

if (!function_exists('becListBackups')) {
    /**
     * List backup archives newest-first with metadata read from each manifest.
     * @return array<int,array{file:string,path:string,size:int,mtime:int,created_at:?string,tables:?int,rows:?int,kind:string}>
     */
    function becListBackups(): array {
        $dir = becBackupDir();
        $out = [];
        foreach (glob($dir . '/*.zip') ?: [] as $path) {
            $file = basename($path);
            $meta = ['created_at' => null, 'tables' => null, 'rows' => null];
            try {
                $entries = becXlsxUnzip($path);
                if (isset($entries['manifest.json'])) {
                    $m = json_decode((string) $entries['manifest.json'], true);
                    if (is_array($m)) {
                        $meta['created_at'] = $m['created_at'] ?? null;
                        $counts = array_filter(($m['tables'] ?? []), 'is_int');
                        $meta['tables'] = count($m['tables'] ?? []);
                        $meta['rows']   = array_sum($counts);
                    }
                }
            } catch (Throwable $e) { /* unreadable / not one of ours — still list it */ }

            $out[] = [
                'file'       => $file,
                'path'       => $path,
                'size'       => (int) (@filesize($path) ?: 0),
                'mtime'      => (int) (@filemtime($path) ?: 0),
                'created_at' => $meta['created_at'],
                'tables'     => $meta['tables'],
                'rows'       => $meta['rows'],
                'kind'       => str_starts_with($file, 'bec_pre_restore') ? 'pre-restore'
                              : (str_starts_with($file, 'bec_imported') ? 'imported' : 'backup'),
            ];
        }
        usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }
}

if (!function_exists('becResolveBackupPath')) {
    /**
     * Map a caller-supplied file name to an absolute path INSIDE backups/.
     * Guards against path traversal — returns null if the name escapes the dir
     * or does not exist.
     */
    function becResolveBackupPath(string $file): ?string {
        $file = basename(trim($file)); // strip any directory components
        if ($file === '' || !preg_match('/\.zip$/i', $file)) { return null; }
        $path = becBackupDir() . '/' . $file;
        $real = realpath($path);
        $base = realpath(becBackupDir());
        if ($real === false || $base === false || !str_starts_with($real, $base)) { return null; }
        return $real;
    }
}

if (!function_exists('becInspectBackupArchive')) {
    /**
     * Read an archive and report exactly what it is, without changing anything.
     *
     * This is the gate for importing a file from outside the server (an archive
     * downloaded on another machine, kept off-site, or handed over on a USB
     * stick). Every check names the specific thing that is wrong, because the
     * failure mode that matters here is a plausible-looking file that is not
     * actually one of ours — a renamed XLSX, a truncated download, an archive
     * from a different system — being accepted and then offered on the restore
     * list as if it were a real snapshot.
     *
     * @param string $path Absolute path to a candidate .zip (may be an upload tmp file).
     * @return array{ok:bool,message:string,tables:array<string,int>,rows:int,
     *               created_at:?string,known:int,unknown:array<int,string>,restorable:int}
     */
    function becInspectBackupArchive(string $path, ?PDO $pdo = null): array {
        $out = ['ok' => false, 'message' => '', 'tables' => [], 'rows' => 0,
                'created_at' => null, 'known' => 0, 'unknown' => [], 'restorable' => 0];

        if (!is_file($path) || !is_readable($path)) {
            $out['message'] = 'The file could not be read from disk.';
            return $out;
        }
        if ((int) filesize($path) === 0) {
            $out['message'] = 'The file is empty (0 bytes) — the download or copy did not complete.';
            return $out;
        }
        // ZIP local-file-header magic. A renamed .xlsx also starts PK, which is
        // why the manifest check below is the one that actually decides.
        $fh = @fopen($path, 'rb');
        $magic = $fh ? (string) fread($fh, 4) : '';
        if ($fh) { fclose($fh); }
        if (strncmp($magic, "PK\x03\x04", 4) !== 0) {
            $out['message'] = 'This is not a ZIP archive (its first bytes are wrong). Upload the .zip exactly as downloaded — do not rename another file to .zip.';
            return $out;
        }

        try {
            $entries = becXlsxUnzip($path);
        } catch (Throwable $e) {
            $out['message'] = 'The ZIP could not be opened — it is corrupt or only partly downloaded.';
            return $out;
        }
        if (!$entries) {
            $out['message'] = 'The ZIP is readable but contains no files.';
            return $out;
        }

        if (!isset($entries['manifest.json'])) {
            $out['message'] = 'This ZIP has no manifest.json, so it is not a BEC database snapshot. Only archives produced by this system (or by scripts\\backup_db.php) can be imported.';
            return $out;
        }
        $manifest = json_decode((string) $entries['manifest.json'], true);
        if (!is_array($manifest) || !isset($manifest['tables']) || !is_array($manifest['tables'])) {
            $out['message'] = 'The manifest inside this ZIP is missing or unreadable, so the archive cannot be trusted.';
            return $out;
        }
        $out['created_at'] = isset($manifest['created_at']) ? (string) $manifest['created_at'] : null;

        // Count what is actually present, not what the manifest claims.
        foreach ($entries as $name => $bytes) {
            if (!preg_match('#^tables/([A-Za-z0-9_]+)\.json$#', (string) $name, $m)) { continue; }
            $rows = json_decode((string) $bytes, true);
            if (!is_array($rows)) {
                $out['message'] = 'Table data for "' . $m[1] . '" inside the archive is not valid JSON — the file is damaged.';
                return $out;
            }
            $out['tables'][$m[1]] = count($rows);
            $out['rows'] += count($rows);
        }
        if (!$out['tables']) {
            $out['message'] = 'The archive has a manifest but no tables/*.json data files, so there is nothing to recover from it.';
            return $out;
        }

        // How much of it this database could actually take. Reported, not
        // enforced: an archive from an older schema is still worth keeping, it
        // just recovers fewer tables.
        if ($pdo !== null) {
            try {
                $present = array_fill_keys(becPublicTableNames($pdo), true);
                $pks     = becTablePrimaryKeys($pdo);
                foreach ($out['tables'] as $t => $n) {
                    if (!isset($present[$t])) { $out['unknown'][] = $t; continue; }
                    $out['known']++;
                    if (!empty($pks[$t]) && $n > 0) { $out['restorable']++; }
                }
            } catch (Throwable $e) { /* schema comparison is advisory only */ }
        }

        $out['ok'] = true;
        $out['message'] = count($out['tables']) . ' table(s), ' . number_format($out['rows']) . ' record(s)';
        return $out;
    }
}

if (!function_exists('becImportBackupArchive')) {
    /**
     * Take a validated archive into backups/ so it appears on the snapshot list
     * and can be restored from.
     *
     * Imports are named bec_imported_* deliberately: becCreateDatabaseBackup()
     * rotates by filename prefix (newest 14 of bec_db_backup_*), so an archive
     * carrying its original name would be deleted by a later backup — exactly
     * the file someone went out of their way to bring back onto the server.
     *
     * @param string $srcPath   Uploaded temp file (already inspected).
     * @param bool   $isUpload  Use move_uploaded_file() rather than copy().
     * @return array{ok:bool,message:string,file:?string}
     */
    function becImportBackupArchive(string $srcPath, bool $isUpload = true): array {
        $dir  = becBackupDir();
        $file = 'bec_imported_' . date('Ymd_His') . '.zip';
        // Two imports inside one second would otherwise collide silently.
        $n = 1;
        while (file_exists($dir . '/' . $file)) { $file = 'bec_imported_' . date('Ymd_His') . '_' . (++$n) . '.zip'; }
        $dest = $dir . '/' . $file;

        $moved = $isUpload ? @move_uploaded_file($srcPath, $dest) : @copy($srcPath, $dest);
        if (!$moved) {
            return ['ok' => false, 'message' => 'The archive could not be written to the backups folder — check that it is writable.', 'file' => null];
        }
        @chmod($dest, 0644);

        // Read it back from its final location: what gets listed and restored is
        // this copy, so this copy is what has to be verifiable.
        $verify = becInspectBackupArchive($dest);
        if (!$verify['ok']) {
            @unlink($dest);
            return ['ok' => false, 'message' => 'The archive did not survive the copy intact (' . $verify['message'] . '). Nothing was imported.', 'file' => null];
        }

        return ['ok' => true, 'message' => $verify['message'], 'file' => $file];
    }
}

if (!function_exists('becTablePrimaryKeys')) {
    /** [table => [pk_col, …]] for every public table that has a primary key. */
    function becTablePrimaryKeys(PDO $pdo): array {
        $sql = "SELECT tc.table_name, kcu.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                  ON tc.constraint_name = kcu.constraint_name
                 AND tc.table_schema = kcu.table_schema
                WHERE tc.constraint_type = 'PRIMARY KEY'
                  AND tc.table_schema = 'public'
                ORDER BY kcu.ordinal_position";
        $out = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['table_name']][] = (string) $r['column_name'];
        }
        return $out;
    }
}

if (!function_exists('becTableDependencyOrder')) {
    /**
     * Topologically sort tables so a parent (referenced) table is restored
     * before any child that references it — lets plain upserts satisfy foreign
     * keys without disabling constraints. Cycles fall back to name order.
     *
     * @param string[] $tables Tables to order (already filtered to those present).
     */
    function becTableDependencyOrder(PDO $pdo, array $tables): array {
        $set = array_fill_keys($tables, true);
        // deps[child] = [parents…]  (self-references ignored)
        $deps = array_fill_keys($tables, []);
        $sql = "SELECT tc.table_name AS child, ccu.table_name AS parent
                FROM information_schema.table_constraints tc
                JOIN information_schema.constraint_column_usage ccu
                  ON tc.constraint_name = ccu.constraint_name
                 AND tc.table_schema = ccu.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY'
                  AND tc.table_schema = 'public'";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $child = (string) $r['child']; $parent = (string) $r['parent'];
            if ($child === $parent) { continue; }
            if (isset($set[$child], $set[$parent])) { $deps[$child][$parent] = true; }
        }

        $ordered = [];
        $done = [];
        $visiting = [];
        $visit = function (string $t) use (&$visit, &$deps, &$done, &$visiting, &$ordered) {
            if (isset($done[$t]) || isset($visiting[$t])) { return; }
            $visiting[$t] = true;
            foreach (array_keys($deps[$t] ?? []) as $parent) { $visit($parent); }
            unset($visiting[$t]);
            $done[$t] = true;
            $ordered[] = $t;
        };
        foreach ($tables as $t) { $visit($t); }
        return $ordered;
    }
}

if (!function_exists('becRestoreFromBackup')) {
    /**
     * Recover records from a backup archive via UPSERT, inside one transaction.
     * A safety snapshot is taken first. Existing rows newer than the backup are
     * updated back to the backed-up values; rows missing from the DB are
     * re-inserted. Nothing is truncated or deleted.
     *
     * @param string $zipPath Absolute path to a backup ZIP (validate via becResolveBackupPath first).
     * @param string[]|null $onlyTables Restrict restore to these tables, or null for all in the archive.
     * @return array{ok:bool,message:string,restored:array<string,int>,skipped:array<string,string>,safety:?string}
     */
    function becRestoreFromBackup(PDO $pdo, string $zipPath, ?array $onlyTables = null): array {
        $result = ['ok' => false, 'message' => '', 'restored' => [], 'skipped' => [], 'safety' => null];

        $entries = becXlsxUnzip($zipPath);
        if (!$entries) {
            $result['message'] = 'The backup archive could not be read or is empty.';
            return $result;
        }

        // Collect table => rows from the archive's tables/*.json entries.
        $backupTables = [];
        foreach ($entries as $name => $bytes) {
            if (!preg_match('#^tables/([A-Za-z0-9_]+)\.json$#', (string) $name, $m)) { continue; }
            $rows = json_decode((string) $bytes, true);
            if (is_array($rows)) { $backupTables[$m[1]] = $rows; }
        }
        if (!$backupTables) {
            $result['message'] = 'No table data was found inside the backup archive.';
            return $result;
        }

        $present = array_fill_keys(becPublicTableNames($pdo), true);
        $pks     = becTablePrimaryKeys($pdo);

        // Decide which tables we can restore.
        $targets = [];
        foreach ($backupTables as $t => $rows) {
            if ($onlyTables !== null && !in_array($t, $onlyTables, true)) { continue; }
            if (!isset($present[$t]))       { $result['skipped'][$t] = 'table no longer exists'; continue; }
            if (empty($pks[$t]))            { $result['skipped'][$t] = 'no primary key (cannot upsert safely)'; continue; }
            if (!is_array($rows) || !$rows) { $result['skipped'][$t] = 'no rows in backup'; continue; }
            $targets[$t] = $rows;
        }
        if (!$targets) {
            $result['message'] = 'Nothing restorable in this archive for the selected scope.';
            return $result;
        }

        // Safety snapshot BEFORE touching anything — restore is itself reversible.
        try {
            $safety = becCreateDatabaseBackup($pdo, 'bec_pre_restore', 8);
            $result['safety'] = $safety['file'];
        } catch (Throwable $e) {
            $result['message'] = 'Aborted: could not take a safety snapshot before restoring (' . $e->getMessage() . ').';
            return $result;
        }

        $order = becTableDependencyOrder($pdo, array_keys($targets));

        try {
            $pdo->beginTransaction();
            foreach ($order as $t) {
                $rows = $targets[$t];
                $tableCols = array_keys(getTableColumns($t));          // live schema
                $cols = array_values(array_intersect(array_keys($rows[0]), $tableCols));
                if (!$cols) { $result['skipped'][$t] = 'no matching columns'; continue; }

                $pk = array_values(array_intersect($pks[$t], $cols));
                if (!$pk) { $result['skipped'][$t] = 'primary key column missing from backup'; continue; }

                $updateCols = array_values(array_diff($cols, $pk));
                $ph  = array_map(static fn($c) => ':' . $c, $cols);
                $sql = 'INSERT INTO public."' . $t . '" ("' . implode('","', $cols) . '") VALUES (' . implode(',', $ph) . ') '
                     . 'ON CONFLICT ("' . implode('","', $pk) . '") ';
                if ($updateCols) {
                    $sets = array_map(static fn($c) => '"' . $c . '" = EXCLUDED."' . $c . '"', $updateCols);
                    $sql .= 'DO UPDATE SET ' . implode(', ', $sets);
                } else {
                    $sql .= 'DO NOTHING';
                }
                $stmt = $pdo->prepare($sql);

                $n = 0;
                foreach ($rows as $row) {
                    $params = [];
                    foreach ($cols as $c) {
                        $v = $row[$c] ?? null;
                        if (is_array($v))      { $v = json_encode($v); }   // jsonb columns
                        elseif (is_bool($v))   { $v = $v ? 'true' : 'false'; }
                        $params[$c] = $v;
                    }
                    $stmt->execute($params);
                    $n++;
                }
                $result['restored'][$t] = $n;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $result['message'] = 'Restore failed and was rolled back — no changes were applied. ' . $e->getMessage()
                               . ' (A safety snapshot "' . ($result['safety'] ?? '?') . '" is available.)';
            $result['restored'] = [];
            return $result;
        }

        $rowsRestored = array_sum($result['restored']);
        $result['ok'] = true;
        $result['message'] = 'Recovered ' . number_format($rowsRestored) . ' record(s) across '
                           . count($result['restored']) . ' table(s).';
        return $result;
    }
}
