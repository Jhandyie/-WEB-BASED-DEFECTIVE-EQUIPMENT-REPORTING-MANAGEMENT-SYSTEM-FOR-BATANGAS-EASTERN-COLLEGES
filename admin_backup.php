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
                    $flash = ['ok', $res['message'] . ' Safety snapshot: ' . ($res['safety'] ?? 'n/a') . '. Tables: ' . (implode(', ', $detail) ?: 'none') . '.'];
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

  :root{ --maroon:#7B1D1D; --maroon-d:#4A0E0E; --gold:#C9960C; --ink:#1A0808; --ink2:#5C3838; --ink3:#9C7A7A; --paper:#F4EFE6; --surface:#fff; --border:#E5D9C6; --field:#FAF7F0; --danger:#B42318; --success:#1A7A33; }
  *{margin:0;padding:0;box-sizing:border-box;font-family:'DM Sans',system-ui,sans-serif;}
  body{background:var(--paper);color:var(--ink);min-height:100vh;}
  .topbar a.back{color:var(--ink2);text-decoration:none;font-size:.78rem;font-weight:600;
    background:var(--surface);border:1px solid var(--border);padding:7px 14px;border-radius:8px;}
  .topbar a.back:hover{background:var(--maroon);border-color:var(--maroon);color:#fff;}
  .wrap{max-width:none;margin:0;padding:24px 28px 60px;}
  .head h2 .h2-ic{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.14));color:var(--maroon);display:inline-flex;align-items:center;justify-content:center;font-size:.9rem;}
  .flash{margin:16px 0;padding:12px 16px;border-radius:10px;font-size:.86rem;display:flex;gap:10px;align-items:flex-start;line-height:1.5;}
  .flash.ok{background:#EEF7F0;border:1px solid #BFE3C8;color:#1A5A2A;}
  .flash.err{background:#FDECEC;border:1px solid #F3C0C0;color:#8A1C1C;}
  .flash i{margin-top:2px;}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin:18px 0;}
  .stat{position:relative;overflow:hidden;display:flex;gap:14px;align-items:center;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:16px 18px;box-shadow:0 1px 2px rgba(28,16,8,.04);transition:transform .26s cubic-bezier(.4,0,.2,1),box-shadow .26s;}
  .stat::before{content:'';position:absolute;top:-24px;right:-24px;width:88px;height:88px;border-radius:50%;background:var(--sk,var(--maroon));opacity:.05;transition:transform .3s,opacity .3s;}
  .stat::after{content:'';position:absolute;left:0;bottom:0;width:100%;height:3px;background:var(--sk,var(--maroon));transform:scaleX(0);transform-origin:left;transition:transform .32s;}
  .stat:hover{transform:translateY(-4px);box-shadow:0 12px 28px rgba(28,16,8,.1);}
  .stat:hover::before{transform:scale(1.5);opacity:.09;}
  .stat:hover::after{transform:scaleX(1);}
  .stat .s-ic{position:relative;z-index:1;width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0;box-shadow:0 3px 0 rgba(0,0,0,.06);transition:transform .26s;}
  .stat:hover .s-ic{transform:rotate(-8deg) scale(1.1);}
  .stat .s-tx{position:relative;z-index:1;min-width:0;}
  .stat.s-m{--sk:var(--maroon);} .stat.s-m .s-ic{background:rgba(123,29,29,.1);color:var(--maroon);}
  .stat.s-a{--sk:#1D4ED8;} .stat.s-a .s-ic{background:#E8EFFF;color:#1D4ED8;}
  .stat.s-g{--sk:var(--success);} .stat.s-g .s-ic{background:#EEF7F0;color:var(--success);}
  .stat .lbl{font-size:.66rem;text-transform:uppercase;letter-spacing:.6px;color:var(--ink3);font-weight:700;}
  .stat .val{font-family:'Fraunces',serif;font-size:1.4rem;margin-top:4px;color:var(--ink);line-height:1.1;}
  .stat .sub{font-size:.72rem;color:var(--ink3);margin-top:2px;}
  .grid{display:grid;grid-template-columns:1.4fr 1fr;gap:18px;align-items:start;}
  @media(max-width:960px){ .grid{grid-template-columns:1fr;} }
  .card{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:0 1px 2px rgba(28,16,8,.04);overflow:hidden;}
  .card .ch{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;}
  .card .ch h3{font-family:'Fraunces',serif;font-size:1.05rem;}
  .card .ch .ci{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.14));display:flex;align-items:center;justify-content:center;color:var(--maroon);font-size:.92rem;flex-shrink:0;}
  .card .cb{padding:16px 18px;}
  .card .cb p{font-size:.83rem;color:var(--ink2);line-height:1.55;margin-bottom:12px;}
  .btn{display:inline-flex;align-items:center;gap:8px;padding:.6rem 1.15rem;border-radius:10px;border:1px solid var(--maroon);background:var(--maroon);color:#fff;font-size:.85rem;font-weight:600;cursor:pointer;text-decoration:none;font-family:'DM Sans',sans-serif;transition:transform .15s,background .15s,box-shadow .15s;}
  .btn:hover{background:#611616;transform:translateY(-1px);box-shadow:0 6px 16px rgba(74,14,14,.22);}
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
  .rowact{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;}
  .empty{text-align:center;padding:44px 20px;color:var(--ink3);}
  .empty i{font-size:2rem;color:var(--border);display:block;margin-bottom:10px;}
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
    <a class="back" href="admin_dashboard.php"><i class="fas fa-arrow-left"></i> Dashboard</a>
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
      <div class="stat s-a"><div class="s-ic"><i class="fas fa-clock-rotate-left"></i></div><div class="s-tx"><div class="lbl">Latest snapshot</div><div class="val" style="font-size:1rem;"><?php echo $lastRun ? bdt($lastRun) : '—'; ?></div><div class="sub"><?php echo $lastRun ? 'most recent backup' : 'no backups yet'; ?></div></div></div>
      <div class="stat s-g"><div class="s-ic"><i class="fas fa-calendar-check"></i></div><div class="s-tx"><div class="lbl">Automated schedule</div><div class="val" style="font-size:1rem;">Daily</div><div class="sub">Windows Task Scheduler</div></div></div>
    </div>

    <div class="grid">
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
              <td><span class="fname"><?php echo be($b['file']); ?></span><br><span class="tag <?php echo be($b['kind']); ?>"><?php echo $b['kind'] === 'pre-restore' ? 'pre-restore safety' : 'backup'; ?></span></td>
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

      <!-- Actions -->
      <div>
        <div class="card" style="margin-bottom:18px;">
          <div class="ch"><div class="ci"><i class="fas fa-cloud-arrow-up"></i></div><h3>Back up now</h3></div>
          <div class="cb">
            <p>Create an immediate snapshot of the entire database. Snapshots are compressed and the newest 14 are kept automatically.</p>
            <form method="post">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="backup">
              <button class="btn" type="submit"><i class="fas fa-cloud-arrow-up"></i> Back up now</button>
            </form>
          </div>
        </div>

        <div class="card">
          <div class="ch"><div class="ci"><i class="fas fa-clock-rotate-left"></i></div><h3>Recover data</h3></div>
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
              <button class="btn danger" type="submit"><i class="fas fa-clock-rotate-left"></i> Recover records</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="note">
      <strong>How the automated backup runs.</strong> A Windows Task Scheduler job runs <code>php scripts\backup_db.php</code> nightly, producing rotating compressed archives of every database table (the same snapshots listed above), then rotating logs and flushing the mail outbox. To (re)create the scheduled task, run in an elevated PowerShell:
      <br><br>
      <code>schtasks /Create /SC DAILY /ST 01:30 /TN "BEC PMO DB Backup" /TR "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\-WEB-BASED\scripts\backup_db.php\""</code>
    </div>
  </div>
<script src="assets/sidebar_autohide.js" defer></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>
