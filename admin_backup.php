<?php
/**
 * admin_backup.php — Backup & Data Recovery (admin only).
 *
 * Surfaces the database backup engine the system runs nightly and adds the
 * recovery half documented in the manuscript: run an on-demand backup,
 * download any archive, and restore/recover records from a chosen backup if
 * data is accidentally deleted or corrupted. Restore is an UPSERT that never
 * truncates, and always takes a safety snapshot first.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/backup_restore.php';

requireRole('admin');

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['fullname'] ?? 'Administrator';

function be($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function bdt($v): string { $t = is_numeric($v) ? (int)$v : strtotime((string)$v); return $t ? date('M d, Y · h:i A', $t) : '—'; }
function bsize($b): string { $b = (int)$b; if ($b < 1024) return $b . ' B'; if ($b < 1048576) return round($b/1024) . ' KB'; return round($b/1048576, 1) . ' MB'; }

/* ── Download an archive (read-only, streamed before any HTML) ── */
if (isset($_GET['dl'])) {
    $path = becResolveBackupPath((string)$_GET['dl']);
    if ($path === null) { http_response_code(404); exit('Backup not found.'); }
    logActivity($admin_id, 'backup.download', 'Downloaded backup ' . basename($path));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit();
}

/* ── State-changing actions (POST + CSRF, then redirect) ── */
$flash = $_SESSION['backup_flash'] ?? null;
unset($_SESSION['backup_flash']);

/* An upload larger than post_max_size arrives with $_POST and $_FILES both
   empty — including the CSRF token — so the generic "security token invalid"
   would be the message for what is really a file-size problem. Catch it first
   and say what actually happened. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $_SESSION['backup_flash'] = ['err',
        'That file is larger than this server accepts in one request (post_max_size = '
        . ini_get('post_max_size') . ', upload_max_filesize = ' . ini_get('upload_max_filesize')
        . '). Raise those limits in php.ini, or copy the .zip straight into the backups\\ folder.'];
    header('Location: admin_backup.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'backup') {
        try {
            $pdo = getPgsqlPdoConnection();
            $r = becCreateDatabaseBackup($pdo);
            logActivity($admin_id, 'backup.create', 'Created backup ' . $r['file'] . ' (' . $r['tables'] . ' tables, ' . $r['rows'] . ' rows)');
            $flash = ['ok', 'Backup created: ' . $r['file'] . ' — ' . $r['tables'] . ' tables, ' . number_format($r['rows']) . ' records, ' . bsize($r['bytes']) . '.'];
        } catch (Throwable $e) {
            // The driver's own message can carry the connection string and file
            // paths — that belongs in the server log, not on a screen someone
            // may be projecting during a demo.
            error_log('backup.create failed: ' . $e->getMessage());
            $flash = ['err', 'Backup failed. The details were written to the server log.'];
        }
    } elseif ($action === 'delete') {
        $path = becResolveBackupPath((string)($_POST['file'] ?? ''));
        if ($path === null) {
            $flash = ['err', 'That backup could not be found.'];
        } else {
            @unlink($path);
            logActivity($admin_id, 'backup.delete', 'Deleted backup ' . basename($path));
            $flash = ['ok', 'Deleted backup ' . basename($path) . '.'];
        }
    } elseif ($action === 'import') {
        /* Bring an archive back onto the server — the other half of Download.
           Nothing is written into backups/ until the file has been proven to be
           a readable BEC snapshot, and the copy that lands is re-read and
           verified before it is reported as imported. */
        $f = $_FILES['archive'] ?? null;
        $errCode = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!$f || $errCode !== UPLOAD_ERR_OK) {
            $msgs = [
                UPLOAD_ERR_INI_SIZE   => 'The file is larger than upload_max_filesize (' . ini_get('upload_max_filesize') . ').',
                UPLOAD_ERR_FORM_SIZE  => 'The file is larger than this form allows.',
                UPLOAD_ERR_PARTIAL    => 'The upload was interrupted and only part of the file arrived.',
                UPLOAD_ERR_NO_FILE    => 'No file was chosen.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads.',
                UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            ];
            $flash = ['err', 'Import failed. ' . ($msgs[$errCode] ?? 'The upload did not complete.')];
        } elseif (!is_uploaded_file((string)$f['tmp_name'])) {
            $flash = ['err', 'Import rejected: that file did not arrive as an upload.'];
        } elseif (!preg_match('/\.zip$/i', (string)$f['name'])) {
            $flash = ['err', 'Import rejected: a backup archive must be a .zip file. You chose "' . basename((string)$f['name']) . '".'];
        } else {
            $pdo = null;
            try { $pdo = getPgsqlPdoConnection(); } catch (Throwable $e) { /* inspect without the schema comparison */ }
            $chk = becInspectBackupArchive((string)$f['tmp_name'], $pdo);
            if (!$chk['ok']) {
                $flash = ['err', 'Import rejected: ' . $chk['message']];
                logActivity($admin_id, 'backup.import_reject', 'Rejected upload ' . basename((string)$f['name']) . ': ' . $chk['message']);
            } else {
                $res = becImportBackupArchive((string)$f['tmp_name'], true);
                if (!$res['ok']) {
                    $flash = ['err', $res['message']];
                } else {
                    $note = 'Imported ' . basename((string)$f['name']) . ' as ' . $res['file'] . ' — ' . $chk['message'];
                    // Say plainly how much of it this database can actually take.
                    $scope = $chk['known'] > 0
                        ? ' ' . $chk['restorable'] . ' of ' . count($chk['tables']) . ' table(s) can be recovered into the current database.'
                        : ' None of its tables match the current database schema.';
                    if ($chk['unknown']) {
                        $scope .= ' Not in this schema: ' . implode(', ', array_slice($chk['unknown'], 0, 6))
                                . (count($chk['unknown']) > 6 ? ' …' : '') . '.';
                    }
                    logActivity($admin_id, 'backup.import', $note);
                    $flash = ['ok', 'Imported as ' . $res['file'] . ' — ' . $chk['message']
                                  . ($chk['created_at'] ? ', taken ' . bdt($chk['created_at']) : '') . '.' . $scope
                                  . ' It is now on the snapshot list and can be recovered from.'];
                }
            }
        }
    } elseif ($action === 'restore') {
        $path = becResolveBackupPath((string)($_POST['file'] ?? ''));
        $confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));
        if ($path === null) {
            $flash = ['err', 'That backup could not be found.'];
        } elseif ($confirm !== 'RESTORE') {
            $flash = ['err', 'Restore not confirmed — type RESTORE to proceed.'];
        } else {
            try {
                $pdo = getPgsqlPdoConnection();
                $res = becRestoreFromBackup($pdo, $path);
                if ($res['ok']) {
                    logActivity($admin_id, 'backup.restore', 'Restored from ' . basename($path) . ' — ' . $res['message'] . ' (safety: ' . ($res['safety'] ?? 'n/a') . ')');
                    $detail = [];
                    foreach ($res['restored'] as $t => $n) { $detail[] = $t . ' (' . $n . ')'; }
                    // A restore that recovered every row but could not advance
                    // the id sequences is not a green tick — the next insert into
                    // those tables can still fail. Report it as what it is.
                    $tone  = empty($res['sequences']) ? 'ok' : 'err';
                    $flash = [$tone, $res['message'] . ' Safety snapshot: ' . ($res['safety'] ?? 'n/a') . '. Tables: ' . (implode(', ', $detail) ?: 'none') . '.'];
                } else {
                    logActivity($admin_id, 'backup.restore_fail', 'Restore from ' . basename($path) . ' failed: ' . $res['message']);
                    $flash = ['err', $res['message']];
                }
            } catch (Throwable $e) {
                error_log('backup.restore failed: ' . $e->getMessage());
                logActivity($admin_id, 'backup.restore_fail', 'Restore from ' . basename($path) . ' failed');
                $flash = ['err', 'Restore failed. The details were written to the server log.'];
            }
        }
    }

    $_SESSION['backup_flash'] = $flash;
    header('Location: admin_backup.php');
    exit();
}

/* ── Page data ── */
$backups = becListBackups();
$totalBytes = array_sum(array_column($backups, 'size'));
$lastRun = $backups[0]['mtime'] ?? 0;
$scheduledLikely = false;
foreach ($backups as $b) { if ($b['kind'] === 'backup') { $scheduledLikely = true; break; } }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php echo csrf_meta(); ?>
<title>Backup &amp; Recovery — BEC Admin</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>

  :root{ --maroon:#7B1D1D; --maroon-d:#4A0E0E; --gold:#C9960C; --ink:#1A0808; --ink2:#5C3838; --ink3:#9C7A7A; --paper:#F4EFE6; --surface:#fff; --border:#E5D9C6; --field:#FAF7F0; --danger:var(--bad-tx); --success:var(--ok-tx); }
  *{margin:0;padding:0;box-sizing:border-box;font-family:'DM Sans',system-ui,sans-serif;}
  body{background:var(--paper);color:var(--ink);min-height:100vh;}
  .topbar a.back{color:var(--ink2);text-decoration:none;font-size:.78rem;font-weight:600;
    background:var(--surface);border:1px solid var(--border);padding:7px 14px;border-radius:8px;}
  .topbar a.back:hover{background:var(--maroon);border-color:var(--maroon);color:#fff;}
  .wrap{max-width:none;margin:0;padding:1.5rem 1.75rem 4rem;}
  .head h2 .h2-ic{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.14));color:var(--maroon);display:inline-flex;align-items:center;justify-content:center;font-size:.9rem;}
/* .flash lives in assets/css/admin-shell.css — one definition for every admin page. */
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin:18px 0;}
  .stat{position:relative;overflow:hidden;display:flex;gap:14px;align-items:center;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:16px 18px;box-shadow:0 1px 2px rgba(28,16,8,.04);transition:transform .26s cubic-bezier(.4,0,.2,1),box-shadow .26s;}
  .stat::before{content:'';position:absolute;top:-24px;right:-24px;width:88px;height:88px;border-radius:50%;background:var(--sk,var(--maroon));opacity:.05;transition:transform .3s,opacity .3s;}
  .stat::after{content:'';position:absolute;left:0;bottom:0;width:100%;height:3px;background:var(--sk,var(--maroon));transform:scaleX(0);transform-origin:left;transition:transform .32s;}
  .stat:hover{transform:none;box-shadow:0 12px 28px rgba(28,16,8,.1);}
  .stat:hover::before{transform:none;opacity:.09;}
  .stat:hover::after{transform:scaleX(1);}
  .stat .s-ic{position:relative;z-index:1;width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0;box-shadow:none;transition:transform .26s;}
  .stat:hover .s-ic{transform:none;}
  .stat .s-tx{position:relative;z-index:1;min-width:0;}
  .stat.s-m{--sk:var(--maroon);} .stat.s-m .s-ic{background:rgba(123,29,29,.1);color:var(--maroon);}
  .stat.s-a{--sk:#1D4ED8;} .stat.s-a .s-ic{background:#E8EFFF;color:#1D4ED8;}
  .stat.s-g{--sk:var(--success);} .stat.s-g .s-ic{background:#EEF7F0;color:var(--success);}
  .stat .lbl{font-size:.66rem;text-transform:uppercase;letter-spacing:.6px;color:var(--ink3);font-weight:700;}
  .stat .val{font-family:'Fraunces',serif;font-size:1.4rem;margin-top:4px;color:var(--ink);line-height:1.1;}
  /* Two of the three tiles hold a phrase, not a number, and were shrunk with an
     inline font-size — so the row rendered at 1.4rem, 1rem, 1rem and read
     ragged. One modifier instead, and the baselines line up. */
  .stat .val.txt{font-size:1rem;line-height:1.35;}
  .stat .sub{font-size:.72rem;color:var(--ink3);margin-top:2px;}
  /* Back up / Import / Recover are peer actions, so they sit in one row above
     the list rather than stacked in a narrow right-hand column. With the import
     card added, that column ran far taller than the table beside it and left a
     column of white space down the page. */
  .actions{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;align-items:start;margin-bottom:18px;}
  @media(max-width:1100px){ .actions{grid-template-columns:1fr;} }
  .card.act{display:flex;flex-direction:column;}
  .card.act .cb{display:flex;flex-direction:column;flex:1;}
  /* Push each card's button to the bottom so the three line up across the row
     however much text sits above them. */
  .card.act .cb form{margin-top:auto;}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:0 1px 2px rgba(28,16,8,.04);overflow:hidden;}
  .card .ch{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;}
  /* Face and size come from admin-shell.css, where every panel/card title on
     the admin surface is now declared once. */
  .card .ch .ci{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.14));display:flex;align-items:center;justify-content:center;color:var(--maroon);font-size:.92rem;flex-shrink:0;}
  .card .cb{padding:16px 18px;}
  .card .cb p{font-size:.83rem;color:var(--ink2);line-height:1.55;margin-bottom:12px;}
  .btn{padding:.6rem 1.15rem;border:1px solid var(--maroon);background:var(--maroon);color:#fff;font-size:.85rem;}
  .btn:hover{background:#611616;transform:none;box-shadow:0 6px 16px rgba(74,14,14,.22);}
  .btn:active{transform:translateY(0);}
  .btn.ghost{background:#fff;color:var(--ink2);border-color:var(--border);}
  .btn.ghost:hover{background:var(--field);color:var(--maroon);border-color:var(--maroon);box-shadow:0 3px 10px rgba(74,14,14,.1);}
  .btn.danger{background:var(--danger);border-color:var(--danger);}
  .btn.danger:hover{background:#8A1C1C;box-shadow:0 6px 16px rgba(180,35,24,.24);}
  .btn.sm{padding:.4rem .7rem;font-size:.76rem;border-radius:8px;}
  table{width:100%;border-collapse:collapse;font-size:.82rem;}
  th{text-align:left;background:var(--field);color:var(--ink2);padding:10px 14px;font-size:.66rem;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);}
  td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
  tr:hover td{background:#FDFBF7;}
  .fname{font-family:'Outfit',monospace;font-weight:600;font-size:.8rem;color:var(--ink);word-break:break-all;}
  .tag{display:inline-block;padding:.16rem .5rem;border-radius:12px;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
  .tag.backup{background:rgba(123,29,29,.08);color:var(--maroon);}
  .tag.pre-restore{background:#FFF7E6;color:#9A6A00;}
  .tag.imported{background:#EAF3FF;color:#1D4E89;}
  /* Import picker */
  input[type=file]{width:100%;padding:.5rem .6rem;border:1.5px dashed var(--border);border-radius:9px;background:#fff;
    font-size:.8rem;font-family:'DM Sans',sans-serif;margin-bottom:10px;cursor:pointer;}
  input[type=file]:hover{border-color:var(--maroon);}
  .imp-pick{background:#F4F9F5;border:1px solid #CFE6D6;color:#1C5C2E;border-radius:9px;
    padding:8px 11px;font-size:.78rem;line-height:1.5;margin-bottom:10px;}
  .imp-pick.bad{background:#FDECEC;border-color:#F3C0C0;color:#8A1C1C;}
  .rowact{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;}
  .empty{padding:44px 20px;}
  .note{background:var(--field);border:1px solid var(--border);border-radius:10px;padding:12px 14px;font-size:.78rem;color:var(--ink2);line-height:1.55;margin-top:14px;}
  .note code{background:#efe9e0;padding:1px 6px;border-radius:5px;font-size:.74rem;}
  select,input[type=text]{width:100%;padding:.55rem .7rem;border:1.5px solid var(--border);border-radius:9px;background:#fff;font-size:.84rem;font-family:'DM Sans',sans-serif;margin-bottom:10px;}
  select:focus,input:focus{outline:none;border-color:var(--maroon);box-shadow:0 0 0 3px rgba(123,29,29,.08);}
  label.fl{display:block;font-size:.72rem;font-weight:700;color:var(--ink2);margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px;}
  .warn{background:#FDECEC;border:1px solid #F3C0C0;color:#8A1C1C;border-radius:9px;padding:10px 12px;font-size:.78rem;line-height:1.5;margin-bottom:12px;}
  /* Persistent admin sidebar (canonical) */
  :root{ --sb:262px; --m1:#2D0505; --g2:#D4A017; --g3:#F0C040; --r1:8px; --r2:12px; }
  /* sidebar styling lives in assets/css/admin-shell.css */
  .topbar{margin-left:var(--sb);}
  .wrap{margin:0 0 0 var(--sb);max-width:none;}
  .topbar,.wrap{transition:margin-left .26s ease;}
  body.becSbHide .topbar, body.becSbHide .wrap{margin-left:0 !important;}
  @media(max-width:860px){ .sb{transform:translateX(-100%);} .topbar,.wrap{margin-left:0;} }
</style>
</head>
<body>
  <?php $activeNav = 'backup'; require __DIR__ . '/includes/admin_sidebar.php'; ?>
  <div class="topbar">
    <div>
      <div class="pg-title">Backup &amp; Recovery</div>
      <div class="bc">
        <a href="admin_dashboard.php"><i class="fas fa-home"></i></a>
        <i class="fas fa-chevron-right"></i><span>Backup &amp; Recovery</span>
      </div>
    </div>
  </div>

  <div class="wrap">
    <div class="head">
      <h2><span class="h2-ic"><i class="fas fa-shield-halved"></i></span> Backup &amp; Data Recovery</h2>
      <p>Protects the system against data loss. An automated daily backup produces a compressed snapshot of every database table; you can also back up on demand, download any snapshot, and recover records from one if data is accidentally deleted or corrupted.</p>
    </div>

    <?php if ($flash): ?>
      <div class="flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>">
        <i class="fas <?php echo $flash[0] === 'ok' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
        <div><?php echo be($flash[1]); ?></div>
      </div>
    <?php endif; ?>

    <div class="stats">
      <div class="stat s-m"><div class="s-ic"><i class="fas fa-database"></i></div><div class="s-tx"><div class="lbl">Snapshots stored</div><div class="val"><?php echo count($backups); ?></div><div class="sub"><?php echo bsize($totalBytes); ?> on disk</div></div></div>
      <div class="stat s-a"><div class="s-ic"><i class="fas fa-clock-rotate-left"></i></div><div class="s-tx"><div class="lbl">Latest snapshot</div><div class="val txt"><?php echo $lastRun ? bdt($lastRun) : '—'; ?></div><div class="sub"><?php echo $lastRun ? 'Most recent backup' : 'No backups yet'; ?></div></div></div>
      <div class="stat s-g"><div class="s-ic"><i class="fas fa-calendar-check"></i></div><div class="s-tx"><div class="lbl">Automated schedule</div><div class="val txt">Daily</div><div class="sub">Windows Task Scheduler</div></div></div>
    </div>

      <!-- Actions -->
      <div class="actions">
        <div class="card act">
          <div class="ch"><div class="ci"><i class="fas fa-cloud-arrow-up"></i></div><h3>Back Up Now</h3></div>
          <div class="cb">
            <p>Create an immediate snapshot of the entire database. Snapshots are compressed, and the newest 14 are kept automatically.</p>
            <form method="post">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="backup">
              <button class="btn" type="submit"><i class="fas fa-cloud-arrow-up"></i> Back Up Now</button>
            </form>
          </div>
        </div>

        <!-- The other half of Download: an archive kept off-site, or made on a
             different machine, has to be able to come back. -->
        <div class="card act">
          <div class="ch"><div class="ci"><i class="fas fa-file-import"></i></div><h3>Import a Snapshot</h3></div>
          <div class="cb">
            <p>Upload a backup <code style="font-family:inherit;">.zip</code> produced by this system — one you downloaded earlier, or one taken on another machine. It is checked before it is accepted and then joins the list above, ready to recover from.</p>
            <form method="post" enctype="multipart/form-data" id="importForm">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="import">
              <label class="fl" for="archive">Backup archive (.zip)</label>
              <input type="file" name="archive" id="archive" accept=".zip,application/zip" required>
              <div class="imp-pick" id="impPick" hidden></div>
              <button class="btn" type="submit"><i class="fas fa-file-import"></i> Import Snapshot</button>
            </form>
            <div class="warn" style="margin-top:12px;">
              <i class="fas fa-circle-info"></i> Importing only stores the archive — <strong>no data is changed</strong>.
              Recovering records from it is the separate, confirmed step below.
              Server limit: <?php echo be(ini_get('upload_max_filesize')); ?> per file.
            </div>
          </div>
        </div>

        <div class="card act">
          <div class="ch"><div class="ci"><i class="fas fa-clock-rotate-left"></i></div><h3>Recover Data</h3></div>
          <div class="cb">
            <?php if (!$backups): ?>
              <p>No snapshot is available to recover from yet.</p>
            <?php else: ?>
            <p>Restore records from a chosen snapshot. Missing records are recovered and changed records are reverted to the backed-up version; <strong>records newer than the snapshot are kept</strong> and nothing is deleted.</p>
            <div class="warn"><i class="fas fa-shield-halved"></i> A safety snapshot of the current database is taken automatically <em>before</em> the restore, so this action is reversible. The whole restore runs in one transaction — if anything fails, no changes are applied.</div>
            <form method="post" onsubmit="return this.confirm.value.trim().toUpperCase()==='RESTORE' || (alert('Type RESTORE to confirm.'),false);">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="restore">
              <label class="fl">Snapshot to recover from</label>
              <select name="file" required>
                <?php foreach ($backups as $b): ?>
                <option value="<?php echo be($b['file']); ?>"><?php echo be($b['file']); ?> — <?php echo bdt($b['created_at'] ?: $b['mtime']); ?><?php echo $b['rows'] !== null ? ' (' . number_format((int)$b['rows']) . ' rows)' : ''; ?></option>
                <?php endforeach; ?>
              </select>
              <label class="fl">Type <code style="font-family:inherit;">RESTORE</code> to confirm</label>
              <input type="text" name="confirm" placeholder="RESTORE" autocomplete="off" required>
              <button class="btn danger" type="submit"><i class="fas fa-clock-rotate-left"></i> Recover Records</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
    </div>

    <!-- Snapshots list -->
    <div class="card">
        <div class="ch"><div class="ci"><i class="fas fa-database"></i></div><h3>Snapshots</h3></div>
        <?php if (!$backups): ?>
          <div class="empty"><i class="fas fa-box-open"></i><strong>No backups yet.</strong><div>Use “Back up now” to create the first snapshot.</div></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
          <thead><tr><th>Archive</th><th>Created</th><th>Contents</th><th>Size</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($backups as $b): ?>
            <tr>
              <td><span class="fname"><?php echo be($b['file']); ?></span><br><span class="tag <?php echo be($b['kind']); ?>"><?php
                echo $b['kind'] === 'pre-restore' ? 'pre-restore safety' : ($b['kind'] === 'imported' ? 'imported' : 'backup');
              ?></span></td>
              <td style="white-space:nowrap;"><?php echo bdt($b['created_at'] ?: $b['mtime']); ?></td>
              <td style="white-space:nowrap;"><?php echo $b['tables'] !== null ? (int)$b['tables'] . ' tables · ' . number_format((int)$b['rows']) . ' rows' : '—'; ?></td>
              <td style="white-space:nowrap;"><?php echo bsize($b['size']); ?></td>
              <td>
                <div class="rowact">
                  <a class="btn ghost sm" href="admin_backup.php?dl=<?php echo urlencode($b['file']); ?>"><i class="fas fa-download"></i></a>
                  <form method="post" onsubmit="return confirm('Delete this backup permanently?\n<?php echo be($b['file']); ?>');" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="file" value="<?php echo be($b['file']); ?>">
                    <button class="btn ghost sm" type="submit" title="Delete"><i class="fas fa-trash"></i></button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>

    <div class="note">
      <strong>How the automated backup runs.</strong> A Windows Task Scheduler job runs <code>php scripts\backup_db.php</code> nightly, producing rotating compressed archives of every database table (the same snapshots listed above), then rotating logs and flushing the mail outbox. To (re)create the scheduled task, run in an elevated PowerShell:
      <br><br>
      <code>schtasks /Create /SC DAILY /ST 01:30 /TN "BEC PMO DB Backup" /TR "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\bec-pmo\scripts\backup_db.php\""</code>
    </div>
  </div>
<script src="assets/sidebar_autohide.js" defer></script>
<script>
/* Import picker: say what was chosen before it is sent, and refuse the two
   mistakes that are obvious without the server — the wrong kind of file, and an
   empty one. Everything that actually decides whether an archive is a genuine
   BEC snapshot is checked server-side; this only saves a pointless round trip. */
(function () {
  var input = document.getElementById('archive');
  if (!input) { return; }
  var note = document.getElementById('impPick');
  var form = document.getElementById('importForm');
  var MAX = <?php
      // The smaller of the two PHP limits, in bytes — the real ceiling.
      $toB = static function ($v) {
          $v = trim((string)$v); $n = (float)$v; $u = strtolower(substr($v, -1));
          if ($u === 'g') { $n *= 1073741824; } elseif ($u === 'm') { $n *= 1048576; } elseif ($u === 'k') { $n *= 1024; }
          return (int)$n;
      };
      $lim = min($toB(ini_get('upload_max_filesize')), $toB(ini_get('post_max_size')));
      echo $lim > 0 ? $lim : 0;
  ?>;

  function human(b) {
    if (b < 1024) { return b + ' B'; }
    if (b < 1048576) { return Math.round(b / 1024) + ' KB'; }
    return (b / 1048576).toFixed(1) + ' MB';
  }

  function check() {
    var f = input.files && input.files[0];
    if (!f) { note.hidden = true; return true; }
    var bad = '';
    if (!/\.zip$/i.test(f.name)) { bad = 'Not a .zip file. A backup archive is the .zip exactly as it was downloaded.'; }
    else if (f.size === 0)       { bad = 'This file is empty (0 bytes) — the copy or download did not finish.'; }
    else if (MAX && f.size > MAX) { bad = 'This file is ' + human(f.size) + ', over the server limit of ' + human(MAX) + '.'; }
    note.hidden = false;
    note.className = 'imp-pick' + (bad ? ' bad' : '');
    note.textContent = bad
      ? f.name + ' — ' + bad
      : 'Ready to import: ' + f.name + ' (' + human(f.size) + '). It will be checked on the server before anything is stored.';
    return !bad;
  }

  input.addEventListener('change', check);
  form.addEventListener('submit', function (ev) { if (!check()) { ev.preventDefault(); } });
})();
</script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>
