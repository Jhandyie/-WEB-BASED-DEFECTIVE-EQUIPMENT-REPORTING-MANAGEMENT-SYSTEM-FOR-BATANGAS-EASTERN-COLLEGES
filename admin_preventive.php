<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/preventive_helper.php';
requireRole('admin');
@runPreventiveMaintenanceSweep(); // generate any due tasks on load

$admin_id   = $_SESSION['user_id'] ?? '';
$admin_name = $_SESSION['fullname'] ?? 'Administrator';
$pdo = getPgsqlPdoConnection();
function pm_e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'create') {
        $title    = trim($_POST['title'] ?? '');
        $eqId     = trim($_POST['equipment_id'] ?? '');
        $freq     = max(1, (int)($_POST['frequency_days'] ?? 60));
        $firstDue = trim($_POST['next_due'] ?? '') ?: date('Y-m-d');
        $priority = trim($_POST['priority'] ?? 'medium');
        $assigned = trim($_POST['assigned_to'] ?? '');
        $instr    = trim($_POST['instructions'] ?? '');
        if ($title === '' || $eqId === '') {
            $flash = ['err', 'Task title and target equipment are required.'];
        } else {
            $eq = function_exists('getEquipmentById') ? getEquipmentById($eqId) : null;
            $eqName = $eq['equipment_name'] ?? '';
            $loc    = $eq['location'] ?? '';
            $cat    = $eq['category'] ?? ($eq['equipment_category'] ?? '');
            $st = $pdo->prepare("INSERT INTO preventive_schedules
                (title,equipment_id,equipment_name,location,category,frequency_days,next_due,priority,assigned_to,instructions,status,created_by,created_at)
                VALUES (:t,:eid,:en,:loc,:cat,:f,:nd,:pr,:asg,:ins,'active',:cb, now())");
            $st->execute(['t'=>$title,'eid'=>$eqId,'en'=>$eqName,'loc'=>$loc,'cat'=>$cat,'f'=>$freq,'nd'=>$firstDue,'pr'=>$priority,'asg'=>($assigned !== '' ? $assigned : null),'ins'=>$instr,'cb'=>$admin_id]);
            if (function_exists('logActivity')) { try { logActivity($admin_id, 'admin', 'pm.create', 'Created PM schedule: ' . $title); } catch (\Throwable $e) {} }
            // If the first due date is today/past, generate immediately —
            // forced past the throttle, because the admin just asked for it.
            @runPreventiveMaintenanceSweep(true);
            $flash = ['ok', 'Preventive maintenance schedule created.'];
        }
    } elseif ($act === 'toggle') {
        $pdo->prepare("UPDATE preventive_schedules SET status = CASE WHEN status='active' THEN 'paused' ELSE 'active' END WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        $flash = ['ok', 'Schedule status updated.'];
    } elseif ($act === 'delete') {
        $pdo->prepare("DELETE FROM preventive_schedules WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        $flash = ['ok', 'Schedule deleted.'];
    } elseif ($act === 'run_now') {
        $n = runPreventiveMaintenanceSweep(true);
        $flash = ['ok', $n > 0 ? "$n preventive task(s) generated." : 'No schedules are due right now.'];
    }
}

$schedules = $pdo->query("SELECT * FROM preventive_schedules ORDER BY (status='active') DESC, next_due ASC")->fetchAll(PDO::FETCH_ASSOC);
$activeCount = 0; $dueSoon = 0;
foreach ($schedules as $s) {
    if ($s['status'] === 'active') { $activeCount++; if (strtotime((string)$s['next_due']) <= strtotime('+7 days')) { $dueSoon++; } }
}
$generatedCount = (int)$pdo->query("SELECT COUNT(*) FROM defect_reports WHERE is_preventive = true")->fetchColumn();
$equipList = $pdo->query("SELECT equipment_id, equipment_name, asset_tag, location FROM equipment WHERE LOWER(COALESCE(status,'')) <> 'deleted' ORDER BY equipment_name")->fetchAll(PDO::FETCH_ASSOC);
$techList  = function_exists('getAvailableTechnicians') ? (getAvailableTechnicians() ?: []) : [];
$presets   = pmFrequencyPresets();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Preventive Maintenance — Admin</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin-shell.css">
<style>

  :root{--m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--ink:#1A0808;--ink2:#5C3838;--ink3:#9C7A7A;--paper:#F4EFE6;--surface:#fff;--border:#E5D9C6;--sb:262px;--danger:#B42318;--success:#1A7A33;--m1:#2D0505;--g2:#D4A017;--g3:#F0C040;--r1:8px;--r2:12px;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'DM Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;}
  /* sidebar styling lives in assets/css/admin-shell.css */
  .main{margin-left:var(--sb);transition:margin-left .26s ease;}
  body.becSbHide .main{margin-left:0 !important;}
  /* Header */
  .wrap{max-width:none;margin:0;padding:24px 28px 64px;} /* full-width desktop view */
  .flash{display:flex;align-items:center;gap:.55rem;padding:.85rem 1rem;border-radius:11px;margin-bottom:1.1rem;font-size:.86rem;font-weight:600;}
  .flash.ok{background:#E9F9EF;border:1px solid #b6e6c6;color:var(--success);}
  .flash.err{background:#FEF2F2;border:1px solid #FECACA;color:var(--danger);}
  /* Stat cards */
  .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;}
  .stat{position:relative;overflow:hidden;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:1.15rem 1.3rem;display:flex;align-items:center;gap:1rem;box-shadow:0 1px 2px rgba(44,10,10,.05);transition:transform .26s cubic-bezier(.4,0,.2,1),box-shadow .26s;cursor:default;}
  .stat::before{content:'';position:absolute;top:-24px;right:-24px;width:90px;height:90px;border-radius:50%;background:var(--sk,var(--m));opacity:.05;transition:transform .3s,opacity .3s;}
  .stat::after{content:'';position:absolute;left:0;bottom:0;width:100%;height:3px;background:var(--sk,var(--m));transform:scaleX(0);transform-origin:left;transition:transform .32s;}
  .stat:hover{transform:none;box-shadow:0 12px 30px rgba(44,10,10,.12);}
  .stat:hover::before{transform:none;opacity:.09;}
  .stat:hover::after{transform:scaleX(1);}
  .stat .ic{position:relative;z-index:1;width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;box-shadow:none;transition:transform .26s;}
  .stat:hover .ic{transform:none;}
  .stat .n{position:relative;z-index:1;font-family:'Outfit',sans-serif;font-size:2rem;font-weight:800;line-height:1;color:var(--ink);}
  .stat .l{position:relative;z-index:1;font-size:.62rem;text-transform:uppercase;letter-spacing:.6px;color:var(--ink3);font-weight:700;margin-top:.35rem;}
  .stat.s-m{--sk:var(--m);} .stat.s-m .ic{background:rgba(123,29,29,.1);color:var(--m);} .stat.s-m .n{color:var(--m);}
  .stat.s-a{--sk:#D97706;} .stat.s-a .ic{background:rgba(201,150,12,.16);color:#B45309;}
  .stat.s-a.warn{--sk:var(--danger);} .stat.s-a.warn .ic{background:#FEF2F2;color:var(--danger);} .stat.s-a.warn .n{color:var(--danger);}
  .stat.s-g{--sk:var(--success);} .stat.s-g .ic{background:#E9F9EF;color:var(--success);} .stat.s-g .n{color:var(--success);}
  /* Panels */
  .panel{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.3rem;margin-bottom:1.4rem;box-shadow:0 1px 2px rgba(44,10,10,.05);}
  .panel h2{margin:0 0 1rem;font-size:1rem;color:var(--ink);display:flex;align-items:center;gap:.6rem;padding-bottom:.8rem;border-bottom:1px solid var(--border);}
  .panel h2 > i{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,rgba(123,29,29,.1),rgba(201,150,12,.12));color:var(--m);display:inline-flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;}
  .panel h2 .count{margin-left:auto;font-size:.66rem;font-weight:700;color:var(--ink3);background:#f4ede1;border:1px solid var(--border);border-radius:999px;padding:.18rem .65rem;text-transform:none;letter-spacing:0;}
  /* Form */
  .fgrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;}
  .fg{display:flex;flex-direction:column;gap:.4rem;min-width:0;}.fg.full{grid-column:1/-1;}
  label{font-size:.7rem;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.5px;}
  input,select,textarea{width:100%;max-width:100%;padding:.7rem .85rem;border:1.5px solid var(--border);border-radius:10px;font:inherit;font-size:.92rem;background:#fff;color:var(--ink);transition:border-color .15s,box-shadow .15s;}
  select{text-overflow:ellipsis;}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--m);box-shadow:0 0 0 3px rgba(123,29,29,.1);}
  textarea{resize:vertical;min-height:62px;}
  .form-actions{margin-top:1.25rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;}
  .form-actions .hint{font-size:.72rem;color:var(--ink3);margin-left:auto;}
  .btn{display:inline-flex;align-items:center;gap:.5rem;padding:.68rem 1.2rem;border-radius:10px;border:none;font:inherit;font-weight:700;font-size:.84rem;cursor:pointer;text-decoration:none;transition:transform .15s,background .15s;}
  .btn.m{background:var(--m);color:#fff;box-shadow:none;} .btn.m:hover{background:var(--md);transform:none;}
  .btn.m:active{transform:none;box-shadow:none;}
  .btn.ghost{background:#f1eadf;color:var(--ink2);} .btn.ghost:hover{background:#e7dac6;}
  /* Table */
  .tbl-wrap{border:1px solid var(--border);border-radius:12px;overflow:hidden;overflow-x:auto;}
  table{width:100%;border-collapse:collapse;font-size:.84rem;}
  th{text-align:left;padding:.7rem .75rem;background:var(--md);color:#fff;font-size:.63rem;text-transform:uppercase;letter-spacing:.5px;font-weight:800;white-space:nowrap;}
  td{padding:.7rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:nth-child(even) td{background:#faf7f0;}
  tbody tr:hover td{background:#fbf3e6;}
  .pill{display:inline-flex;align-items:center;gap:.3rem;font-size:.6rem;font-weight:800;padding:.22rem .6rem;border-radius:999px;text-transform:uppercase;letter-spacing:.3px;}
  .pill.active{background:#E9F9EF;color:#166534;}.pill.active::before{content:'';width:6px;height:6px;border-radius:50%;background:#16A34A;}
  .pill.paused{background:#F1F1F1;color:#777;}.pill.paused::before{content:'';width:6px;height:6px;border-radius:50%;background:#9ca3af;}
  .prio{display:inline-block;font-size:.6rem;font-weight:800;padding:.2rem .5rem;border-radius:6px;text-transform:uppercase;letter-spacing:.3px;}
  .prio.critical{background:#FEF2F2;color:#991B1B;}.prio.high{background:#FFF7ED;color:#C2410C;}.prio.medium{background:#FFFBEB;color:#92600A;}.prio.low{background:#F0FDF4;color:#166534;}
  .due-soon{color:var(--danger);font-weight:800;}
  .due-badge{display:inline-block;margin-top:.28rem;font-size:.58rem;font-weight:800;padding:.15rem .48rem;border-radius:6px;letter-spacing:.2px;text-transform:uppercase;}
  .due-badge.over{background:#FEF2F2;color:#991B1B;}
  .due-badge.soon{background:#FFFBEB;color:#92600A;}
  .due-badge.far{background:#F0FDF4;color:#166534;}
  .due-badge.paused{background:#F1F1F1;color:#8A8A8A;}
  .empty{text-align:center;color:var(--ink3);padding:2.5rem 1rem;}
  .empty i{color:var(--g);opacity:.75;}
  .iact{background:#fff;border:1px solid var(--border);border-radius:9px;width:34px;height:34px;cursor:pointer;color:var(--ink2);transition:all .15s;}
  .iact:hover{background:#f1eadf;border-color:var(--m);color:var(--m);transform:none;}
  .iact.del:hover{background:#FEF2F2;border-color:var(--danger);color:var(--danger);}
  @media(max-width:860px){.main{margin-left:0;}.cards{grid-template-columns:1fr}.fgrid{grid-template-columns:1fr}}
  @media(max-width:640px){ input,select,textarea,.fi,.fc,.input{ font-size:16px; } } /* prevent iOS zoom */
</style>
</head>
<body>
<?php $activeNav = 'preventive'; require __DIR__ . '/includes/admin_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <div>
        <div class="pg-title">Preventive Maintenance</div>
        <div class="bc">
          <a href="admin_dashboard.php"><i class="fas fa-home"></i></a>
          <i class="fas fa-chevron-right"></i><span>Preventive Maint.</span>
        </div>
      </div>
    </div>
    <div class="wrap">
      <div class="head">
        <h2>Preventive Maintenance</h2>
        <p>Recurring maintenance schedules that auto-generate work when due.</p>
      </div>
      <?php if ($flash): ?><div class="flash <?php echo $flash[0]==='ok'?'ok':'err'; ?>"><?php echo pm_e($flash[1]); ?></div><?php endif; ?>

      <div class="cards">
        <div class="stat s-m"><div class="ic"><i class="fas fa-calendar-check"></i></div><div><div class="n"><?php echo (int)$activeCount; ?></div><div class="l">Active Schedules</div></div></div>
        <div class="stat s-a<?php echo $dueSoon>0?' warn':''; ?>"><div class="ic"><i class="fas fa-clock"></i></div><div><div class="n"><?php echo (int)$dueSoon; ?></div><div class="l">Due Within 7 Days</div></div></div>
        <div class="stat s-g"><div class="ic"><i class="fas fa-circle-check"></i></div><div><div class="n"><?php echo (int)$generatedCount; ?></div><div class="l">Tasks Generated</div></div></div>
      </div>

      <div class="panel">
        <h2><i class="fas fa-calendar-plus"></i> New Preventive Schedule</h2>
        <form method="POST">
          <input type="hidden" name="action" value="create">
          <div class="fgrid">
            <div class="fg full"><label>Task Title *</label><input type="text" name="title" placeholder="e.g. Aircon cleaning &amp; filter check" required></div>
            <div class="fg"><label>Target Equipment *</label>
              <select name="equipment_id" required>
                <option value="">Select equipment…</option>
                <?php foreach ($equipList as $e): ?>
                  <option value="<?php echo pm_e($e['equipment_id']); ?>"><?php echo pm_e($e['equipment_name']); ?><?php echo $e['asset_tag']?' ('.pm_e($e['asset_tag']).')':''; ?><?php echo $e['location']?' — '.pm_e($e['location']):''; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fg"><label>Frequency *</label>
              <select name="frequency_days" required>
                <?php foreach ($presets as $days=>$lbl): ?><option value="<?php echo (int)$days; ?>" <?php echo $days==60?'selected':''; ?>><?php echo pm_e($lbl); ?> (<?php echo (int)$days; ?> days)</option><?php endforeach; ?>
              </select>
            </div>
            <div class="fg"><label>First Due Date *</label><input type="date" name="next_due" value="<?php echo date('Y-m-d'); ?>" required></div>
            <div class="fg"><label>Priority</label>
              <select name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select>
            </div>
            <div class="fg"><label>Auto-assign Technician (optional)</label>
              <select name="assigned_to"><option value="">Leave unassigned</option>
                <?php foreach ($techList as $t): $tid=$t['technician_id']??($t['user_id']??''); ?><option value="<?php echo pm_e($tid); ?>"><?php echo pm_e($t['fullname']??$tid); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="fg full"><label>Instructions (optional)</label><textarea name="instructions" rows="2" placeholder="Checklist or notes for the technician…"></textarea></div>
          </div>
          <div class="form-actions">
            <button class="btn m" type="submit"><i class="fas fa-plus"></i> Create Schedule</button>
            <button class="btn ghost" type="submit" form="runForm"><i class="fas fa-bolt"></i> Run Due Now</button>
            <span class="hint">Due schedules also auto-run when you open this page or the dashboard.</span>
          </div>
        </form>
        <form method="POST" id="runForm"><input type="hidden" name="action" value="run_now"></form>
      </div>

      <div class="panel">
        <h2><i class="fas fa-calendar-check"></i> Schedules <span class="count"><?php echo count($schedules); ?> total</span></h2>
        <?php if (!$schedules): ?>
          <div class="empty"><i class="fas fa-calendar" style="font-size:1.6rem;display:block;margin-bottom:.5rem;"></i>No preventive schedules yet. Create one above to automate recurring maintenance.</div>
        <?php else: ?>
          <div class="tbl-wrap">
          <table data-paginate="15" data-paginate-noun="schedules">
            <thead><tr><th>Task</th><th>Equipment</th><th>Every</th><th>Next Due</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($schedules as $s):
                $due = strtotime((string)$s['next_due']); $soon = $due <= strtotime('+7 days'); ?>
              <tr>
                <td><strong><?php echo pm_e($s['title']); ?></strong></td>
                <td><?php echo pm_e($s['equipment_name'] ?: $s['equipment_id']); ?><?php echo $s['location']?'<br><span style="color:#9E8070;font-size:.72rem;">'.pm_e($s['location']).'</span>':''; ?></td>
                <td><?php echo (int)$s['frequency_days']; ?> days</td>
                <td class="<?php echo ($soon && $s['status']==='active')?'due-soon':''; ?>">
                  <?php echo pm_e(date('M j, Y', $due)); ?>
                  <?php
                    $dd = (int)floor(($due - strtotime('today')) / 86400);
                    if ($s['status'] !== 'active') { $bc='paused'; $bt='Paused'; }
                    elseif ($dd < 0)  { $bc='over'; $bt='Overdue '.abs($dd).'d'; }
                    elseif ($dd === 0){ $bc='over'; $bt='Due today'; }
                    elseif ($dd <= 7) { $bc='soon'; $bt='In '.$dd.'d'; }
                    else              { $bc='far';  $bt='In '.$dd.'d'; }
                  ?>
                  <br><span class="due-badge <?php echo $bc; ?>"><?php echo $bt; ?></span>
                </td>
                <td><span class="prio <?php echo pm_e(strtolower((string)$s['priority'])); ?>"><?php echo pm_e(ucfirst((string)$s['priority'])); ?></span></td>
                <td><span class="pill <?php echo $s['status']==='active'?'active':'paused'; ?>"><?php echo pm_e($s['status']); ?></span></td>
                <td style="white-space:nowrap;">
                  <form method="POST" style="display:inline;"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                    <button class="iact" type="submit" title="<?php echo $s['status']==='active'?'Pause':'Resume'; ?>"><i class="fas fa-<?php echo $s['status']==='active'?'pause':'play'; ?>"></i></button>
                  </form>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this schedule? Generated tasks remain.');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                    <button class="iact del" type="submit" title="Delete"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php require_once __DIR__ . '/includes/csrf_inject.php'; ?>
<script src="assets/sidebar_autohide.js" defer></script>
<script src="assets/table_paginate.js" defer></script>
<script src="assets/date_picker.js"></script>
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/admin_ui.php'; ?>
</body>
</html>
