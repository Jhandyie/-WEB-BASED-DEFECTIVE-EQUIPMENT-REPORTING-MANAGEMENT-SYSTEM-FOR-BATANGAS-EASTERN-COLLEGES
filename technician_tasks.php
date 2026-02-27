<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

requireRole('technician');

$technician_id   = $_SESSION['user_id']    ?? '';
$technician_name = $_SESSION['fullname']   ?? 'Technician';
$technician_email= $_SESSION['user_email'] ?? '';

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmtDateShort($dt) {
    if (empty($dt)) return 'N/A';
    $ts = strtotime((string)$dt);
    return $ts ? date('M d, Y', $ts) : 'N/A';
}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ initials ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
$initials = 'T';
if (!empty($technician_name)) {
    $parts = explode(' ', trim($technician_name));
    $initials = count($parts) >= 2
        ? strtoupper(substr($parts[0],0,1).substr($parts[count($parts)-1],0,1))
        : strtoupper(substr($technician_name,0,2));
}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ filters from query-string ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
$filter_status   = trim($_GET['status']   ?? '');
$filter_priority = trim($_GET['priority'] ?? '');
$filter_search   = trim($_GET['search']   ?? '');
$current_page    = max(1, (int)($_GET['page'] ?? 1));
$per_page        = 10;
$offset          = ($current_page - 1) * $per_page;

/* inline status-update action */
$action_msg  = '';
$action_type = '';
$technician_keys = array_values(array_filter(array_unique([
    trim((string)$technician_id),
    trim((string)$technician_email),
    trim((string)$technician_name),
]), fn($v) => $v !== ''));
$technician_key_norms = array_values(array_unique(array_map(
    fn($v) => strtolower(trim((string)$v)),
    $technician_keys
)));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $upd_report_id = trim($_POST['report_id'] ?? '');
    $upd_status    = trim($_POST['new_status'] ?? '');
    $allowed_statuses = ['in_progress','completed','assigned'];

    if ($upd_report_id && in_array($upd_status, $allowed_statuses, true)) {
        $conn_upd = getDBConnection();
        $drColsUpd = [];
        try {
            $resCols = $conn_upd->query('SHOW COLUMNS FROM defect_reports');
            if ($resCols) {
                while ($rcol = $resCols->fetch_assoc()) {
                    $drColsUpd[$rcol['Field']] = true;
                }
            }
        } catch (Exception $e) {}

        $assigneeColUpd   = isset($drColsUpd['assigned_to']) ? 'assigned_to' : (isset($drColsUpd['assigned_technician']) ? 'assigned_technician' : 'assigned_to');
        $hasStatusColUpd  = isset($drColsUpd['status']);
        $hasStartedColUpd = isset($drColsUpd['started_at']);
        $hasDoneColUpd    = isset($drColsUpd['completion_date']);

        if (!$hasStatusColUpd) {
            $action_msg  = 'Unable to update task: status column is missing.';
            $action_type = 'err';
        } else {
            $taskRow = null;
            $chk = $conn_upd->prepare("SELECT {$assigneeColUpd} AS assignee_value, status AS current_status FROM defect_reports WHERE report_id=? LIMIT 1");
            if ($chk) {
                $chk->bind_param('s', $upd_report_id);
                if ($chk->execute()) {
                    $taskRow = $chk->get_result()->fetch_assoc();
                }
                $chk->close();
            }

            if (!$taskRow) {
                $action_msg  = 'Task not found.';
                $action_type = 'err';
            } else {
                $assigneeNow = strtolower(trim((string)($taskRow['assignee_value'] ?? '')));
                $statusNow   = strtolower(trim((string)($taskRow['current_status'] ?? '')));

                if ($assigneeNow === '' || !in_array($assigneeNow, $technician_key_norms, true)) {
                    $action_msg  = 'No changes were made (task may not belong to you).';
                    $action_type = 'err';
                } elseif ($statusNow === $upd_status) {
                    $action_msg  = 'No changes were made (status is already set).';
                    $action_type = 'info';
                } else {
                    $setParts = ["status=?"];
                    $types = 's';
                    $vals = [$upd_status];

                    if ($upd_status === 'in_progress' && $hasStartedColUpd) {
                        $setParts[] = "started_at = COALESCE(started_at, NOW())";
                    }
                    if ($upd_status === 'completed' && $hasDoneColUpd) {
                        $setParts[] = "completion_date = NOW()";
                    }

                    $sqlUpd = "UPDATE defect_reports SET " . implode(', ', $setParts) . " WHERE report_id=? AND {$assigneeColUpd}=?";
                    $types .= 'ss';
                    $vals[] = $upd_report_id;
                    $vals[] = (string)($taskRow['assignee_value'] ?? '');

                    $stmt_upd = $conn_upd->prepare($sqlUpd);
                    if ($stmt_upd) {
                        $stmt_upd->bind_param($types, ...$vals);
                        $okExec = $stmt_upd->execute();
                        if ($okExec && $stmt_upd->errno === 0) {
                            $action_msg  = "Task $upd_report_id updated to " . ucwords(str_replace('_',' ',$upd_status)) . '.';
                            $action_type = 'ok';
                        } else {
                            $action_msg  = 'Update failed. Please try again.';
                            $action_type = 'err';
                        }
                        $stmt_upd->close();
                    } else {
                        $action_msg  = 'Update failed. Please try again.';
                        $action_type = 'err';
                    }
                }
            }
        }
    }
}
/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ DB introspection (same pattern as dashboard) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
$conn    = getDBConnection();
$drCols  = [];
$eqCols  = [];
try { $res = $conn->query('SHOW COLUMNS FROM defect_reports'); if($res) while($r=$res->fetch_assoc()) $drCols[$r['Field']]=true; } catch(Exception $e){}
try { $res = $conn->query('SHOW COLUMNS FROM equipment');      if($res) while($r=$res->fetch_assoc()) $eqCols[$r['Field']]=true; } catch(Exception $e){}

$assigneeCol      = isset($drCols['assigned_to'])         ? 'assigned_to'         : (isset($drCols['assigned_technician']) ? 'assigned_technician' : 'assigned_to');
$issueExpr        = isset($drCols['issue_description'])   ? 'r.issue_description'  : (isset($drCols['defect_description']) ? 'r.defect_description' : "''");
$priorityExpr     = isset($drCols['priority'])            ? 'r.priority'           : "'medium'";
$statusExpr       = isset($drCols['status'])              ? 'r.status'             : "'reported'";
$reportDateExpr   = isset($drCols['report_date'])         ? 'r.report_date'        : 'NOW()';
$completionExpr   = isset($drCols['completion_date'])     ? 'r.completion_date'    : 'NULL';
$eqIdCol          = isset($eqCols['equipment_id'])        ? 'equipment_id'         : (isset($eqCols['id']) ? 'id' : 'equipment_id');
$eqNameCol        = isset($eqCols['equipment_name'])      ? 'equipment_name'       : (isset($eqCols['name']) ? 'name' : 'equipment_name');
$eqLocationCol    = isset($eqCols['location'])            ? 'location'             : (isset($eqCols['room']) ? 'room' : 'location');
$eqJoin           = isset($drCols['equipment_name'])      ? '' : "LEFT JOIN equipment e ON e.{$eqIdCol} = r.equipment_id";
$equipmentExpr    = isset($drCols['equipment_name'])      ? 'r.equipment_name'     : "COALESCE(e.{$eqNameCol}, r.equipment_id)";
$locationExpr     = isset($drCols['location'])            ? 'r.location'           : "COALESCE(e.{$eqLocationCol}, '')";

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ build WHERE clauses ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
$where_parts = ["r.{$assigneeCol} = ?"];
$bind_types  = 's';
$bind_vals   = [$technician_id];

if ($filter_status !== '') {
    $where_parts[] = "{$statusExpr} = ?";
    $bind_types   .= 's';
    $bind_vals[]   = $filter_status;
}
if ($filter_priority !== '') {
    $where_parts[] = "{$priorityExpr} = ?";
    $bind_types   .= 's';
    $bind_vals[]   = $filter_priority;
}
if ($filter_search !== '') {
    $where_parts[] = "({$equipmentExpr} LIKE ? OR {$issueExpr} LIKE ? OR r.report_id LIKE ?)";
    $bind_types   .= 'sss';
    $like = '%'.$filter_search.'%';
    $bind_vals[]   = $like;
    $bind_vals[]   = $like;
    $bind_vals[]   = $like;
}

$where_sql = implode(' AND ', $where_parts);

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ count query ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
$total_tasks = 0;
try {
    $sql_count = "SELECT COUNT(*) AS cnt FROM defect_reports r {$eqJoin} WHERE {$where_sql}";
    $stmt_c    = $conn->prepare($sql_count);
    $stmt_c->bind_param($bind_types, ...$bind_vals);
    $stmt_c->execute();
    $total_tasks = (int)$stmt_c->get_result()->fetch_assoc()['cnt'];
    $stmt_c->close();
} catch(Exception $e){}

$total_pages = max(1, (int)ceil($total_tasks / $per_page));
$current_page = min($current_page, $total_pages);
$offset       = ($current_page - 1) * $per_page;

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ data query ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
$tasks = [];
try {
    $sql_data = "SELECT r.report_id,
                        {$equipmentExpr}  AS equipment_name,
                        {$issueExpr}      AS issue_description,
                        {$priorityExpr}   AS priority,
                        {$statusExpr}     AS status,
                        {$locationExpr}   AS location,
                        {$reportDateExpr} AS report_date,
                        {$completionExpr} AS completion_date
                 FROM defect_reports r {$eqJoin}
                 WHERE {$where_sql}
                 ORDER BY FIELD({$priorityExpr},'critical','high','medium','low'),
                          {$reportDateExpr} DESC
                 LIMIT ? OFFSET ?";
    $stmt_d = $conn->prepare($sql_data);
    $paginated_types = $bind_types . 'ii';
    $paginated_vals  = array_merge($bind_vals, [$per_page, $offset]);
    $stmt_d->bind_param($paginated_types, ...$paginated_vals);
    $stmt_d->execute();
    $tasks = $stmt_d->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_d->close();
} catch(Exception $e){}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ summary counts ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
$c_all=0; $c_assigned=0; $c_in_progress=0; $c_completed=0;
try {
    $sql_sum = "SELECT {$statusExpr} AS st, COUNT(*) AS n FROM defect_reports r {$eqJoin} WHERE r.{$assigneeCol}=? GROUP BY {$statusExpr}";
    $stmt_s  = $conn->prepare($sql_sum);
    $stmt_s->bind_param('s', $technician_id);
    $stmt_s->execute();
    $rows_s  = $stmt_s->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_s->close();
    foreach ($rows_s as $rs) {
        $st = strtolower((string)$rs['st']);
        $c_all += (int)$rs['n'];
        if ($st === 'assigned')   $c_assigned   += (int)$rs['n'];
        if ($st === 'in_progress')$c_in_progress += (int)$rs['n'];
        if (in_array($st, ['completed','verified','closed'], true)) $c_completed += (int)$rs['n'];
    }
} catch(Exception $e){}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ helper to build filter URL ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
function filterUrl(array $overrides = []): string {
    $params = [
        'status'   => $_GET['status']   ?? '',
        'priority' => $_GET['priority'] ?? '',
        'search'   => $_GET['search']   ?? '',
        'page'     => '1',
    ];
    foreach ($overrides as $k => $v) $params[$k] = $v;
    $qs = http_build_query(array_filter($params, fn($v) => $v !== ''));
    return 'technician_tasks.php' . ($qs ? '?'.$qs : '');
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="assets/logs.png">
<title>BEC Maintenance - My Tasks</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&family=Poppins:wght@400;500;600;700;800&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* -------------------------------------------
   BEC EQUIPMENT MANAGEMENT - TECHNICIAN PORTAL
   technician_tasks.php
------------------------------------------- */
:root{
  --maroon:#7B1D1D; --maroon-d:#521010; --maroon-l:#9B2C2C;
  --gold:#D4A017;   --gold-l:#F0C040;   --gold-p:#FEF9E7;
  --cream:#FFFDF8;  --surf:#FFFFFF;     --surf2:#FBF7F0;
  --bdr:#EDE0CC;    --t1:#1A0A0A;       --t2:#6B4040;  --t3:#B08080;
  --pb:#FEF9E7; --pc:#92600A;
  --ib:#EBF5FB; --ic:#154360;
  --db:#EAFAF1; --dc:#145A32;
  --rb:#FDEDEC; --rc:#7B241C;
  --s1:0 2px 8px rgba(90,16,16,.07);
  --s2:0 6px 24px rgba(90,16,16,.12);
  --s3:0 16px 48px rgba(90,16,16,.16);
  --r1:8px;--r2:14px;--r3:20px;--r4:28px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'Nunito',sans-serif;background:var(--cream);color:var(--t1);min-height:100vh;overflow-x:hidden;}
::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-track{background:var(--surf2);}::-webkit-scrollbar-thumb{background:var(--bdr);border-radius:10px;}
.layout{display:flex;min-height:100vh;}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ SIDEBAR ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.sidebar{position:fixed;left:0;top:0;width:260px;height:100vh;background:linear-gradient(168deg,#1E0202 0%,#350808 38%,#4A0E0E 68%,#3A0808 100%);display:flex;flex-direction:column;z-index:300;box-shadow:5px 0 30px rgba(45,5,5,.38);transition:transform .35s cubic-bezier(.4,0,.2,1);overflow:hidden;}
.sidebar::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(212,160,23,.13),transparent);pointer-events:none;}
.sb-seal{
  padding:1.4rem 1.25rem 1rem;
  border-bottom:1px solid rgba(255,255,255,.06);
  position:relative;z-index:1;
  display:flex;align-items:center;gap:.75rem;
}
.seal-wrap{
  position:relative;flex-shrink:0;
  width:46px;height:46px;
}
.seal-glow{
  position:absolute;inset:-3px;border-radius:50%;
  background:conic-gradient(var(--gold),var(--gold-l),var(--gold));
  animation:sglow 6s linear infinite;
  opacity:.7;
}
@keyframes sglow{from{transform:rotate(0)}to{transform:rotate(360deg)}}
.seal-inner{
  position:absolute;inset:2px;border-radius:50%;
  background:var(--maroon-d);
  display:flex;align-items:center;justify-content:center;
  overflow:hidden;
}
.seal-inner img{width:100%;height:100%;border-radius:50%;object-fit:cover;}
.sb-brand strong{
  display:block;font-family:'Outfit',sans-serif;
  font-size:.8rem;font-weight:800;color:#fff;line-height:1.25;
}
.sb-brand span{font-size:.57rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:1.8px;}
.sb-user{margin:.45rem 1rem .2rem;background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.07);border-radius:var(--r2);padding:.65rem .875rem;display:flex;align-items:center;gap:.65rem;transition:background .2s;cursor:default;position:relative;z-index:1;}
.sb-user:hover{background:rgba(255,255,255,.08);}
.u-av{width:32px;height:32px;flex-shrink:0;background:linear-gradient(135deg,var(--gold),var(--maroon-l));border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-weight:800;font-size:.77rem;color:#fff;box-shadow:0 4px 0 rgba(0,0,0,.3),0 6px 14px rgba(0,0,0,.2);transition:transform .25s;}
.sb-user:hover .u-av{transform:scale(1.08) rotate(-6deg);}
.u-name{display:block;font-size:.8rem;color:#fff;font-weight:600;}
.u-meta{display:flex;align-items:center;gap:.3rem;margin-top:.1rem;}
.u-dot{width:6px;height:6px;border-radius:50%;background:#4ade80;box-shadow:0 0 6px #4ade80;animation:uPulse 2s ease-in-out infinite;}
@keyframes uPulse{0%,100%{opacity:1;}50%{opacity:.5;}}
.u-role{font-size:.58rem;color:rgba(255,255,255,.32);text-transform:uppercase;letter-spacing:1px;}
.sb-nav{flex:1;padding:.25rem 0;overflow-y:auto;position:relative;z-index:1;}
.sb-nav::-webkit-scrollbar{width:3px;}
.sb-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:3px;}
.nav-sep{font-size:.54rem;text-transform:uppercase;letter-spacing:2.5px;color:rgba(255,255,255,.18);padding:.5rem 1.25rem .2rem;font-weight:700;}
.nav-item{display:flex;align-items:center;gap:.65rem;padding:.56rem 1.25rem;color:rgba(255,255,255,.42);background:none;border:none;width:100%;text-align:left;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;cursor:pointer;transition:all .16s;position:relative;text-decoration:none;}
.nav-item .ni{width:30px;height:30px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.78rem;background:rgba(255,255,255,.05);flex-shrink:0;transition:all .22s;}
.nav-item:hover{color:rgba(255,255,255,.82);}
.nav-item:hover .ni{background:rgba(255,255,255,.12);transform:scale(1.08);}
.nav-item.active{color:#fff;}
.nav-item.active .ni{background:linear-gradient(135deg,var(--gold),var(--gold-l));color:var(--maroon-d);box-shadow:0 3px 0 rgba(0,0,0,.2),0 4px 12px rgba(212,160,23,.3);transform:scale(1.05);}
.nav-item.active::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(to bottom,var(--gold),var(--gold-l));border-radius:0 3px 3px 0;}
.n-badge{margin-left:auto;background:linear-gradient(135deg,var(--gold),var(--gold-l));color:var(--maroon-d);font-size:.6rem;font-weight:900;padding:2px 7px;border-radius:20px;box-shadow:0 2px 6px rgba(212,160,23,.4);animation:bPulse 2s ease-in-out infinite;}
@keyframes bPulse{0%,100%{transform:scale(1);}50%{transform:scale(1.08);}}
.sb-foot{padding:.55rem 1rem .95rem;border-top:1px solid rgba(255,255,255,.07);position:relative;z-index:1;}
.logout-btn{width:100%;display:flex;align-items:center;gap:.65rem;padding:.52rem .78rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);color:rgba(255,255,255,.42);border-radius:var(--r1);cursor:pointer;font-size:.8rem;font-family:'DM Sans',sans-serif;font-weight:500;transition:all .18s;}
.logout-btn:hover{background:rgba(220,38,38,.18);color:#fca5a5;border-color:rgba(220,38,38,.3);}
.logout-btn i{transition:transform .35s;}
.logout-btn:hover i{transform:rotate(180deg);}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ MAIN ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.main{margin-left:260px;width:calc(100% - 260px);min-height:100vh;display:flex;flex-direction:column;}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ TOPBAR ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.topbar{background:var(--surf);border-bottom:1px solid var(--bdr);height:62px;padding:0 2rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:var(--s1);}
.tb-left{display:flex;align-items:center;gap:.75rem;}
.mob-btn{display:none;background:none;border:none;font-size:1.15rem;cursor:pointer;color:var(--t2);padding:.25rem;}
.tb-seal{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--maroon-d),var(--maroon));border:2px solid var(--bdr);display:flex;align-items:center;justify-content:center;box-shadow:var(--s1);cursor:pointer;transition:transform .3s,box-shadow .3s;flex-shrink:0;}
.tb-seal:hover{transform:scale(1.1) rotate(10deg);box-shadow:0 0 14px rgba(212,160,23,.4);border-color:var(--gold);}
.tb-seal i{font-size:.8rem;color:var(--gold-l);}
.tb-title{font-family:'Poppins',sans-serif;font-weight:700;font-size:1.05rem;color:var(--t1);}
.tb-bread{font-size:.72rem;color:var(--t3);display:flex;align-items:center;gap:.3rem;}
.tb-bread i{font-size:.6rem;}
.tb-right{display:flex;align-items:center;gap:.75rem;}
.date-pill{background:var(--surf2);border:1px solid var(--bdr);border-radius:30px;padding:.35rem .9rem;font-size:.73rem;color:var(--t2);font-weight:700;display:flex;align-items:center;gap:.35rem;}
.date-pill i{color:var(--gold);font-size:.75rem;}
.tb-btn{width:32px;height:32px;background:var(--surf2);border:1px solid var(--bdr);border-radius:var(--r1);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--t2);font-size:.92rem;transition:all .2s;box-shadow:0 2px 0 var(--bdr);position:relative;}
.tb-btn:hover{background:var(--maroon);color:#fff;transform:translateY(-2px);box-shadow:0 4px 0 var(--maroon-d);}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ CONTENT ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.content{padding:1.875rem 2rem;flex:1;display:flex;flex-direction:column;min-height:calc(100vh - 62px);}
@keyframes fadeUp{from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:translateY(0);}}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ PAGE HEADER ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;animation:fadeUp .45s ease both;}
.ph-left{}
.ph-eyebrow{font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:2px;color:var(--maroon);margin-bottom:.3rem;display:flex;align-items:center;gap:.4rem;}
.ph-title{font-family:'Poppins',sans-serif;font-size:1.55rem;font-weight:800;color:var(--t1);line-height:1.2;}
.ph-sub{font-size:.82rem;color:var(--t2);margin-top:.25rem;}
.section-strip{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;background:linear-gradient(135deg,#fff,#fff7e8);border:1px solid var(--bdr);border-left:4px solid var(--gold);border-radius:var(--r2);padding:.7rem .95rem;margin:-.15rem 0 1rem;animation:fadeUp .45s .02s ease both;}
.section-strip .ss-label{font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:1.1px;color:var(--maroon);display:flex;align-items:center;gap:.38rem;}
.section-strip .ss-text{font-size:.78rem;color:var(--t2);}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ SUMMARY STRIPS ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.summary-strip{display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;animation:fadeUp .45s .05s ease both;}
.sum-card{flex:1;min-width:140px;background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r2);padding:.85rem 1.1rem;display:flex;align-items:center;gap:.75rem;box-shadow:var(--s1);cursor:pointer;text-decoration:none;transition:all .22s;}
.sum-card:hover{transform:translateY(-3px);box-shadow:var(--s2);border-color:var(--gold);}
.sum-card.active-filter{border-color:var(--maroon);background:var(--rb);}
.sc-icon{width:40px;height:40px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;}
.sc-icon.all{background:var(--surf2);color:var(--maroon);}
.sc-icon.asgn{background:var(--gold-p);color:var(--pc);}
.sc-icon.prog{background:var(--ib);color:var(--ic);}
.sc-icon.done{background:var(--db);color:var(--dc);}
.sc-n{font-family:'Poppins',sans-serif;font-size:1.4rem;font-weight:800;color:var(--t1);line-height:1;}
.sc-lbl{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);margin-top:.1rem;}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ FILTER BAR ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.filter-bar{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r2);padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;box-shadow:var(--s1);animation:fadeUp .45s .1s ease both;}
.fb-search{flex:1;min-width:200px;position:relative;}
.fb-search i{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--t3);font-size:.85rem;pointer-events:none;}
.fb-search input{width:100%;padding:.52rem .75rem .52rem 2.2rem;border:1px solid var(--bdr);border-radius:var(--r1);font-family:'Nunito',sans-serif;font-size:.84rem;color:var(--t1);background:var(--surf2);transition:border-color .2s,box-shadow .2s;}
.fb-search input:focus{outline:none;border-color:var(--maroon);box-shadow:0 0 0 3px rgba(123,29,29,.08);}
.fb-select{padding:.52rem .85rem;border:1px solid var(--bdr);border-radius:var(--r1);font-family:'Nunito',sans-serif;font-size:.84rem;color:var(--t1);background:var(--surf2);cursor:pointer;transition:border-color .2s;}
.fb-select:focus{outline:none;border-color:var(--maroon);}
.fb-label{font-size:.73rem;font-weight:700;color:var(--t3);white-space:nowrap;}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:var(--r1);font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:800;cursor:pointer;border:none;transition:all .2s;text-decoration:none;white-space:nowrap;}
.btn-maroon{background:linear-gradient(135deg,var(--maroon),var(--maroon-l));color:#fff;box-shadow:0 3px 0 var(--maroon-d);}
.btn-maroon:hover{transform:translateY(-2px);box-shadow:0 5px 0 var(--maroon-d);}
.btn-outline{background:var(--surf2);border:1px solid var(--bdr);color:var(--t2);box-shadow:0 2px 0 var(--bdr);}
.btn-outline:hover{background:var(--surf);border-color:var(--maroon);color:var(--maroon);}
.btn-sm{padding:.34rem .7rem;font-size:.76rem;}
.btn-ghost{background:none;border:1px solid transparent;color:var(--t3);}
.btn-ghost:hover{background:var(--surf2);border-color:var(--bdr);color:var(--t1);}
.filter-bar > *{min-height:34px;}
.fb-select,.btn-sm{line-height:1.1;}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ PANEL ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.panel{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r3);box-shadow:var(--s1);overflow:hidden;animation:fadeUp .45s .15s ease both;display:flex;flex-direction:column;flex:1;min-height:420px;}
.panel-h{padding:1rem 1.4rem;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;}
.panel-h h3{font-family:'Poppins',sans-serif;font-size:.92rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:.4rem;margin:0;}
.panel-h h3 i{color:var(--maroon);}
.panel-count{font-size:.76rem;color:var(--t3);font-weight:700;}
.panel-h-link-btn{display:inline-flex;align-items:center;gap:.42rem;padding:.4rem .74rem;border:1px solid #d6c4a3;border-radius:999px;background:linear-gradient(135deg,#fffdf7,#f7efe0);font-size:.72rem;font-weight:800;color:var(--maroon-d);text-decoration:none;line-height:1;box-shadow:0 1px 0 #eadcc4;}
.panel-h-link-btn:hover{border-color:var(--maroon);background:#fff;color:var(--maroon);box-shadow:0 2px 0 #d2bea0;}
.panel-h-link-btn i{font-size:.85rem;color:var(--maroon);}
.summary-row{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;padding:.7rem 1.35rem;border-bottom:1px dashed var(--bdr);background:var(--surf2);}
.summary-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .58rem;border-radius:999px;border:1px solid var(--bdr);font-size:.68rem;font-weight:800;color:var(--t2);background:#fff;}
.summary-pill.active{border-color:var(--gold);background:var(--gold-p);color:var(--pc);}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ TABLE ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.tbl-wrap{overflow-x:auto;flex:1;display:flex;flex-direction:column;}
.tbl{width:100%;border-collapse:collapse;min-width:720px;height:100%;}
.tbl thead th{padding:.46rem .86rem;font-size:.56rem;text-transform:uppercase;letter-spacing:.8px;color:var(--t3);font-weight:800;text-align:left;background:var(--surf2);border-bottom:1.5px solid var(--bdr);white-space:nowrap;}
.tbl tbody td{padding:.54rem .86rem;font-size:.73rem;color:var(--t1);border-bottom:1px solid var(--bdr);vertical-align:middle;word-break:break-word;}
.tbl tbody tr:last-child td{border-bottom:none;}
.tbl tbody tr{transition:background .1s,transform .1s;}
.tbl tbody tr.rep-row{cursor:pointer;}
.tbl tbody tr:hover td{background:var(--surf2);}
.tbl tbody tr:hover{transform:translateX(2px);}
.tbl tbody tr:hover .tbl-id{color:var(--maroon-d);}
.tbl-id{font-family:'Poppins',sans-serif;font-weight:800;color:var(--maroon);font-size:.78rem;}
.tbl-name{font-weight:700;color:var(--t1);font-size:.74rem;line-height:1.22;}
.tbl-sub{font-size:.62rem;color:var(--t3);margin-top:1px;line-height:1.2;}
.tbl-issue{font-size:.72rem;color:var(--t2);line-height:1.25;max-width:230px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ BADGES ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.badge{display:inline-flex;align-items:center;gap:.2rem;padding:.16rem .5rem;border-radius:20px;font-size:.56rem;font-weight:800;text-transform:uppercase;letter-spacing:.28px;white-space:nowrap;transition:transform .2s;}
.badge:hover{transform:scale(1.06);}
.badge::before{content:'';width:4px;height:4px;border-radius:50%;background:currentColor;animation:dot 2.2s ease-in-out infinite;}
@keyframes dot{0%,100%{opacity:1;}50%{opacity:.4;}}
.b-critical{background:#FFF7ED;color:#C2410C;}
.b-high    {background:#FFFBEB;color:#B45309;}
.b-medium  {background:#EFF6FF;color:#1D4ED8;}
.b-low     {background:#F0FDF4;color:#15803D;}
.b-assigned{background:#FEF9E7;color:#92600A;}
.b-progress{background:#F5F3FF;color:#7C3AED;}
.b-done    {background:#F0FDF4;color:#15803D;}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ ACTION BUTTONS ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.act-btn{width:27px;height:27px;border-radius:var(--r1);display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;cursor:pointer;border:1px solid var(--bdr);background:var(--surf2);color:var(--t2);transition:all .2s;margin-right:2px;vertical-align:middle;}
.act-btn:hover{background:var(--maroon);color:#fff;border-color:var(--maroon-d);transform:translateY(-1px);box-shadow:0 3px 0 var(--maroon-d);}
.act-btn.g:hover{background:#145A32;border-color:#0e3d22;box-shadow:0 3px 0 #0e3d22;}
.act-btn.b:hover{background:#154360;border-color:#0d2e42;box-shadow:0 3px 0 #0d2e42;}
.tbl-date{font-size:.68rem;color:var(--t3);white-space:nowrap;}
.tbl-actions{white-space:nowrap;text-align:right;}
.tbl-actions .act-btn{margin-right:.25rem;}
.tbl-actions .act-btn:last-child{margin-right:0;}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ STATUS QUICK-CHANGE DROPDOWN ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.qs-form{display:inline-flex;align-items:center;gap:.3rem;}
.qs-select{padding:.28rem .55rem;border:1px solid var(--bdr);border-radius:var(--r1);font-size:.73rem;font-family:'Nunito',sans-serif;color:var(--t2);background:var(--surf2);cursor:pointer;transition:border-color .2s;}
.qs-select:focus{outline:none;border-color:var(--maroon);}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ EMPTY STATE ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.empty-state{text-align:center;padding:3.5rem 1.5rem;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:300px;}
.empty-state i{font-size:2.8rem;color:var(--bdr);margin-bottom:1rem;}
.empty-state h4{font-family:'Poppins',sans-serif;font-size:1rem;font-weight:700;color:var(--t2);margin-bottom:.4rem;}
.empty-state p{font-size:.82rem;color:var(--t3);}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ PAGINATION ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.4rem;border-top:1px solid var(--bdr);flex-wrap:wrap;gap:.5rem;}
.pg-info{font-size:.75rem;color:var(--t3);font-weight:700;}
.pg-btns{display:flex;align-items:center;gap:.3rem;}
.pg-btn{width:30px;height:30px;border-radius:var(--r1);border:1px solid var(--bdr);background:var(--surf2);color:var(--t2);font-size:.78rem;font-weight:700;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .18s;font-family:'Nunito',sans-serif;}
.pg-btn:hover{background:var(--maroon);color:#fff;border-color:var(--maroon-d);}
.pg-btn.active{background:var(--maroon);color:#fff;border-color:var(--maroon-d);box-shadow:0 3px 0 var(--maroon-d);}
.pg-btn.disabled{opacity:.38;pointer-events:none;}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ MODAL ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.mo{position:fixed;inset:0;background:rgba(26,10,10,.62);backdrop-filter:blur(6px);z-index:500;display:none;align-items:center;justify-content:center;padding:1rem;}
.mo.open{display:flex;animation:moFI .18s ease;}
@keyframes moFI{from{opacity:0}to{opacity:1}}
.modal{background:var(--surf);border-radius:var(--r4);width:100%;max-width:580px;max-height:92vh;overflow-y:auto;box-shadow:var(--s3);animation:mUp .28s cubic-bezier(.4,0,.2,1);border:1px solid var(--bdr);}
.modal::-webkit-scrollbar{width:4px;}.modal::-webkit-scrollbar-thumb{background:var(--bdr);border-radius:4px;}
@keyframes mUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.m-head{padding:1.4rem 1.65rem 1.2rem;display:flex;justify-content:space-between;align-items:flex-start;background:linear-gradient(115deg,#3D0A0A,var(--maroon));border-radius:var(--r4) var(--r4) 0 0;position:relative;overflow:hidden;}
.m-head::after{content:'';position:absolute;right:-15px;top:-15px;width:90px;height:90px;border-radius:50%;border:2px solid rgba(212,160,23,.2);background:rgba(212,160,23,.06);animation:sealRotate 20s linear infinite;}
.mh-t h2{font-family:'Poppins',sans-serif;font-size:1.05rem;font-weight:800;color:#fff;position:relative;z-index:1;}
.mh-t p{font-size:.73rem;color:rgba(255,255,255,.42);margin-top:.15rem;position:relative;z-index:1;}
.m-x{width:28px;height:28px;background:rgba(255,255,255,.1);border:none;border-radius:50%;color:rgba(255,255,255,.65);font-size:.77rem;cursor:pointer;transition:all .18s;display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;z-index:1;}
.m-x:hover{background:rgba(255,255,255,.22);color:#fff;transform:rotate(90deg);}
.m-body{padding:1.5rem 1.65rem;}
.m-foot{padding:1rem 1.65rem 1.4rem;border-top:1px solid var(--bdr);display:flex;justify-content:flex-end;gap:.55rem;flex-wrap:wrap;}
.dr2{display:flex;gap:1rem;padding:.55rem 0;border-bottom:1px solid var(--bdr);align-items:flex-start;}
.dr2:last-child{border:none;}
.dk2{width:120px;flex-shrink:0;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);}
.dv2{font-size:.84rem;color:var(--t1);flex:1;line-height:1.55;min-width:0;}
.dv2-id{font-family:'Poppins',sans-serif;font-weight:800;color:var(--maroon);}
.w-full{width:100%;}
#statusModal .modal{max-width:620px;}
#statusModal .m-head{padding:1.1rem 1.25rem .95rem;}
#statusModal .mh-t h2{font-size:1.02rem;line-height:1.2;}
#statusModal .mh-t p{font-size:.72rem;opacity:.92;}
#statusModal .m-body{padding:1.05rem 1.25rem 1.15rem;}
#statusModal .m-foot{padding:.9rem 1.25rem 1.15rem;}

#statusModal .dr2{display:grid;grid-template-columns:130px minmax(0,1fr);gap:.75rem;align-items:start;padding:.62rem 0;border-bottom:1px solid var(--bdr);}
#statusModal .dr2:last-child{border-bottom:none;}
#statusModal .dk2{width:auto;font-size:.62rem;font-weight:900;letter-spacing:.75px;text-transform:uppercase;color:var(--t3);line-height:1.2;padding-top:.15rem;}
#statusModal .dv2{font-size:.82rem;color:var(--t1);line-height:1.45;min-width:0;}
#statusModal .dv2-id{font-size:.88rem;color:var(--maroon);}
#statusModal .fb-select{height:35px;padding:.46rem .7rem;font-size:.82rem;}
#statusModal .badge{font-size:.56rem;}

@media(max-width:768px){
  #statusModal .m-head{padding:.95rem 1rem .85rem;}
  #statusModal .m-body{padding:.9rem 1rem 1rem;}
  #statusModal .m-foot{padding:.8rem 1rem 1rem;}
  #statusModal .dr2{grid-template-columns:1fr;gap:.3rem;padding:.52rem 0;}
  #statusModal .dk2{padding-top:0;}
}
#taskInfoModal .modal{max-width:760px;}
#taskInfoModal .m-head{padding:1.1rem 1.25rem .95rem;}
#taskInfoModal .mh-t h2{font-size:1.02rem;line-height:1.2;}
#taskInfoModal .mh-t p{font-size:.72rem;opacity:.92;}
#taskInfoModal .m-body{padding:1.05rem 1.25rem 1.2rem;}

.ti-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.58rem;align-items:stretch;}
.ti-box{border:1px solid var(--bdr);border-radius:10px;padding:.55rem .65rem;background:linear-gradient(135deg,#fff,#fdf8ef);min-width:0;}
.ti-k{font-size:.58rem;font-weight:900;letter-spacing:.75px;text-transform:uppercase;color:var(--t3);margin-bottom:.18rem;line-height:1.1;}
.ti-v{font-size:.78rem;color:var(--t1);font-weight:700;line-height:1.28;word-break:break-word;overflow-wrap:anywhere;}
.ti-v .badge{vertical-align:middle;}

.ti-issue{margin-top:.65rem;border:1px solid var(--bdr);border-radius:10px;padding:.62rem .7rem;background:#fff;}
.ti-issue .ti-v{font-size:.76rem;color:var(--t2);font-weight:600;line-height:1.42;max-height:136px;overflow:auto;padding-right:.2rem;}

.ti-actions{display:flex;gap:.45rem;flex-wrap:wrap;justify-content:flex-end;margin-top:.72rem;padding-top:.72rem;border-top:1px dashed var(--bdr);}
.ti-actions .btn{min-height:32px;}

@media(max-width:768px){
  #taskInfoModal .m-head{padding:.95rem 1rem .85rem;}
  #taskInfoModal .m-body{padding:.9rem 1rem 1rem;}
  .ti-meta{grid-template-columns:1fr;gap:.45rem;}
  .ti-actions{justify-content:stretch;}
  .ti-actions .btn{width:100%;justify-content:center;}
}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ TOAST ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
.ttray{position:fixed;bottom:1.75rem;right:1.75rem;display:flex;flex-direction:column;gap:.42rem;z-index:9999;}
.toast{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r2);padding:.8rem 1rem;display:flex;align-items:flex-start;gap:.6rem;box-shadow:var(--s3);min-width:260px;border-left:4px solid var(--maroon);transform:translateX(80px);opacity:0;transition:all .3s cubic-bezier(.34,1.4,.64,1);}
.toast.show{transform:translateX(0);opacity:1;}
.toast.ok{border-left-color:#145A32;}.toast.err{border-left-color:#7B241C;}
.t-ic{font-size:.95rem;color:var(--maroon);margin-top:1px;}
.toast.ok .t-ic{color:#145A32;}.toast.err .t-ic{color:#7B241C;}
.t-txt{font-size:.81rem;font-weight:700;color:var(--t1);}
.t-sub{font-size:.72rem;color:var(--t2);margin-top:1px;}

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ RESPONSIVE ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
@media(max-width:768px){
  .sidebar{transform:translateX(-100%);}
  .sidebar.open{transform:translateX(0);}
  .main{margin-left:0;width:100%;}
  .content{padding:1.25rem 1rem;min-height:auto;}
  .topbar{padding:0 1rem;}
  .mob-btn{display:flex;}
  .date-pill{display:none;}
  .summary-strip{gap:.6rem;}
  .sum-card{min-width:120px;}
  .filter-bar{gap:.5rem;}
  .fb-search,.fb-select,.fb-label,.btn-sm{width:100%;}
  .fb-label{margin-top:.25rem;}
  .tbl{min-width:680px;}
  .m-foot .btn{width:100%;justify-content:center;}
}
</style>
</head>
<body>
<div class="layout">

<!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â
     SIDEBAR
ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
<aside class="sidebar" id="sidebar">

      <div class="sb-seal">
      <div class="seal-wrap">
        <div class="seal-glow"></div>
        <div class="seal-inner">
          <img src="assets/logs.png" alt="BEC Seal">
        </div>
      </div>
      <div class="sb-brand">
        <strong>Batangas Eastern Colleges</strong>
        <span>Equipment Management</span>
      </div>
    </div>


  <div class="sb-user">
    <div class="u-av"><?php echo esc($initials); ?></div>
    <div>
      <span class="u-name"><?php echo esc($technician_name); ?></span>
      <div class="u-meta">
        <div class="u-dot"></div>
        <span class="u-role">Technician</span>
      </div>
    </div>
  </div>

  <nav class="sb-nav">
    <div class="nav-sep">Main Menu</div>
    <a href="technician_dashboard.php" class="nav-item">
      <div class="ni"><i class="fas fa-th-large"></i></div>
      Dashboard
    </a>
    <a href="technician_tasks.php" class="nav-item active">
      <div class="ni"><i class="fas fa-clipboard-list"></i></div>
      My Tasks
      <?php if ($c_in_progress > 0): ?>
        <span class="n-badge"><?php echo $c_in_progress; ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-sep">Work</div>
    <a href="technician_task_details.php" class="nav-item">
      <div class="ni"><i class="fas fa-wrench"></i></div>
      Task Details
    </a>
    <a href="technician_history.php" class="nav-item">
      <div class="ni"><i class="fas fa-history"></i></div>
      Work History
    </a>

    <div class="nav-sep">Account</div>
    <a href="technician_profile.php" class="nav-item">
      <div class="ni"><i class="fas fa-user-cog"></i></div>
      Profile
    </a>
  </nav>

  <div class="sb-foot">
    <a href="logout.php" class="logout-btn">
      <i class="fas fa-sign-out-alt"></i>
      Logout
    </a>
  </div>

</aside>

<!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â
     MAIN
ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
<div class="main">

  <!-- ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ TOPBAR ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ -->
  <div class="topbar">
    <div class="tb-left">
      <button class="mob-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <i class="fas fa-bars"></i>
      </button>
      <div class="tb-seal" onclick="window.location='technician_dashboard.php'">
        <i class="fas fa-tools"></i>
      </div>
      <div>
        <div class="tb-title">My Tasks</div>
        <div class="tb-bread">
          <a href="technician_dashboard.php" style="color:inherit;text-decoration:none;">Dashboard</a>
          <i class="fas fa-chevron-right"></i>
          Tasks
        </div>
      </div>
    </div>
    <div class="tb-right">
      <div class="date-pill"><i class="fas fa-calendar-alt"></i><span id="currentDate"></span></div>
      <a href="technician_dashboard.php" class="tb-btn" title="Back to Dashboard">
        <i class="fas fa-home"></i>
      </a>
    </div>
  </div>

  <!-- ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ CONTENT ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ -->
  <div class="content">

    <!-- Page Header -->
    <div class="page-header">
      <div class="ph-left">
        <div class="ph-eyebrow"><i class="fas fa-clipboard-list"></i> Task Management</div>
        <div class="ph-title">My Assigned Tasks</div>
        <div class="ph-sub">View, filter, and update the status of all your maintenance work orders.</div>
      </div>
    </div>
    <div class="section-strip">
      <div>
        <div class="ss-label"><i class="fas fa-layer-group"></i> Task Sections</div>
        <div class="ss-text">Track pending work, update active jobs, and finish assigned orders from one queue.</div>
      </div>
    </div>

    <!-- Summary Strip -->
    <div class="summary-strip">
      <a href="<?php echo esc(filterUrl(['status'=>''])); ?>" class="sum-card<?php echo $filter_status==='' ? ' active-filter' : ''; ?>">
        <div class="sc-icon all"><i class="fas fa-layer-group"></i></div>
        <div>
          <div class="sc-n"><?php echo $c_all; ?></div>
          <div class="sc-lbl">All Tasks</div>
        </div>
      </a>
      <a href="<?php echo esc(filterUrl(['status'=>'assigned'])); ?>" class="sum-card<?php echo $filter_status==='assigned' ? ' active-filter' : ''; ?>">
        <div class="sc-icon asgn"><i class="fas fa-inbox"></i></div>
        <div>
          <div class="sc-n"><?php echo $c_assigned; ?></div>
          <div class="sc-lbl">Pending</div>
        </div>
      </a>
      <a href="<?php echo esc(filterUrl(['status'=>'in_progress'])); ?>" class="sum-card<?php echo $filter_status==='in_progress' ? ' active-filter' : ''; ?>">
        <div class="sc-icon prog"><i class="fas fa-spinner"></i></div>
        <div>
          <div class="sc-n"><?php echo $c_in_progress; ?></div>
          <div class="sc-lbl">In Progress</div>
        </div>
      </a>
      <a href="<?php echo esc(filterUrl(['status'=>'completed'])); ?>" class="sum-card<?php echo $filter_status==='completed' ? ' active-filter' : ''; ?>">
        <div class="sc-icon done"><i class="fas fa-check-circle"></i></div>
        <div>
          <div class="sc-n"><?php echo $c_completed; ?></div>
          <div class="sc-lbl">Completed</div>
        </div>
      </a>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="technician_tasks.php">
      <input type="hidden" name="page" value="1">
      <div class="filter-bar">
        <div class="fb-search">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search by ID, equipment, or issue..."
                 value="<?php echo esc($filter_search); ?>">
        </div>
        <span class="fb-label">Status:</span>
        <select name="status" class="fb-select">
          <option value="">All</option>
          <option value="assigned"    <?php echo $filter_status==='assigned'    ? 'selected' : ''; ?>>Pending</option>
          <option value="in_progress" <?php echo $filter_status==='in_progress' ? 'selected' : ''; ?>>In Progress</option>
          <option value="completed"   <?php echo $filter_status==='completed'   ? 'selected' : ''; ?>>Completed</option>
          <option value="verified"    <?php echo $filter_status==='verified'    ? 'selected' : ''; ?>>Verified</option>
          <option value="closed"      <?php echo $filter_status==='closed'      ? 'selected' : ''; ?>>Closed</option>
        </select>
        <span class="fb-label">Priority:</span>
        <select name="priority" class="fb-select">
          <option value="">All</option>
          <option value="critical" <?php echo $filter_priority==='critical' ? 'selected' : ''; ?>>Critical</option>
          <option value="high"     <?php echo $filter_priority==='high'     ? 'selected' : ''; ?>>High</option>
          <option value="medium"   <?php echo $filter_priority==='medium'   ? 'selected' : ''; ?>>Medium</option>
          <option value="low"      <?php echo $filter_priority==='low'      ? 'selected' : ''; ?>>Low</option>
        </select>
        <button type="submit" class="btn btn-maroon btn-sm"><i class="fas fa-filter"></i> Filter</button>
        <?php if ($filter_status||$filter_priority||$filter_search): ?>
          <a href="technician_tasks.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Task Table Panel -->
    <div class="panel">
      <div class="panel-h">
        <h3><i class="fas fa-clipboard-check"></i> Task List</h3>
        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
          <a class="panel-h-link-btn" href="technician_history.php"><i class="fas fa-history"></i> Work History</a>
          <span class="panel-count">
            Showing <?php echo min($offset+1, $total_tasks); ?>-<?php echo min($offset+$per_page, $total_tasks); ?> of <?php echo $total_tasks; ?> task<?php echo $total_tasks!==1?'s':''; ?>
          </span>
        </div>
      </div>
      <div class="summary-row">
        <span class="summary-pill<?php echo $filter_status==='' ? ' active' : ''; ?>"><i class="fas fa-layer-group"></i> <?php echo $c_all; ?> all</span>
        <span class="summary-pill<?php echo $filter_status==='assigned' ? ' active' : ''; ?>"><i class="fas fa-inbox"></i> <?php echo $c_assigned; ?> pending</span>
        <span class="summary-pill<?php echo $filter_status==='in_progress' ? ' active' : ''; ?>"><i class="fas fa-spinner"></i> <?php echo $c_in_progress; ?> in progress</span>
        <span class="summary-pill<?php echo $filter_status==='completed' ? ' active' : ''; ?>"><i class="fas fa-check-circle"></i> <?php echo $c_completed; ?> completed</span>
      </div>

      <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>Task ID</th>
              <th>Equipment</th>
              <th>Issue</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Assigned</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
<?php if (empty($tasks)): ?>
            <tr>
              <td colspan="7">
                <div class="empty-state">
                  <i class="fas fa-clipboard-list"></i>
                  <h4>No tasks found</h4>
                  <p>Try adjusting your filters or check back later for new assignments.</p>
                </div>
              </td>
            </tr>
<?php else: foreach ($tasks as $t):
  $st  = strtolower((string)($t['status']   ?? 'assigned'));
  $p   = strtolower((string)($t['priority'] ?? 'low'));
  $pb  = match($p) { 'critical'=>'b-critical','high'=>'b-high','medium'=>'b-medium', default=>'b-low' };
  $sb  = in_array($st,['completed','verified','closed'],true)
           ? 'b-done'
           : ($st==='in_progress' ? 'b-progress' : 'b-assigned');
  $rid = (string)($t['report_id'] ?? '');
  $equip = (string)($t['equipment_name'] ?? 'Equipment');
  $loc = (string)($t['location'] ?: 'Unspecified');
  $issueShort = mb_strimwidth((string)($t['issue_description']??'No description.'),0,90,'...');
  $issueFull  = (string)($t['issue_description']??'No description.');
  $stLabel = ucwords(str_replace('_',' ',$st));
  $pLabel  = ucfirst($p);
  $assignedFmt = fmtDateShort($t['report_date']??null);
  $detailUrl = 'technician_task_details.php?report_id=' . urlencode($rid);
?>
            <tr class="rep-row" tabindex="0" role="button" data-report-id="<?php echo esc($rid); ?>" data-equipment="<?php echo esc($equip); ?>" data-location="<?php echo esc($loc); ?>" data-issue="<?php echo esc($issueFull); ?>" data-priority="<?php echo esc($p); ?>" data-priority-label="<?php echo esc($pLabel); ?>" data-status="<?php echo esc($st); ?>" data-status-label="<?php echo esc($stLabel); ?>" data-assigned="<?php echo esc($assignedFmt); ?>" data-detail-url="<?php echo esc($detailUrl); ?>">
              <td><span class="tbl-id"><?php echo esc($rid); ?></span></td>
              <td>
                <div class="tbl-name"><?php echo esc($t['equipment_name'] ?? 'Equipment'); ?></div>
                <div class="tbl-sub"><?php echo esc($t['location'] ?: 'Unspecified'); ?></div>
              </td>
              <td>
                <div class="tbl-issue"><?php echo esc(mb_strimwidth((string)($t['issue_description']??'No description.'),0,90,'...')); ?></div>
              </td>
              <td><span class="badge <?php echo esc($pb); ?>"><?php echo esc(ucfirst($p)); ?></span></td>
              <td><span class="badge <?php echo esc($sb); ?>"><?php echo esc(ucwords(str_replace('_',' ',$st))); ?></span></td>
              <td class="tbl-date"><?php echo esc(fmtDateShort($t['report_date']??null)); ?></td>
              <td class="tbl-actions">
                <!-- View detail -->
                <a class="act-btn b" href="technician_task_details.php?report_id=<?php echo urlencode($rid); ?>" title="View Details">
                  <i class="fas fa-eye"></i>
                </a>

                <?php if (!in_array($st, ['completed','verified','closed'], true)): ?>
                <!-- Quick status update -->
                <button class="act-btn" title="Update Status"
                        onclick="openStatusModal('<?php echo esc($rid); ?>','<?php echo esc($st); ?>','<?php echo esc($t['equipment_name']??'Equipment'); ?>')">
                  <i class="fas fa-exchange-alt"></i>
                </button>
                <?php endif; ?>
              </td>
            </tr>
<?php endforeach; endif; ?>
          </tbody>
        </table>
      </div><!-- /tbl-wrap -->

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="pagination">
        <div class="pg-info">
          Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
        </div>
        <div class="pg-btns">
          <a class="pg-btn <?php echo $current_page<=1?'disabled':''; ?>"
             href="<?php echo esc(filterUrl(['page'=>max(1,$current_page-1)])); ?>">
            <i class="fas fa-chevron-left"></i>
          </a>
          <?php
          $start_p = max(1, $current_page-2);
          $end_p   = min($total_pages, $current_page+2);
          for ($pp = $start_p; $pp <= $end_p; $pp++):
          ?>
          <a class="pg-btn <?php echo $pp===$current_page?'active':''; ?>"
             href="<?php echo esc(filterUrl(['page'=>$pp])); ?>">
            <?php echo $pp; ?>
          </a>
          <?php endfor; ?>
          <a class="pg-btn <?php echo $current_page>=$total_pages?'disabled':''; ?>"
             href="<?php echo esc(filterUrl(['page'=>min($total_pages,$current_page+1)])); ?>">
            <i class="fas fa-chevron-right"></i>
          </a>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /panel -->

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /layout -->

<!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â
     STATUS UPDATE MODAL
ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
<div class="mo" id="statusModal">
  <div class="modal">
    <div class="m-head">
      <div class="mh-t">
        <h2 id="smTitle">Update Task Status</h2>
        <p id="smSub">Select a new status for this work order</p>
      </div>
      <button class="m-x" onclick="closeModal('statusModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="technician_tasks.php<?php echo http_build_query(array_filter(['status'=>$filter_status,'priority'=>$filter_priority,'search'=>$filter_search,'page'=>$current_page])) ? '?'.http_build_query(array_filter(['status'=>$filter_status,'priority'=>$filter_priority,'search'=>$filter_search,'page'=>$current_page])) : ''; ?>">
      <input type="hidden" name="update_status" value="1">
      <input type="hidden" name="report_id" id="smReportId">
      <div class="m-body">
        <div class="dr2">
          <div class="dk2">Task ID</div>
          <div class="dv2 dv2-id" id="smTaskId"></div>
        </div>
        <div class="dr2">
          <div class="dk2">Equipment</div>
          <div class="dv2" id="smEquipment"></div>
        </div>
        <div class="dr2">
          <div class="dk2">Current Status</div>
          <div class="dv2" id="smCurrent"></div>
        </div>
        <div class="dr2" style="border:none;padding-top:.85rem;">
          <div class="dk2">New Status</div>
          <div class="dv2">
            <select name="new_status" class="fb-select w-full" required>
              <option value="">- Select status -</option>
              <option value="assigned">Assigned (Pending)</option>
              <option value="in_progress">In Progress</option>
              <option value="completed">Completed</option>
            </select>
          </div>
        </div>
      </div>
      <div class="m-foot">
        <button type="button" class="btn btn-outline" onclick="closeModal('statusModal')">Cancel</button>
        <button type="submit" class="btn btn-maroon"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<div class="mo" id="taskInfoModal">
  <div class="modal">
    <div class="m-head">
      <div class="mh-t">
        <h2 id="tiTitle">Task Information</h2>
        <p id="tiSub">Review task details and choose an action.</p>
      </div>
      <button class="m-x" onclick="closeModal('taskInfoModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="m-body">
      <div class="ti-meta">
        <div class="ti-box"><div class="ti-k">Task ID</div><div class="ti-v" id="tiRid"></div></div>
        <div class="ti-box"><div class="ti-k">Assigned</div><div class="ti-v" id="tiAssigned"></div></div>
        <div class="ti-box"><div class="ti-k">Equipment</div><div class="ti-v" id="tiEquipment"></div></div>
        <div class="ti-box"><div class="ti-k">Location</div><div class="ti-v" id="tiLocation"></div></div>
        <div class="ti-box"><div class="ti-k">Priority</div><div class="ti-v" id="tiPriority"></div></div>
        <div class="ti-box"><div class="ti-k">Status</div><div class="ti-v" id="tiStatus"></div></div>
      </div>
      <div class="ti-issue">
        <div class="ti-k" style="margin-bottom:.35rem;">Issue Description</div>
        <div class="ti-v" id="tiIssue"></div>
      </div>
      <div class="ti-actions">
        <a class="btn btn-outline" id="tiViewBtn" href="#"><i class="fas fa-eye"></i> View Full Details</a>
        <button class="btn btn-maroon" type="button" id="tiUpdateBtn"><i class="fas fa-exchange-alt"></i> Update Status</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast Tray -->
<div class="ttray" id="ttray"></div>

<?php if ($action_msg): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  showToast(
    <?php echo json_encode($action_type==='ok'?'Status Updated':'Update Failed'); ?>,
    <?php echo json_encode($action_msg); ?>,
    <?php echo json_encode($action_type); ?>
  );
});
</script>
<?php endif; ?>

<script>
/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Date ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
document.getElementById('currentDate').textContent =
  new Date().toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Sidebar mobile toggle ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
document.addEventListener('click', function(e){
  const sb = document.getElementById('sidebar');
  if (sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.mob-btn')){
    sb.classList.remove('open');
  }
});

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Status Modal ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
function statusBadgeClass(status){
  if (status === 'in_progress') return 'b-progress';
  if (status === 'completed' || status === 'verified' || status === 'closed') return 'b-done';
  return 'b-assigned';
}

function priorityBadgeClass(priority){
  if (priority === 'critical') return 'b-critical';
  if (priority === 'high') return 'b-high';
  if (priority === 'medium') return 'b-medium';
  return 'b-low';
}

function openTaskInfoModalFromRow(tr){
  const rid = tr.dataset.reportId || '';
  const equipment = tr.dataset.equipment || 'Equipment';
  const location = tr.dataset.location || 'Unspecified';
  const issue = tr.dataset.issue || 'No description.';
  const priority = tr.dataset.priority || 'low';
  const priorityLabel = tr.dataset.priorityLabel || priority;
  const status = tr.dataset.status || 'assigned';
  const statusLabel = tr.dataset.statusLabel || status.replace('_',' ');
  const assigned = tr.dataset.assigned || 'N/A';
  const detailUrl = tr.dataset.detailUrl || '#';

  document.getElementById('tiTitle').textContent = equipment;
  document.getElementById('tiRid').textContent = rid;
  document.getElementById('tiAssigned').textContent = assigned;
  document.getElementById('tiEquipment').textContent = equipment;
  document.getElementById('tiLocation').textContent = location;
  document.getElementById('tiIssue').textContent = issue;
  document.getElementById('tiPriority').innerHTML = `<span class="badge ${priorityBadgeClass(priority)}">${priorityLabel}</span>`;
  document.getElementById('tiStatus').innerHTML = `<span class="badge ${statusBadgeClass(status)}">${statusLabel}</span>`;

  const viewBtn = document.getElementById('tiViewBtn');
  viewBtn.href = detailUrl;

  const updBtn = document.getElementById('tiUpdateBtn');
  if (status === 'completed' || status === 'verified' || status === 'closed') {
    updBtn.style.display = 'none';
  } else {
    updBtn.style.display = 'inline-flex';
    updBtn.onclick = function(){
      closeModal('taskInfoModal');
      openStatusModal(rid, status, equipment);
    };
  }

  document.getElementById('taskInfoModal').classList.add('open');
}

document.querySelectorAll('.tbl tbody tr.rep-row').forEach((tr)=>{
  tr.addEventListener('click', ()=>openTaskInfoModalFromRow(tr));
  tr.addEventListener('keydown', (e)=>{
    if(e.key==='Enter' || e.key===' '){ e.preventDefault(); openTaskInfoModalFromRow(tr); }
  });
});

document.querySelectorAll('.tbl .tbl-actions a, .tbl .tbl-actions button').forEach((el)=>{
  el.addEventListener('click', (e)=>e.stopPropagation());
});
function openStatusModal(reportId, currentStatus, equipment){
  document.getElementById('smReportId').value   = reportId;
  document.getElementById('smTaskId').textContent     = reportId;
  document.getElementById('smEquipment').textContent  = equipment;
  const cur = currentStatus.replace('_',' ');
  document.getElementById('smCurrent').innerHTML =
    `<span class="badge ${currentStatus==='in_progress'?'b-progress':currentStatus==='assigned'?'b-assigned':'b-done'}">
       ${cur.charAt(0).toUpperCase()+cur.slice(1)}
     </span>`;
  document.getElementById('statusModal').classList.add('open');
}

function closeModal(id){
  document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.mo').forEach(o=>{
  o.addEventListener('click', e=>{ if(e.target===o) o.classList.remove('open'); });
});

/* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Toast ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
function showToast(title, sub, type){
  const tray = document.getElementById('ttray');
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `
    <i class="t-ic fas ${type==='ok'?'fa-check-circle':type==='err'?'fa-times-circle':'fa-info-circle'}"></i>
    <div><div class="t-txt">${title}</div><div class="t-sub">${sub}</div></div>`;
  tray.appendChild(t);
  setTimeout(()=>t.classList.add('show'),20);
  setTimeout(()=>{ t.classList.remove('show'); setTimeout(()=>t.remove(),400); },3800);
}
</script>
</body>
</html>




















