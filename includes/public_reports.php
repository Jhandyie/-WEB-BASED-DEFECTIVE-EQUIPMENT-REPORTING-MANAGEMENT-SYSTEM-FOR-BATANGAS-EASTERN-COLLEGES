<?php
// public_reports.php
// Public-facing page — no login required
require_once __DIR__ . '/../config/database.php';

// ── Filters from GET ─────────────────────────────────────────────────────
$search   = trim($_GET['q']      ?? '');
$status   = trim($_GET['status'] ?? '');
$severity = trim($_GET['sev']    ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$offset   = ($page - 1) * $per_page;

$conn = getDBConnection();
$defectReportColumns = [];
$defectColsRes = $conn->query("SHOW COLUMNS FROM defect_reports");
if ($defectColsRes) {
    while ($col = $defectColsRes->fetch_assoc()) {
        $defectReportColumns[$col['Field']] = true;
    }
}

$where = [];
$types = '';
$params = [];

if (isset($defectReportColumns['is_public'])) {
    $where[] = "dr.is_public = 1";
} elseif (isset($defectReportColumns['admin_approval_status'])) {
    $where[] = "dr.admin_approval_status = 'approved'";
} else {
    $where[] = "dr.status IN ('reported','assigned','in_progress','completed','verified','closed')";
}

if ($search !== '') {
    $where[] = '(dr.report_id LIKE ? OR e.equipment_name LIKE ? OR e.location LIKE ? OR dr.issue_description LIKE ?)';
    $needle = "%{$search}%";
    array_push($params, $needle, $needle, $needle, $needle);
    $types .= 'ssss';
}

if ($severity !== '') {
    $where[] = 'dr.priority = ?';
    $params[] = strtolower($severity);
    $types .= 's';
}

if ($status === 'Open') {
    $where[] = "dr.status IN ('reported','assigned')";
} elseif ($status === 'In Progress') {
    $where[] = "dr.status = 'in_progress'";
} elseif ($status === 'Resolved') {
    $where[] = "dr.status IN ('completed','verified','closed')";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countSql = "
    SELECT COUNT(*) AS total
    FROM defect_reports dr
    JOIN equipment e ON dr.equipment_id = e.equipment_id
    LEFT JOIN categories c ON e.category_id = c.category_id
    {$whereSql}
";
$countStmt = $conn->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total_rows = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$sql = "
    SELECT
        dr.report_id AS ticket,
        e.equipment_name,
        COALESCE(c.category_name, CAST(e.category_id AS CHAR), 'Uncategorized') AS category,
        e.asset_tag,
        COALESCE(e.location, '') AS location,
        dr.priority AS severity,
        dr.status,
        COALESCE(e.status, '') AS equipment_status,
        COALESCE(e.condition_status, '') AS equipment_condition,
        dr.issue_description AS defect_description,
        dr.report_date AS created_at
    FROM defect_reports dr
    JOIN equipment e ON dr.equipment_id = e.equipment_id
    LEFT JOIN categories c ON e.category_id = c.category_id
    {$whereSql}
    ORDER BY dr.report_date DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$queryTypes = $types . 'ii';
$queryParams = [...$params, $per_page, $offset];
$stmt->bind_param($queryTypes, ...$queryParams);
$stmt->execute();
$reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$statsSql = "
    SELECT
        COUNT(*) AS total,
        SUM(dr.status IN ('reported','assigned')) AS open,
        SUM(dr.status = 'in_progress') AS in_progress,
        SUM(dr.status IN ('completed','verified','closed')) AS resolved,
        SUM(dr.priority = 'critical') AS critical
    FROM defect_reports dr
";
$statsScope = [];
if (isset($defectReportColumns['is_public'])) {
    $statsScope[] = "dr.is_public = 1";
} elseif (isset($defectReportColumns['admin_approval_status'])) {
    $statsScope[] = "dr.admin_approval_status = 'approved'";
} else {
    $statsScope[] = "dr.status IN ('reported','assigned','in_progress','completed','verified','closed')";
}
if ($statsScope) {
    $statsSql .= ' WHERE ' . implode(' AND ', $statsScope);
}
$stats = $conn->query($statsSql)->fetch_assoc() ?: [
    'total' => 0,
    'open' => 0,
    'in_progress' => 0,
    'resolved' => 0,
    'critical' => 0,
];

$suggestionsSql = "
    SELECT
        dr.report_id AS ticket,
        dr.status,
        dr.priority AS severity,
        COALESCE(e.equipment_id, '') AS equipment_id,
        COALESCE(e.asset_tag, '') AS asset_tag,
        COALESCE(e.equipment_name, '') AS equipment_name,
        COALESCE(e.location, '') AS location,
        COALESCE(c.category_name, CAST(e.category_id AS CHAR), 'Uncategorized') AS category,
        COALESCE(e.status, '') AS equipment_status,
        COALESCE(dr.issue_description, '') AS defect_description,
        dr.report_date AS created_at
    FROM defect_reports dr
    JOIN equipment e ON dr.equipment_id = e.equipment_id
    LEFT JOIN categories c ON e.category_id = c.category_id
";
if ($statsScope) {
    $suggestionsSql .= ' WHERE ' . implode(' AND ', $statsScope);
}
$suggestionsSql .= ' ORDER BY dr.report_date DESC LIMIT 80';

$publicSuggestions = [];
$suggestionsRes = $conn->query($suggestionsSql);
if ($suggestionsRes) {
    while ($row = $suggestionsRes->fetch_assoc()) {
        $publicSuggestions[] = [
            'ticket' => (string)($row['ticket'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'severity' => (string)($row['severity'] ?? ''),
            'equipment_id' => (string)($row['equipment_id'] ?? ''),
            'asset_tag' => (string)($row['asset_tag'] ?? ''),
            'equipment_name' => (string)($row['equipment_name'] ?? ''),
            'location' => (string)($row['location'] ?? ''),
            'category' => (string)($row['category'] ?? ''),
            'equipment_status' => (string)($row['equipment_status'] ?? ''),
            'defect_description' => (string)($row['defect_description'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }
}

$total_pages = max(1, ceil($total_rows / $per_page));

// ── Helpers ───────────────────────────────────────────────────────────────
function severity_class($s) {
    return match(strtolower((string)$s)) {
        'low'      => 'sev-low',
        'medium'   => 'sev-med',
        'high'     => 'sev-high',
        'critical' => 'sev-crit',
        default    => ''
    };
}
function status_class($s) {
    return match((string)$s) {
        'reported',
        'assigned' => 'st-open',
        'in_progress' => 'st-prog',
        'completed', 'verified', 'closed' => 'st-done',
        default       => ''
    };
}
function status_label($s) {
    return match((string)$s) {
        'reported',
        'assigned' => 'Open',
        'in_progress' => 'In Progress',
        'completed', 'verified', 'closed' => 'Resolved',
        default => ucfirst(str_replace('_', ' ', (string)$s))
    };
}
function priority_label($s) {
    return ucfirst(strtolower((string)$s));
}
function equipment_status_label($status) {
    return match (strtolower((string)$status)) {
        'available', 'operational' => 'Operational',
        'maintenance', 'under_maintenance' => 'Under Maintenance',
        'reserved', 'in_use', 'in use', 'borrowed' => 'In Use',
        'defective', 'faulty', 'damaged' => 'Needs Attention',
        default => $status !== '' ? ucwords(str_replace('_', ' ', (string)$status)) : 'Unknown',
    };
}
function equipment_status_class($status) {
    return match (strtolower((string)$status)) {
        'available', 'operational' => 'eq-ok',
        'maintenance', 'under_maintenance' => 'eq-maint',
        'reserved', 'in_use', 'in use', 'borrowed' => 'eq-use',
        'defective', 'faulty', 'damaged' => 'eq-bad',
        default => 'eq-unk',
    };
}
function public_issue_summary($text, $limit = 180) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if ($text === '') {
        return 'No summary available.';
    }
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
}
function public_location_label($location) {
    $location = trim((string)$location);
    if ($location === '') {
        return 'General campus area';
    }
    $location = preg_replace('/\b(room|rm)\s*[a-z0-9-]+\b/i', '$1 area', $location);
    $location = preg_replace('/\blab(oratory)?\s*[a-z0-9-]*\b/i', 'laboratory area', $location);
    $location = preg_replace('/\boffice\s*[a-z0-9-]*\b/i', 'office area', $location);
    $location = preg_replace('/\s+/', ' ', $location);
    return ucwords(trim($location));
}
function ago($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60).'m ago';
    if ($diff < 86400)  return floor($diff/3600).'h ago';
    if ($diff < 604800) return floor($diff/86400).'d ago';
    return date('M j, Y', strtotime($dt));
}
function build_url($extra=[]) {
    $p = array_merge(['q'=>$_GET['q']??'','status'=>$_GET['status']??'','sev'=>$_GET['sev']??'','page'=>$_GET['page']??1], $extra);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== '1' && $v !== 1 || in_array(array_search($v,[$p['q'],$p['status'],$p['sev'],$p['page']]), [3]));
    return '?' . http_build_query(array_filter($extra + ['q'=>$_GET['q']??'','status'=>$_GET['status']??'','sev'=>$_GET['sev']??''], fn($v)=>$v!==''));
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Public Reports — BEC Equipment</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --m:#7B1D1D;--md:#4A0E0E;--mdd:#2D0505;--ms:rgba(123,29,29,.08);--ml:rgba(123,29,29,.04);
  --g:#C9960C;--gl:#F0C040;--gb:#FFFBEF;
  --k:#1C1008;--k2:#5C3838;--k3:#9E8070;
  --p:#F8F3EA;--s:#FFFFFF;--b:#E8DDD0;--b2:#D5C8B8;
  --sh:0 1px 4px rgba(44,10,10,.05),0 4px 16px rgba(44,10,10,.07);
  --sh2:0 2px 8px rgba(44,10,10,.07),0 16px 48px rgba(44,10,10,.12);
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'DM Sans',sans-serif;background:var(--p);min-height:100vh;padding:0 0 4rem;position:relative;overflow-x:hidden;}
body::before{content:'';position:fixed;top:-200px;right:-200px;width:550px;height:550px;border-radius:50%;background:radial-gradient(circle,rgba(201,150,12,.1) 0%,transparent 65%);pointer-events:none;z-index:0;}
body::after{content:'';position:fixed;bottom:-160px;left:-160px;width:450px;height:450px;border-radius:50%;background:radial-gradient(circle,rgba(123,29,29,.08) 0%,transparent 65%);pointer-events:none;z-index:0;}
.bg-grid{position:fixed;inset:0;z-index:0;pointer-events:none;background-image:radial-gradient(circle,rgba(123,29,29,.1) 1px,transparent 1px);background-size:32px 32px;mask-image:radial-gradient(ellipse 80% 80% at 50% 50%,black 0%,transparent 100%);}

/* ── LAYOUT ── */
.page{max-width:1100px;margin:0 auto;padding:0 1.25rem;position:relative;z-index:1;}

/* ── TOPBAR ── */
.topbar{background:var(--s);border-bottom:1px solid var(--b);position:sticky;top:0;z-index:200;box-shadow:0 1px 8px rgba(44,10,10,.06);}
.topbar-inner{max-width:1100px;margin:0 auto;padding:.75rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;}
.logo-row{display:flex;align-items:center;gap:.65rem;flex-shrink:0;}
.seal{width:36px;height:36px;border-radius:50%;background:#fff;border:1px solid rgba(123,29,29,.14);display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 3px rgba(123,29,29,.15);overflow:hidden;}
.seal img{width:100%;height:100%;object-fit:cover;display:block;}
.lt strong{display:block;font-size:.8rem;font-weight:600;color:var(--k);}
.lt span{font-size:.62rem;color:var(--k3);text-transform:uppercase;letter-spacing:1.5px;}
.topbar-actions{display:flex;align-items:center;gap:.6rem;}
.tb-btn{display:flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:8px;font-size:.78rem;font-weight:500;text-decoration:none;transition:all .16s;border:1.5px solid var(--b);color:var(--k2);background:transparent;}
.tb-btn:hover{border-color:var(--m);color:var(--m);background:var(--ms);}
.tb-btn.primary{background:var(--md);color:#fff;border-color:var(--md);}
.tb-btn.primary:hover{background:var(--m);}
.tb-btn i{font-size:.7rem;}

/* ── PAGE HEADER ── */
.page-header{padding:2rem 0 1.5rem;animation:fadeUp .5s ease both;}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.eye{font-size:.65rem;font-weight:600;color:var(--m);text-transform:uppercase;letter-spacing:2px;margin-bottom:.35rem;display:flex;align-items:center;gap:.4rem;}
.eye::before{content:'';width:18px;height:2px;background:var(--m);}
h1{font-family:'Fraunces',serif;font-size:1.75rem;font-weight:700;color:var(--k);line-height:1.1;letter-spacing:-.02em;margin-bottom:.3rem;}
h1 em{font-style:italic;color:var(--m);}
.psub{font-size:.84rem;color:var(--k3);line-height:1.6;}

/* ── STAT CARDS ── */
.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:.75rem;margin-bottom:1.75rem;animation:fadeUp .5s ease .06s both;}
.stat{background:var(--s);border:1px solid var(--b);border-radius:14px;padding:1rem 1.1rem;box-shadow:var(--sh);position:relative;overflow:hidden;transition:transform .18s,box-shadow .18s;}
.stat:hover{transform:translateY(-2px);box-shadow:var(--sh2);}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.stat.s-total::before{background:var(--b2);}
.stat.s-open::before{background:#F87171;}
.stat.s-prog::before{background:#FB923C;}
.stat.s-done::before{background:#4ADE80;}
.stat.s-crit::before{background:#991B1B;}
.stat-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.7rem;margin-bottom:.6rem;}
.stat.s-total .stat-icon{background:rgba(92,56,56,.08);color:var(--k2);}
.stat.s-open  .stat-icon{background:#FEF2F2;color:#991B1B;}
.stat.s-prog  .stat-icon{background:#FFF7ED;color:#C2410C;}
.stat.s-done  .stat-icon{background:#F0FDF4;color:#166534;}
.stat.s-crit  .stat-icon{background:#FEF2F2;color:#7F1D1D;}
.stat-num{font-family:'Fraunces',serif;font-size:1.6rem;font-weight:700;color:var(--k);line-height:1;margin-bottom:.2rem;}
.stat-label{font-size:.68rem;color:var(--k3);font-weight:500;text-transform:uppercase;letter-spacing:.8px;}

/* ── TOOLBAR ── */
.toolbar{display:flex;align-items:center;gap:.65rem;margin-bottom:1.1rem;flex-wrap:wrap;animation:fadeUp .5s ease .1s both;}
.search-wrap{position:relative;flex:1;min-width:200px;}
.search-icon{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:var(--k3);font-size:.78rem;pointer-events:none;}
.search-input{width:100%;padding:.68rem 1rem .68rem 2.4rem;border:1.5px solid var(--b);border-radius:10px;font-family:'DM Sans',sans-serif;font-size:.86rem;color:var(--k);background:#fff;outline:none;transition:border-color .18s,box-shadow .18s;}
.search-input:focus{border-color:var(--m);box-shadow:0 0 0 3px rgba(123,29,29,.09);}
.search-input::placeholder{color:#C4AFA8;}
.search-dd{position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:40;background:var(--s);border:1.5px solid var(--m);border-radius:12px;box-shadow:0 10px 30px rgba(44,10,10,.15);max-height:280px;overflow-y:auto;display:none}
.search-dd.open{display:block}
.search-item{display:flex;gap:.7rem;align-items:flex-start;padding:.7rem .85rem;cursor:pointer;border-top:1px solid rgba(232,221,208,.7)}
.search-item:first-child{border-top:none}
.search-item:hover,.search-item.focused{background:#fcf6ed}
.search-icon-chip{width:28px;height:28px;border-radius:50%;background:rgba(123,29,29,.08);color:var(--m);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.8rem}
.search-copy{display:grid;gap:.15rem;min-width:0}
.search-title{font-weight:700;overflow-wrap:anywhere}
.search-meta{font-size:.75rem;color:var(--k3);line-height:1.45;overflow-wrap:anywhere}
.search-empty{padding:.85rem 1rem;font-size:.84rem;color:var(--k3);text-align:center}
.filter-sel{padding:.68rem 2rem .68rem .85rem;border:1.5px solid var(--b);border-radius:10px;font-family:'DM Sans',sans-serif;font-size:.83rem;color:var(--k2);background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%239E8070' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right .65rem center;-webkit-appearance:none;outline:none;cursor:pointer;transition:border-color .18s;}
.filter-sel:focus{border-color:var(--m);}
.result-count{font-size:.76rem;color:var(--k3);white-space:nowrap;}
.clear-link{font-size:.75rem;color:var(--m);text-decoration:none;font-weight:600;white-space:nowrap;}
.clear-link:hover{text-decoration:underline;}

/* ── TABLE ── */
.table-wrap{background:var(--s);border:1px solid var(--b);border-radius:16px;overflow:hidden;box-shadow:var(--sh);animation:fadeUp .5s ease .14s both;}
.table-scroll{overflow-x:auto;}
table{width:100%;border-collapse:collapse;min-width:760px;}
thead{background:var(--p);border-bottom:1px solid var(--b);}
th{padding:.75rem 1rem;font-size:.67rem;font-weight:600;color:var(--k3);text-transform:uppercase;letter-spacing:.9px;text-align:left;white-space:nowrap;}
th:first-child{padding-left:1.25rem;}
th:last-child{padding-right:1.25rem;}
tbody tr{border-bottom:1px solid var(--b);transition:background .14s;cursor:pointer;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:var(--ml);}
td{padding:.85rem 1rem;font-size:.83rem;color:var(--k);vertical-align:middle;}
td:first-child{padding-left:1.25rem;}
td:last-child{padding-right:1.25rem;}
.ticket-cell{font-family:'Fraunces',serif;font-weight:700;font-size:.82rem;color:var(--m);letter-spacing:.03em;white-space:nowrap;}
.equip-name{font-weight:600;color:var(--k);margin-bottom:.12rem;font-size:.84rem;}
.equip-cat{font-size:.68rem;color:var(--k3);}
.loc-main{font-weight:500;color:var(--k);margin-bottom:.1rem;font-size:.82rem;}
.loc-sub{font-size:.68rem;color:var(--k3);}
.date-main{font-size:.8rem;color:var(--k2);white-space:nowrap;}
.date-ago{font-size:.68rem;color:var(--k3);margin-top:.08rem;}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .6rem;border-radius:20px;font-size:.68rem;font-weight:600;white-space:nowrap;}
.badge::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;}
.sev-low {background:#F0FDF4;color:#166534;}
.sev-med {background:#FFFBEB;color:#92400E;}
.sev-high{background:#FFF7ED;color:#C2410C;}
.sev-crit{background:#FEF2F2;color:#991B1B;}
.st-open {background:#FEF2F2;color:#991B1B;}
.st-prog {background:#FFF7ED;color:#C2410C;}
.st-done {background:#F0FDF4;color:#166534;}
.eq-ok{background:#F0FDF4;color:#166534;}
.eq-maint{background:#FFF7ED;color:#C2410C;}
.eq-use{background:#EBF5FF;color:#1D4ED8;}
.eq-bad{background:#FEF2F2;color:#991B1B;}
.eq-unk{background:#F3F4F6;color:#4B5563;}
.photo-chip{display:inline-flex;align-items:center;gap:.25rem;font-size:.68rem;color:var(--m);background:var(--ms);border-radius:20px;padding:.18rem .5rem;}
.no-photo{font-size:.68rem;color:var(--k3);}

/* ── EMPTY STATE ── */
.empty{text-align:center;padding:4rem 1rem;}
.empty-icon{font-size:2rem;color:var(--b2);margin-bottom:.75rem;}
.empty-title{font-family:'Fraunces',serif;font-size:1.1rem;font-weight:700;color:var(--k);margin-bottom:.35rem;}
.empty-sub{font-size:.82rem;color:var(--k3);}

/* ── PAGINATION ── */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-top:1px solid var(--b);}
.pg-info{font-size:.76rem;color:var(--k3);}
.pg-btns{display:flex;gap:.4rem;}
.pg-btn{padding:.4rem .75rem;border:1.5px solid var(--b);border-radius:8px;font-size:.78rem;color:var(--k2);text-decoration:none;transition:all .16s;font-family:'DM Sans',sans-serif;}
.pg-btn:hover{border-color:var(--m);color:var(--m);}
.pg-btn.active{background:var(--md);color:#fff;border-color:var(--md);}
.pg-btn.disabled{opacity:.4;pointer-events:none;}

/* ── MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(20,5,5,.6);z-index:500;display:none;align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(3px);}
.modal-overlay.open{display:flex;animation:fadeIn .2s ease;}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal{background:var(--s);border-radius:20px;width:100%;max-width:620px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(20,5,5,.35);animation:modalUp .3s cubic-bezier(.22,1,.36,1);}
@keyframes modalUp{from{opacity:0;transform:translateY(24px) scale(.97)}to{opacity:1;transform:none}}
.modal-bar{height:4px;background:linear-gradient(90deg,var(--mdd),var(--m),var(--g));border-radius:20px 20px 0 0;}
.modal-head{display:flex;align-items:flex-start;justify-content:space-between;padding:1.5rem 1.5rem .85rem;border-bottom:1px solid var(--b);}
.modal-ticket{font-family:'Fraunces',serif;font-size:1rem;font-weight:700;color:var(--m);letter-spacing:.04em;margin-bottom:.25rem;}
.modal-equip{font-family:'Fraunces',serif;font-size:1.25rem;font-weight:700;color:var(--k);line-height:1.2;}
.modal-close{width:30px;height:30px;border-radius:50%;border:1.5px solid var(--b);background:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--k3);font-size:.75rem;flex-shrink:0;margin-left:.75rem;transition:all .16s;}
.modal-close:hover{border-color:var(--m);color:var(--m);background:var(--ms);}
.modal-body{padding:1.25rem 1.5rem;}
.modal-badges{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:1.25rem;}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:.65rem;margin-bottom:1.25rem;}
.detail-item{background:var(--p);border:1px solid var(--b);border-radius:10px;padding:.65rem .85rem;}
.detail-item.full{grid-column:1/-1;}
.di-label{font-size:.63rem;font-weight:600;color:var(--k3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:.2rem;}
.di-value{font-size:.84rem;color:var(--k);font-weight:500;line-height:1.5;}
.di-value.desc{font-weight:400;line-height:1.65;}
.us-yes {color:#166534;} .us-part{color:#92400E;} .us-no{color:#991B1B;}
.modal-photo{margin-top:.5rem;}
.modal-photo img{width:100%;border-radius:10px;max-height:220px;object-fit:cover;border:1px solid var(--b);}
.modal-foot{padding:.85rem 1.5rem 1.25rem;border-top:1px solid var(--b);display:flex;gap:.6rem;justify-content:flex-end;}
.btn-track{padding:.65rem 1.15rem;background:var(--md);color:#fff;border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;transition:background .16s;}
.btn-track:hover{background:var(--m);}
.btn-close-modal{padding:.65rem 1.15rem;border:1.5px solid var(--b);border-radius:9px;color:var(--k2);font-size:.82rem;font-weight:500;background:none;cursor:pointer;transition:all .16s;}
.btn-close-modal:hover{border-color:var(--m);color:var(--m);}

/* ── RESPONSIVE ── */
@media(max-width:900px){.stats{grid-template-columns:repeat(3,1fr);}}
@media(max-width:600px){
  .stats{grid-template-columns:1fr 1fr;}
  .stat.s-crit{grid-column:1/-1;}
  h1{font-size:1.45rem;}
  .toolbar{flex-direction:column;align-items:stretch;}
  .topbar-actions .tb-btn span{display:none;}
  .detail-grid{grid-template-columns:1fr;}
}
</style>
</head>
<body>
<div class="bg-grid"></div>

<!-- ── TOPBAR ── -->
<div class="topbar">
  <div class="topbar-inner">
    <div class="logo-row">
      <div class="seal">
        <img src="assets/logs.png" alt="BEC logo">
      </div>
      <div class="lt">
        <strong>BEC Equipment Reporting</strong>
        <span>Public Reports</span>
      </div>
    </div>
    <div class="topbar-actions">
      <a href="track_report.php" class="tb-btn"><i class="fas fa-search"></i> <span>Track Ticket</span></a>
      <a href="student_index.php" class="tb-btn primary"><i class="fas fa-plus"></i> <span>New Report</span></a>
    </div>
  </div>
</div>

<div class="page">

  <!-- ── PAGE HEADER ── -->
  <div class="page-header">
    <div class="eye">Transparency Board</div>
    <h1>All <em>equipment reports</em> — public view.</h1>
    <p class="psub">Browse publicly visible equipment reports only. Personal identity and sensitive operational details are intentionally hidden.</p>
  </div>

  <!-- ── STAT CARDS ── -->
  <div class="stats">
    <div class="stat s-total">
      <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
      <div class="stat-num"><?= $stats['total'] ?></div>
      <div class="stat-label">Total Reports</div>
    </div>
    <div class="stat s-open">
      <div class="stat-icon"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-num"><?= $stats['open'] ?></div>
      <div class="stat-label">Open</div>
    </div>
    <div class="stat s-prog">
      <div class="stat-icon"><i class="fas fa-tools"></i></div>
      <div class="stat-num"><?= $stats['in_progress'] ?></div>
      <div class="stat-label">In Progress</div>
    </div>
    <div class="stat s-done">
      <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div class="stat-num"><?= $stats['resolved'] ?></div>
      <div class="stat-label">Resolved</div>
    </div>
    <div class="stat s-crit">
      <div class="stat-icon"><i class="fas fa-fire"></i></div>
      <div class="stat-num"><?= $stats['critical'] ?></div>
      <div class="stat-label">Critical</div>
    </div>
  </div>

  <!-- ── TOOLBAR ── -->
  <form method="GET" action="">
    <div class="toolbar">
      <div class="search-wrap">
        <i class="fas fa-search search-icon"></i>
        <input type="text" name="q" id="public-search" class="search-input"
          placeholder="Search by ticket, equipment, location, or issue…"
          value="<?= htmlspecialchars($search) ?>" autocomplete="off">
        <div class="search-dd" id="public-dropdown"></div>
      </div>
      <select name="status" class="filter-sel" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="Open"        <?= $status==='Open'        ?'selected':'' ?>>Open</option>
        <option value="In Progress" <?= $status==='In Progress' ?'selected':'' ?>>In Progress</option>
        <option value="Resolved"    <?= $status==='Resolved'    ?'selected':'' ?>>Resolved</option>
      </select>
      <select name="sev" class="filter-sel" onchange="this.form.submit()">
        <option value="">All Severities</option>
        <option value="Low"      <?= $severity==='Low'      ?'selected':'' ?>>Low</option>
        <option value="Medium"   <?= $severity==='Medium'   ?'selected':'' ?>>Medium</option>
        <option value="High"     <?= $severity==='High'     ?'selected':'' ?>>High</option>
        <option value="Critical" <?= $severity==='Critical' ?'selected':'' ?>>Critical</option>
      </select>
      <button type="submit" style="display:none"></button>
      <span class="result-count"><?= $total_rows ?> result<?= $total_rows!==1?'s':'' ?></span>
      <?php if($search||$status||$severity): ?>
        <a href="public_reports.php" class="clear-link"><i class="fas fa-times" style="font-size:.65rem;margin-right:.2rem"></i>Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- ── TABLE ── -->
  <div class="table-wrap">
    <div class="table-scroll">
      <?php if(empty($reports)): ?>
        <div class="empty">
          <div class="empty-icon"><i class="fas fa-folder-open"></i></div>
          <div class="empty-title">No reports found</div>
          <div class="empty-sub">Try adjusting your search or filters.</div>
        </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Ticket</th>
            <th>Equipment</th>
            <th>Location</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Equipment</th>
            <th>Issue Summary</th>
            <th>Submitted</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($reports as $r): ?>
          <tr onclick="openModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
            <td><div class="ticket-cell"><?= htmlspecialchars($r['ticket']) ?></div></td>
            <td>
              <div class="equip-name"><?= htmlspecialchars($r['equipment_name']) ?></div>
              <div class="equip-cat"><?= htmlspecialchars($r['category']) ?></div>
            </td>
            <td>
              <div class="loc-main"><?= htmlspecialchars(public_location_label($r['location'] ?? '')) ?></div>
            </td>
            <td><span class="badge <?= severity_class($r['severity']) ?>"><?= htmlspecialchars(priority_label($r['severity'])) ?></span></td>
            <td><span class="badge <?= status_class($r['status']) ?>"><?= htmlspecialchars(status_label($r['status'])) ?></span></td>
            <td><span class="badge <?= equipment_status_class($r['equipment_status'] ?? '') ?>"><?= htmlspecialchars(equipment_status_label($r['equipment_status'] ?? '')) ?></span></td>
            <td style="max-width:260px;">
              <div style="line-height:1.45;color:var(--k2);"><?= htmlspecialchars(public_issue_summary($r['defect_description'] ?? '')) ?></div>
            </td>
            <td>
              <div class="date-main"><?= date('M j, Y', strtotime($r['created_at'])) ?></div>
              <div class="date-ago"><?= ago($r['created_at']) ?></div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- PAGINATION -->
    <?php if($total_pages > 1 || $total_rows > 0): ?>
    <div class="pagination">
      <span class="pg-info">
        Showing <?= min($offset+1,$total_rows) ?>–<?= min($offset+$per_page,$total_rows) ?> of <?= $total_rows ?> reports
      </span>
      <div class="pg-btns">
        <a href="?q=<?=urlencode($search)?>&status=<?=urlencode($status)?>&sev=<?=urlencode($severity)?>&page=<?=$page-1?>"
           class="pg-btn <?= $page<=1?'disabled':'' ?>"><i class="fas fa-chevron-left" style="font-size:.65rem"></i></a>
        <?php for($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
          <a href="?q=<?=urlencode($search)?>&status=<?=urlencode($status)?>&sev=<?=urlencode($severity)?>&page=<?=$i?>"
             class="pg-btn <?= $i===$page?'active':'' ?>"><?=$i?></a>
        <?php endfor; ?>
        <a href="?q=<?=urlencode($search)?>&status=<?=urlencode($status)?>&sev=<?=urlencode($severity)?>&page=<?=$page+1?>"
           class="pg-btn <?= $page>=$total_pages?'disabled':'' ?>"><i class="fas fa-chevron-right" style="font-size:.65rem"></i></a>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /page -->

<!-- ── MODAL ── -->
<div class="modal-overlay" id="modal-overlay" onclick="closeModal(event)">
  <div class="modal" id="modal">
    <div class="modal-bar"></div>
    <div class="modal-head">
      <div>
        <div class="modal-ticket" id="m-ticket"></div>
        <div class="modal-equip" id="m-equip"></div>
      </div>
      <button class="modal-close" onclick="closeModalDirect()"><i class="fas fa-times"></i></button>
    </div>
      <div class="modal-body">
      <div class="modal-badges" id="m-badges"></div>
      <div style="font-size:.76rem;color:var(--k3);line-height:1.55;margin:0 0 1rem;">Public view shows safe report details only. Reporter identity, contact info, and internal admin notes are hidden.</div>
      <div class="detail-grid">
        <div class="detail-item">
          <div class="di-label"><i class="fas fa-tag" style="margin-right:.3rem;font-size:.6rem"></i>Category</div>
          <div class="di-value" id="m-cat"></div>
        </div>
        <div class="detail-item">
          <div class="di-label"><i class="fas fa-map-marker-alt" style="margin-right:.3rem;font-size:.6rem"></i>Location</div>
          <div class="di-value" id="m-location"></div>
        </div>
        <div class="detail-item">
          <div class="di-label"><i class="fas fa-clock" style="margin-right:.3rem;font-size:.6rem"></i>Report Submitted</div>
          <div class="di-value" id="m-submitted"></div>
        </div>
        <div class="detail-item">
          <div class="di-label"><i class="fas fa-microchip" style="margin-right:.3rem;font-size:.6rem"></i>Equipment Status</div>
          <div class="di-value" id="m-eq-status"></div>
        </div>
        <div class="detail-item full">
          <div class="di-label"><i class="fas fa-align-left" style="margin-right:.3rem;font-size:.6rem"></i>Issue Summary</div>
          <div class="di-value desc" id="m-desc"></div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn-close-modal" onclick="closeModalDirect()">Close</button>
      <a id="m-track-link" href="track_report.php" class="btn-track">
        <i class="fas fa-search" style="font-size:.72rem"></i> Track This Report
      </a>
    </div>
  </div>
</div>

<script>
const publicSearchInput = document.getElementById('public-search');
const publicSearchDropdown = document.getElementById('public-dropdown');
const publicSearchData = <?php echo json_encode($publicSuggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let publicFocusIdx = -1;
let publicRenderTimer = null;

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  })[char]);
}

function publicBadgeLabel(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'reported' || normalized === 'assigned') return 'Open';
  if (normalized === 'in_progress') return 'In Progress';
  if (normalized === 'completed' || normalized === 'verified' || normalized === 'closed') return 'Resolved';
  return normalized ? normalized.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'Report';
}

function renderPublicDropdown(query) {
  const q = String(query || '').trim().toLowerCase();
  const matches = q
    ? publicSearchData.filter(item =>
        (item.ticket || '').toLowerCase().includes(q) ||
        (item.equipment_id || '').toLowerCase().includes(q) ||
        (item.asset_tag || '').toLowerCase().includes(q) ||
        (item.equipment_name || '').toLowerCase().includes(q) ||
        (item.location || '').toLowerCase().includes(q)
      )
    : publicSearchData.slice(0, 8);

  if (!matches.length) {
    publicSearchDropdown.innerHTML = '<div class="search-empty">No matching public reports found yet.</div>';
    publicSearchDropdown.classList.add('open');
    publicFocusIdx = -1;
    return;
  }

  publicSearchDropdown.innerHTML = matches.slice(0, 8).map(item => {
    const title = item.ticket || item.equipment_id || item.asset_tag || 'Reference';
    const refs = [item.equipment_id, item.asset_tag].filter(Boolean).join(' · ');
    const detailParts = [item.equipment_name, refs, item.location].filter(Boolean);
    const submitValue = item.ticket || item.equipment_id || item.asset_tag || '';
    return `
      <div class="search-item" data-index="${publicSearchData.indexOf(item)}" data-value="${escapeHtml(submitValue)}">
        <span class="search-icon-chip"><i class="fas fa-search"></i></span>
        <span class="search-copy">
          <span class="search-title">${escapeHtml(title)}</span>
          <span class="search-meta">${escapeHtml(detailParts.join(' • '))}</span>
          <span class="search-meta">${escapeHtml(publicBadgeLabel(item.status || ''))}</span>
        </span>
      </div>
    `;
  }).join('');

  publicSearchDropdown.classList.add('open');
  publicFocusIdx = -1;

  publicSearchDropdown.querySelectorAll('.search-item').forEach(el => {
    el.addEventListener('mousedown', event => {
      event.preventDefault();
      const match = publicSearchData[Number(el.dataset.index)];
      publicSearchInput.value = el.dataset.value || '';
      publicSearchDropdown.classList.remove('open');
      if (match) {
        openModal(match);
      } else {
        publicSearchInput.form?.requestSubmit();
      }
    });
  });
}

if (publicSearchInput && publicSearchDropdown) {
  publicSearchInput.addEventListener('input', () => {
    clearTimeout(publicRenderTimer);
    publicRenderTimer = setTimeout(() => {
      renderPublicDropdown(publicSearchInput.value);
    }, 120);
  });

  publicSearchInput.addEventListener('focus', () => {
    renderPublicDropdown(publicSearchInput.value);
  });

  publicSearchInput.addEventListener('blur', () => {
    setTimeout(() => publicSearchDropdown.classList.remove('open'), 150);
  });

  publicSearchInput.addEventListener('keydown', event => {
    const items = publicSearchDropdown.querySelectorAll('.search-item');
    if (!items.length) {
      if (event.key === 'Escape') {
        publicSearchDropdown.classList.remove('open');
      }
      return;
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      publicFocusIdx = Math.min(publicFocusIdx + 1, items.length - 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      publicFocusIdx = Math.max(publicFocusIdx - 1, 0);
    } else if (event.key === 'Enter' && publicFocusIdx >= 0) {
      event.preventDefault();
      items[publicFocusIdx].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
      return;
    } else if (event.key === 'Escape') {
      publicSearchDropdown.classList.remove('open');
      return;
    } else {
      return;
    }

    items.forEach((el, index) => el.classList.toggle('focused', index === publicFocusIdx));
    items[publicFocusIdx]?.scrollIntoView({ block: 'nearest' });
  });
}

function openModal(r) {
  const sevCls  = {low:'sev-low',medium:'sev-med',high:'sev-high',critical:'sev-crit'};
  const stCls   = {reported:'st-open',assigned:'st-open',in_progress:'st-prog',completed:'st-done',verified:'st-done',closed:'st-done'};
  const statusLabel = {reported:'Open',assigned:'Open',in_progress:'In Progress',completed:'Resolved',verified:'Resolved',closed:'Resolved'};
  const priorityLabel = v => v ? v.charAt(0).toUpperCase() + v.slice(1).toLowerCase() : '—';
  const eqStatusLabel = value => {
    const v = (value || '').toLowerCase();
    if (v === 'available' || v === 'operational') return 'Operational';
    if (v === 'maintenance' || v === 'under_maintenance') return 'Under Maintenance';
    if (v === 'reserved' || v === 'in_use' || v === 'in use' || v === 'borrowed') return 'In Use';
    if (v === 'defective' || v === 'faulty' || v === 'damaged') return 'Needs Attention';
    return value || 'Unknown';
  };
  const publicLocation = value => {
    const cleaned = (value || '')
      .replace(/\b(room|rm)\s*[a-z0-9-]+\b/ig, '$1 area')
      .replace(/\blab(oratory)?\s*[a-z0-9-]*\b/ig, 'laboratory area')
      .replace(/\boffice\s*[a-z0-9-]*\b/ig, 'office area')
      .trim();
    return cleaned || 'General campus area';
  };

  document.getElementById('m-ticket').textContent = r.ticket;
  document.getElementById('m-equip').textContent  = r.equipment_name;
  document.getElementById('m-cat').textContent     = r.category;
  document.getElementById('m-location').textContent = publicLocation(r.location);
  document.getElementById('m-desc').textContent    = r.defect_description || 'No summary available.';
  document.getElementById('m-submitted').textContent  = r.created_at ? new Date(r.created_at).toLocaleString('en-PH',{dateStyle:'medium',timeStyle:'short'}) : '—';
  document.getElementById('m-eq-status').textContent = eqStatusLabel(r.equipment_status);
  document.getElementById('m-track-link').href = 'track_report.php?ticket=' + encodeURIComponent(r.ticket);

  // Badges
  const badges = document.getElementById('m-badges');
  badges.innerHTML = `
    <span class="badge ${sevCls[(r.severity || '').toLowerCase()]||''}">${priorityLabel(r.severity || '')}</span>
    <span class="badge ${stCls[r.status]||''}">${statusLabel[r.status] || r.status}</span>`;

  document.getElementById('modal-overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal(e) {
  if (e.target === document.getElementById('modal-overlay')) closeModalDirect();
}
function closeModalDirect() {
  document.getElementById('modal-overlay').classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModalDirect(); });
</script>
</body>
</html>
