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

/* ── initials ── */
$initials = 'T';
if (!empty($technician_name)) {
    $parts = explode(' ', trim($technician_name));
    $initials = count($parts) >= 2
        ? strtoupper(substr($parts[0],0,1).substr($parts[count($parts)-1],0,1))
        : strtoupper(substr($technician_name,0,2));
}

/* ── filters from query-string ── */
$filter_status   = trim($_GET['status']   ?? '');
$filter_priority = trim($_GET['priority'] ?? '');
$filter_search   = trim($_GET['search']   ?? '');
$current_page    = max(1, (int)($_GET['page'] ?? 1));
$per_page        = 10;
$offset          = ($current_page - 1) * $per_page;

/* ── inline status-update action ── */
$action_msg  = '';
$action_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $upd_report_id = trim($_POST['report_id'] ?? '');
    $upd_status    = trim($_POST['new_status'] ?? '');
    $allowed_statuses = ['in_progress','completed','assigned'];
    if ($upd_report_id && in_array($upd_status, $allowed_statuses, true)) {
        $conn_upd = getDBConnection();
        $stmt_upd = $conn_upd->prepare(
            "UPDATE defect_reports SET status=? WHERE report_id=? AND assigned_to=?"
        );
        if ($stmt_upd) {
            $stmt_upd->bind_param('sss', $upd_status, $upd_report_id, $technician_id);
            if ($stmt_upd->execute() && $stmt_upd->affected_rows > 0) {
                $action_msg  = "Task $upd_report_id updated to " . ucwords(str_replace('_',' ',$upd_status)) . '.';
                $action_type = 'ok';
            } else {
                $action_msg  = 'No changes were made (task may not belong to you).';
                $action_type = 'err';
            }
            $stmt_upd->close();
        }
    }
}

/* ── DB introspection (same pattern as dashboard) ── */
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

/* ── build WHERE clauses ── */
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

/* ── count query ── */
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

/* ── data query ── */
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

/* ── summary counts ── */
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

/* ── helper to build filter URL ── */
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
<title>BEC Maintenance — My Tasks</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* -------------------------------------------
   BEC EQUIPMENT MANAGEMENT — TECHNICIAN PORTAL
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

/* ── SIDEBAR ── */
.sidebar{position:fixed;left:0;top:0;width:260px;height:100vh;background:linear-gradient(180deg,#3D0A0A 0%,var(--maroon-d) 35%,var(--maroon) 100%);display:flex;flex-direction:column;z-index:300;box-shadow:6px 0 32px rgba(52,10,10,.4);transition:transform .35s cubic-bezier(.4,0,.2,1);overflow:hidden;}
.sidebar::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(212,160,23,.13),transparent);pointer-events:none;}
.sb-seal-area{padding:1.6rem 1.4rem 1rem;border-bottom:1px solid rgba(255,255,255,.07);display:flex;flex-direction:column;align-items:center;gap:.6rem;position:relative;z-index:1;cursor:default;}
.seal-ring{position:relative;width:80px;height:80px;animation:sealFloat 5s ease-in-out infinite;}
@keyframes sealFloat{0%,100%{transform:translateY(0) rotate(0deg);}50%{transform:translateY(-6px) rotate(2deg);}}
.seal-ring::before{content:'';position:absolute;inset:-5px;border-radius:50%;background:conic-gradient(var(--gold),var(--gold-l),var(--gold),var(--gold-l),var(--gold));animation:sealRotate 8s linear infinite;opacity:.7;}
@keyframes sealRotate{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}
.seal-ring::after{content:'';position:absolute;inset:-2px;border-radius:50%;background:var(--maroon-d);}
.seal-img{position:absolute;inset:0;width:100%;height:100%;border-radius:50%;object-fit:cover;border:3px solid rgba(212,160,23,.5);z-index:1;transition:transform .4s;box-shadow:0 0 20px rgba(212,160,23,.3);}
.seal-ring:hover .seal-img{transform:scale(1.06);}
.seal-inner{position:absolute;inset:0;border-radius:50%;z-index:1;background:linear-gradient(135deg,var(--maroon-d),#6B1212,var(--maroon));border:3px solid rgba(212,160,23,.5);box-shadow:0 0 20px rgba(212,160,23,.3);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1px;}
.seal-inner i{font-size:1.55rem;color:var(--gold-l);}
.seal-inner span{font-size:.35rem;color:rgba(255,255,255,.55);text-transform:uppercase;letter-spacing:1.5px;font-weight:800;}
.sb-school-name{text-align:center;line-height:1.25;}
.sb-school-name strong{display:block;font-family:'Poppins',sans-serif;font-size:.82rem;font-weight:800;color:#fff;letter-spacing:.2px;}
.sb-school-name span{font-size:.6rem;color:rgba(255,255,255,.38);text-transform:uppercase;letter-spacing:1.8px;}
.sb-divider{height:1px;margin:0 1.4rem;background:linear-gradient(to right,transparent,rgba(212,160,23,.4),transparent);}
.sb-user{margin:.75rem 1.1rem;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);border-radius:var(--r2);padding:.8rem 1rem;display:flex;align-items:center;gap:.7rem;transition:background .2s;cursor:default;position:relative;z-index:1;}
.sb-user:hover{background:rgba(255,255,255,.1);}
.u-av{width:38px;height:38px;flex-shrink:0;background:linear-gradient(135deg,var(--gold),var(--maroon-l));border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-weight:800;font-size:.88rem;color:#fff;box-shadow:0 4px 0 rgba(0,0,0,.3),0 6px 14px rgba(0,0,0,.2);transition:transform .25s;}
.sb-user:hover .u-av{transform:scale(1.1) rotate(-5deg);}
.u-name{display:block;font-size:.85rem;color:#fff;font-weight:700;}
.u-meta{display:flex;align-items:center;gap:.3rem;margin-top:.1rem;}
.u-dot{width:6px;height:6px;border-radius:50%;background:#4ade80;box-shadow:0 0 6px #4ade80;animation:uPulse 2s ease-in-out infinite;}
@keyframes uPulse{0%,100%{opacity:1;}50%{opacity:.5;}}
.u-role{font-size:.62rem;color:rgba(255,255,255,.38);text-transform:uppercase;letter-spacing:1.2px;}
.sb-nav{flex:1;padding:.3rem 0;overflow-y:auto;position:relative;z-index:1;}
.sb-nav::-webkit-scrollbar{width:3px;}
.sb-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:3px;}
.nav-sep{font-size:.58rem;text-transform:uppercase;letter-spacing:2.5px;color:rgba(255,255,255,.2);padding:.5rem 1.4rem .2rem;font-weight:700;}
.nav-item{display:flex;align-items:center;gap:.75rem;padding:.6rem 1.4rem;color:rgba(255,255,255,.48);background:none;border:none;width:100%;text-align:left;font-family:'Nunito',sans-serif;font-size:.85rem;font-weight:700;cursor:pointer;transition:all .18s;position:relative;text-decoration:none;}
.nav-item .ni{width:32px;height:32px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.8rem;background:rgba(255,255,255,.06);flex-shrink:0;transition:all .25s;}
.nav-item:hover{color:rgba(255,255,255,.88);}
.nav-item:hover .ni{background:rgba(255,255,255,.12);transform:scale(1.1);}
.nav-item.active{color:#fff;}
.nav-item.active .ni{background:linear-gradient(135deg,var(--gold),var(--gold-l));color:var(--maroon-d);box-shadow:0 3px 0 rgba(0,0,0,.2),0 4px 12px rgba(212,160,23,.3);transform:scale(1.05);}
.nav-item.active::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(to bottom,var(--gold),var(--gold-l));border-radius:0 3px 3px 0;}
.n-badge{margin-left:auto;background:linear-gradient(135deg,var(--gold),var(--gold-l));color:var(--maroon-d);font-size:.6rem;font-weight:900;padding:2px 7px;border-radius:20px;box-shadow:0 2px 6px rgba(212,160,23,.4);animation:bPulse 2s ease-in-out infinite;}
@keyframes bPulse{0%,100%{transform:scale(1);}50%{transform:scale(1.1);}}
.sb-foot{padding:.75rem 1.1rem 1.1rem;border-top:1px solid rgba(255,255,255,.07);position:relative;z-index:1;}
.logout-btn{width:100%;display:flex;align-items:center;gap:.7rem;padding:.6rem .9rem;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.07);color:rgba(255,255,255,.48);border-radius:var(--r1);cursor:pointer;font-size:.83rem;font-family:'Nunito',sans-serif;font-weight:700;transition:all .2s;}
.logout-btn:hover{background:rgba(220,38,38,.18);color:#fca5a5;border-color:rgba(220,38,38,.3);}
.logout-btn i{transition:transform .35s;}
.logout-btn:hover i{transform:rotate(180deg);}

/* ── MAIN ── */
.main{margin-left:260px;min-height:100vh;display:flex;flex-direction:column;}

/* ── TOPBAR ── */
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
.tb-btn{width:38px;height:38px;background:var(--surf2);border:1px solid var(--bdr);border-radius:var(--r1);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--t2);font-size:.92rem;transition:all .2s;box-shadow:0 2px 0 var(--bdr);position:relative;}
.tb-btn:hover{background:var(--maroon);color:#fff;transform:translateY(-2px);box-shadow:0 4px 0 var(--maroon-d);}

/* ── CONTENT ── */
.content{padding:1.875rem 2rem;flex:1;}
@keyframes fadeUp{from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:translateY(0);}}

/* ── PAGE HEADER ── */
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;animation:fadeUp .45s ease both;}
.ph-left{}
.ph-eyebrow{font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:2px;color:var(--maroon);margin-bottom:.3rem;display:flex;align-items:center;gap:.4rem;}
.ph-title{font-family:'Poppins',sans-serif;font-size:1.55rem;font-weight:800;color:var(--t1);line-height:1.2;}
.ph-sub{font-size:.82rem;color:var(--t2);margin-top:.25rem;}

/* ── SUMMARY STRIPS ── */
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

/* ── FILTER BAR ── */
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

/* ── PANEL ── */
.panel{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r3);box-shadow:var(--s1);overflow:hidden;animation:fadeUp .45s .15s ease both;}
.panel-h{padding:1rem 1.4rem;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;}
.panel-h h3{font-family:'Poppins',sans-serif;font-size:.92rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:.4rem;margin:0;}
.panel-h h3 i{color:var(--maroon);}
.panel-count{font-size:.76rem;color:var(--t3);font-weight:700;}

/* ── TABLE ── */
.tbl-wrap{overflow-x:auto;}
.tbl{width:100%;border-collapse:collapse;min-width:720px;}
.tbl thead th{padding:.62rem 1.1rem;font-size:.65rem;text-transform:uppercase;letter-spacing:1px;color:var(--t3);font-weight:800;text-align:left;background:var(--surf2);border-bottom:1.5px solid var(--bdr);white-space:nowrap;}
.tbl tbody td{padding:.82rem 1.1rem;font-size:.82rem;color:var(--t1);border-bottom:1px solid var(--bdr);vertical-align:middle;}
.tbl tbody tr:last-child td{border-bottom:none;}
.tbl tbody tr{transition:background .12s,transform .1s;}
.tbl tbody tr:hover td{background:var(--surf2);}
.tbl tbody tr:hover{transform:translateX(3px);}
.tbl-id{font-family:'Poppins',sans-serif;font-weight:800;color:var(--maroon);font-size:.8rem;}
.tbl-name{font-weight:700;color:var(--t1);}
.tbl-sub{font-size:.68rem;color:var(--t3);margin-top:1px;}
.tbl-issue{font-size:.78rem;color:var(--t2);line-height:1.4;max-width:240px;}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:.28rem;padding:.22rem .65rem;border-radius:20px;font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;transition:transform .2s;}
.badge:hover{transform:scale(1.06);}
.badge::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;animation:dot 2s ease-in-out infinite;}
@keyframes dot{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.4;transform:scale(.7);}}
.b-critical{background:#FDEDEC;color:#7B241C;}
.b-high    {background:#FEF0E7;color:#873600;}
.b-medium  {background:var(--ib);color:#154360;}
.b-low     {background:var(--surf2);color:var(--t3);border:1px solid var(--bdr);}
.b-assigned{background:var(--gold-p);color:#92600A;}
.b-progress{background:var(--ib);color:#154360;}
.b-done    {background:var(--db);color:#145A32;}

/* ── ACTION BUTTONS ── */
.act-btn{width:32px;height:32px;border-radius:var(--r1);display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;cursor:pointer;border:1px solid var(--bdr);background:var(--surf2);color:var(--t2);transition:all .2s;margin-right:3px;vertical-align:middle;}
.act-btn:hover{background:var(--maroon);color:#fff;border-color:var(--maroon-d);transform:translateY(-1px);box-shadow:0 3px 0 var(--maroon-d);}
.act-btn.g:hover{background:#145A32;border-color:#0e3d22;box-shadow:0 3px 0 #0e3d22;}
.act-btn.b:hover{background:#154360;border-color:#0d2e42;box-shadow:0 3px 0 #0d2e42;}

/* ── STATUS QUICK-CHANGE DROPDOWN ── */
.qs-form{display:inline-flex;align-items:center;gap:.3rem;}
.qs-select{padding:.28rem .55rem;border:1px solid var(--bdr);border-radius:var(--r1);font-size:.73rem;font-family:'Nunito',sans-serif;color:var(--t2);background:var(--surf2);cursor:pointer;transition:border-color .2s;}
.qs-select:focus{outline:none;border-color:var(--maroon);}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:3.5rem 1.5rem;}
.empty-state i{font-size:2.8rem;color:var(--bdr);margin-bottom:1rem;}
.empty-state h4{font-family:'Poppins',sans-serif;font-size:1rem;font-weight:700;color:var(--t2);margin-bottom:.4rem;}
.empty-state p{font-size:.82rem;color:var(--t3);}

/* ── PAGINATION ── */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.4rem;border-top:1px solid var(--bdr);flex-wrap:wrap;gap:.5rem;}
.pg-info{font-size:.75rem;color:var(--t3);font-weight:700;}
.pg-btns{display:flex;align-items:center;gap:.3rem;}
.pg-btn{width:32px;height:32px;border-radius:var(--r1);border:1px solid var(--bdr);background:var(--surf2);color:var(--t2);font-size:.78rem;font-weight:700;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .18s;font-family:'Nunito',sans-serif;}
.pg-btn:hover{background:var(--maroon);color:#fff;border-color:var(--maroon-d);}
.pg-btn.active{background:var(--maroon);color:#fff;border-color:var(--maroon-d);box-shadow:0 3px 0 var(--maroon-d);}
.pg-btn.disabled{opacity:.38;pointer-events:none;}

/* ── MODAL ── */
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
.m-x{width:28px;height:28px;background:rgba(255,255,255,.1);border:none;border-radius:50%;color:rgba(255,255,255,.65);font-size:.88rem;cursor:pointer;transition:all .18s;display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;z-index:1;}
.m-x:hover{background:rgba(255,255,255,.22);color:#fff;transform:rotate(90deg);}
.m-body{padding:1.5rem 1.65rem;}
.m-foot{padding:1rem 1.65rem 1.4rem;border-top:1px solid var(--bdr);display:flex;justify-content:flex-end;gap:.55rem;}
.dr2{display:flex;gap:1rem;padding:.55rem 0;border-bottom:1px solid var(--bdr);align-items:flex-start;}
.dr2:last-child{border:none;}
.dk2{width:120px;flex-shrink:0;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);}
.dv2{font-size:.84rem;color:var(--t1);flex:1;line-height:1.55;}

/* ── TOAST ── */
.ttray{position:fixed;bottom:1.75rem;right:1.75rem;display:flex;flex-direction:column;gap:.42rem;z-index:9999;}
.toast{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r2);padding:.8rem 1rem;display:flex;align-items:flex-start;gap:.6rem;box-shadow:var(--s3);min-width:260px;border-left:4px solid var(--maroon);transform:translateX(80px);opacity:0;transition:all .3s cubic-bezier(.34,1.4,.64,1);}
.toast.show{transform:translateX(0);opacity:1;}
.toast.ok{border-left-color:#145A32;}.toast.err{border-left-color:#7B241C;}
.t-ic{font-size:.95rem;color:var(--maroon);margin-top:1px;}
.toast.ok .t-ic{color:#145A32;}.toast.err .t-ic{color:#7B241C;}
.t-txt{font-size:.81rem;font-weight:700;color:var(--t1);}
.t-sub{font-size:.72rem;color:var(--t2);margin-top:1px;}

/* ── RESPONSIVE ── */
@media(max-width:768px){
  .sidebar{transform:translateX(-100%);}
  .sidebar.open{transform:translateX(0);}
  .main{margin-left:0;}
  .content{padding:1.25rem 1rem;}
  .topbar{padding:0 1rem;}
  .mob-btn{display:flex;}
  .date-pill{display:none;}
  .summary-strip{gap:.6rem;}
  .sum-card{min-width:120px;}
  .filter-bar{gap:.5rem;}
}
</style>
</head>
<body>
<div class="layout">

<!-- ════════════════════════════
     SIDEBAR
════════════════════════════ -->
<aside class="sidebar" id="sidebar">

  <div class="sb-seal-area">
    <div class="seal-ring">
      <div class="seal-inner">
        <i class="fas fa-tools"></i>
        <span>BEC</span>
      </div>
    </div>
    <div class="sb-school-name">
      <strong>Batangas Eastern</strong>
      <span>Colleges · Est. 1940</span>
    </div>
  </div>

  <div class="sb-divider"></div>

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
    <div class="nav-sep">Main</div>
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
      Task History
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

<!-- ════════════════════════════
     MAIN
════════════════════════════ -->
<div class="main">

  <!-- ── TOPBAR ── -->
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

  <!-- ── CONTENT ── -->
  <div class="content">

    <!-- Page Header -->
    <div class="page-header">
      <div class="ph-left">
        <div class="ph-eyebrow"><i class="fas fa-clipboard-list"></i> Task Management</div>
        <div class="ph-title">My Assigned Tasks</div>
        <div class="ph-sub">View, filter, and update the status of all your maintenance work orders.</div>
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
          <input type="text" name="search" placeholder="Search by ID, equipment, or issue…"
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
        <span class="panel-count">
          Showing <?php echo min($offset+1, $total_tasks); ?>–<?php echo min($offset+$per_page, $total_tasks); ?> of <?php echo $total_tasks; ?> task<?php echo $total_tasks!==1?'s':''; ?>
        </span>
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
?>
            <tr>
              <td><span class="tbl-id"><?php echo esc($rid); ?></span></td>
              <td>
                <div class="tbl-name"><?php echo esc($t['equipment_name'] ?? 'Equipment'); ?></div>
                <div class="tbl-sub"><?php echo esc($t['location'] ?: 'Unspecified'); ?></div>
              </td>
              <td>
                <div class="tbl-issue"><?php echo esc(mb_strimwidth((string)($t['issue_description']??'No description.'),0,90,'…')); ?></div>
              </td>
              <td><span class="badge <?php echo esc($pb); ?>"><?php echo esc(ucfirst($p)); ?></span></td>
              <td><span class="badge <?php echo esc($sb); ?>"><?php echo esc(ucwords(str_replace('_',' ',$st))); ?></span></td>
              <td style="font-size:.78rem;color:var(--t3);"><?php echo esc(fmtDateShort($t['report_date']??null)); ?></td>
              <td>
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

<!-- ════════════════════════════
     STATUS UPDATE MODAL
════════════════════════════ -->
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
          <div class="dv2" id="smTaskId" style="font-family:'Poppins',sans-serif;font-weight:800;color:var(--maroon);"></div>
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
            <select name="new_status" class="fb-select" style="width:100%;" required>
              <option value="">— Select status —</option>
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
/* ── Date ── */
document.getElementById('currentDate').textContent =
  new Date().toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});

/* ── Sidebar mobile toggle ── */
document.addEventListener('click', function(e){
  const sb = document.getElementById('sidebar');
  if (sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.mob-btn')){
    sb.classList.remove('open');
  }
});

/* ── Status Modal ── */
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

/* ── Toast ── */
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