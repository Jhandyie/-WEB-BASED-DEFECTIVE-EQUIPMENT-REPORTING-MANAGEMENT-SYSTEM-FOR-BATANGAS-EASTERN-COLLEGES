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
            // If the first due date is today/past, generate immediately.
            @runPreventiveMaintenanceSweep();
            $flash = ['ok', 'Preventive maintenance schedule created.'];
        }
    } elseif ($act === 'toggle') {
        $pdo->prepare("UPDATE preventive_schedules SET status = CASE WHEN status='active' THEN 'paused' ELSE 'active' END WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        $flash = ['ok', 'Schedule status updated.'];
    } elseif ($act === 'delete') {
        $pdo->prepare("DELETE FROM preventive_schedules WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        $flash = ['ok', 'Schedule deleted.'];
    } elseif ($act === 'run_now') {
        $n = runPreventiveMaintenanceSweep();
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
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root{--m:#7B1D1D;--md:#4A0E0E;--g:#C9960C;--ink:#1C1008;--ink2:#5C3838;--ink3:#8A7466;--paper:#F4F1EC;--surface:#fff;--border:#E2D9CC;--sb:262px;--danger:#B42318;--success:#1A7A33;--m1:#2D0505;--g2:#D4A017;--g3:#F0C040;--r1:8px;--r2:12px;}
  *{box-sizing:border-box}
  body{margin:0;font-family:'DM Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;}
  .sb{position:fixed;left:0;top:0;width:var(--sb);height:100vh;background:linear-gradient(168deg,#1E0202 0%,#350808 38%,#4A0E0E 68%,#3A0808 100%);display:flex;flex-direction:column;z-index:400;overflow:hidden;box-shadow:5px 0 30px rgba(45,5,5,.38);transition:transform .32s cubic-bezier(.4,0,.2,1);}
  .sb::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 110% 45% at 50% -5%,rgba(212,160,23,.12),transparent);pointer-events:none;}
  .sb-top{padding:1.35rem 1.2rem .9rem;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:.75rem;position:relative;z-index:1;}
  .seal-ring{position:relative;width:46px;height:46px;flex-shrink:0;}
  .seal-spin{position:absolute;inset:-3px;border-radius:50%;background:conic-gradient(var(--g2) 0%,var(--g3) 30%,var(--g2) 60%,var(--g3) 80%,var(--g2) 100%);animation:sealSpin 7s linear infinite;opacity:.72;}
  @keyframes sealSpin{to{transform:rotate(360deg)}}
  .seal-core{position:absolute;inset:2px;border-radius:50%;overflow:hidden;background:var(--m1);}
  .seal-core img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
  .sb-brand strong{display:block;font-family:'Outfit',sans-serif;font-weight:800;font-size:.8rem;color:#fff;line-height:1.25;}
  .sb-brand em{font-size:.57rem;font-style:normal;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:1.8px;}
  .sb-user{margin:.45rem 1rem .2rem;padding:.65rem .875rem;background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.07);border-radius:var(--r2);display:flex;align-items:center;gap:.65rem;position:relative;z-index:1;}
  .uav{width:32px;height:32px;flex-shrink:0;border-radius:50%;background:linear-gradient(135deg,var(--g2),#B45309);display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-weight:900;font-size:.77rem;color:#fff;box-shadow:0 3px 0 rgba(0,0,0,.28);}
  .uname{font-size:.8rem;color:#fff;font-weight:600;display:block;}
  .urole{font-size:.58rem;color:rgba(255,255,255,.32);text-transform:uppercase;letter-spacing:1px;}
  .sb-nav{flex:1;padding:.25rem 0;overflow-y:auto;position:relative;z-index:1;scrollbar-width:thin;scrollbar-color:rgba(212,160,23,.45) transparent;}
.sb-nav::-webkit-scrollbar{width:6px;}
.sb-nav::-webkit-scrollbar-thumb{background:rgba(212,160,23,.4);border-radius:3px;}
.sb-nav::-webkit-scrollbar-thumb:hover{background:rgba(212,160,23,.65);}
  .nav-sec{font-size:.54rem;text-transform:uppercase;letter-spacing:2.5px;color:rgba(255,255,255,.18);padding:.5rem 1.25rem .2rem;font-weight:700;}
  .ni{display:flex;align-items:center;gap:.65rem;padding:.56rem 1.25rem;color:rgba(255,255,255,.42);background:none;border:none;width:100%;text-align:left;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;cursor:pointer;transition:all .16s;text-decoration:none;position:relative;}
  .ni-ic{width:30px;height:30px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.78rem;background:rgba(255,255,255,.05);flex-shrink:0;transition:all .22s;}
  .ni:hover{color:rgba(255,255,255,.82);}
  .ni:hover .ni-ic{background:rgba(255,255,255,.1);transform:scale(1.08);}
  .ni.on{color:#fff;font-weight:600;}
  .ni.on .ni-ic{background:linear-gradient(135deg,var(--g2),var(--g3));color:var(--m1);box-shadow:0 3px 0 rgba(0,0,0,.18),0 4px 12px rgba(212,160,23,.25);}
  .ni.on::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(to bottom,var(--g2),var(--g3));border-radius:0 3px 3px 0;}
  .sb-foot{padding:.55rem 1rem .95rem;border-top:1px solid rgba(255,255,255,.06);}
  .lout{width:100%;display:flex;align-items:center;justify-content:center;gap:.65rem;padding:.52rem .78rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);color:rgba(255,255,255,.42);border-radius:var(--r1);cursor:pointer;font-size:.8rem;font-family:'DM Sans',sans-serif;font-weight:500;text-decoration:none;transition:all .18s;}
  .lout:hover{background:rgba(220,38,38,.14);color:#fca5a5;border-color:rgba(220,38,38,.22);}
  .main{margin-left:var(--sb);transition:margin-left .26s ease;}
  body.becSbHide .main{margin-left:0 !important;}
  /* Header */
  .top{background:linear-gradient(135deg,var(--m1) 0%,var(--md) 55%,var(--m) 100%);color:#fff;padding:20px 28px;display:flex;align-items:center;gap:.95rem;}
  .top .top-ic{width:44px;height:44px;border-radius:13px;background:rgba(255,255,255,.12);border:1px solid rgba(212,160,23,.45);display:flex;align-items:center;justify-content:center;font-size:1.15rem;color:var(--g3);flex-shrink:0;}
  .top h1{font-size:1.18rem;margin:0;font-weight:800;letter-spacing:-.01em;}
  .top .sub{font-size:.74rem;color:rgba(255,255,255,.72);margin-top:3px;}
  .wrap{max-width:none;margin:0;padding:24px 28px 64px;} /* full-width desktop view */
  .flash{display:flex;align-items:center;gap:.55rem;padding:.85rem 1rem;border-radius:11px;margin-bottom:1.1rem;font-size:.86rem;font-weight:600;}
  .flash.ok{background:#E9F9EF;border:1px solid #b6e6c6;color:var(--success);}
  .flash.err{background:#FEF2F2;border:1px solid #FECACA;color:var(--danger);}
  /* Stat cards */
  .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:.9rem;margin-bottom:1.4rem;}
  .stat{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1rem 1.15rem;display:flex;align-items:center;gap:.95rem;box-shadow:0 1px 2px rgba(44,10,10,.05);transition:transform .18s,box-shadow .18s;}
  .stat:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(44,10,10,.1);}
  .stat .ic{width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0;}
  .stat .n{font-size:1.8rem;font-weight:800;line-height:1;color:var(--ink);}
  .stat .l{font-size:.65rem;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);font-weight:700;margin-top:.3rem;}
  .stat.s-m .ic{background:rgba(123,29,29,.1);color:var(--m);} .stat.s-m .n{color:var(--m);}
  .stat.s-a .ic{background:rgba(201,150,12,.16);color:#B45309;}
  .stat.s-a.warn .ic{background:#FEF2F2;color:var(--danger);} .stat.s-a.warn .n{color:var(--danger);}
  .stat.s-g .ic{background:#E9F9EF;color:var(--success);} .stat.s-g .n{color:var(--success);}
  /* Panels */
  .panel{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.3rem;margin-bottom:1.4rem;box-shadow:0 1px 2px rgba(44,10,10,.05);}
  .panel h2{margin:0 0 1rem;font-size:1rem;color:var(--m);display:flex;align-items:center;gap:.55rem;padding-bottom:.8rem;border-bottom:1px solid var(--border);}
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
  .btn.m{background:var(--m);color:#fff;box-shadow:0 3px 0 var(--m1);} .btn.m:hover{background:var(--md);transform:translateY(-1px);}
  .btn.m:active{transform:translateY(1px);box-shadow:0 1px 0 var(--m1);}
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
  .empty{text-align:center;color:var(--ink3);padding:2.5rem 1rem;}
  .empty i{color:var(--g);opacity:.75;}
  .iact{background:#fff;border:1px solid var(--border);border-radius:9px;width:34px;height:34px;cursor:pointer;color:var(--ink2);transition:all .15s;}
  .iact:hover{background:#f1eadf;border-color:var(--m);color:var(--m);transform:translateY(-1px);}
  .iact.del:hover{background:#FEF2F2;border-color:var(--danger);color:var(--danger);}
  @media(max-width:860px){.main{margin-left:0;}.cards{grid-template-columns:1fr}.fgrid{grid-template-columns:1fr}}
  @media(max-width:640px){ input,select,textarea,.fi,.fc,.input{ font-size:16px; } } /* prevent iOS zoom */
</style>
</head>
<body>
<?php $activeNav = 'preventive'; require __DIR__ . '/includes/admin_sidebar.php'; ?>
  <div class="main">
    <div class="top">
      <div class="top-ic"><i class="fas fa-calendar-check"></i></div>
      <div><h1>Preventive Maintenance</h1><div class="sub">Recurring maintenance schedules that auto-generate work when due</div></div>
    </div>
    <div class="wrap">
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
          <table>
            <thead><tr><th>Task</th><th>Equipment</th><th>Every</th><th>Next Due</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($schedules as $s):
                $due = strtotime((string)$s['next_due']); $soon = $due <= strtotime('+7 days'); ?>
              <tr>
                <td><strong><?php echo pm_e($s['title']); ?></strong></td>
                <td><?php echo pm_e($s['equipment_name'] ?: $s['equipment_id']); ?><?php echo $s['location']?'<br><span style="color:#9E8070;font-size:.72rem;">'.pm_e($s['location']).'</span>':''; ?></td>
                <td><?php echo (int)$s['frequency_days']; ?> days</td>
                <td class="<?php echo ($soon && $s['status']==='active')?'due-soon':''; ?>"><?php echo pm_e(date('M j, Y', $due)); ?></td>
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
<?php require_once __DIR__ . '/includes/admin_assistant.php'; ?>
<?php require __DIR__ . '/includes/site_transitions.php'; ?>
</body>
</html>
