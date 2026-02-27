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
function fmtDateFull($dt) {
    if (empty($dt)) return 'N/A';
    $ts = strtotime((string)$dt);
    return $ts ? date('F j, Y', $ts) : 'N/A';
}
function daysBetween($start, $end) {
    if (empty($start) || empty($end)) return null;
    $ts1 = strtotime((string)$start);
    $ts2 = strtotime((string)$end);
    if (!$ts1 || !$ts2) return null;
    return max(0, (int)floor(abs($ts2 - $ts1) / 86400));
}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ initials ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
$initials = 'T';
if (!empty($technician_name)) {
    $parts    = explode(' ', trim($technician_name));
    $initials = count($parts) >= 2
        ? strtoupper(substr($parts[0],0,1).substr($parts[count($parts)-1],0,1))
        : strtoupper(substr($technician_name,0,2));
}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ filters ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
$filter_priority = trim($_GET['priority']   ?? '');
$filter_search   = trim($_GET['search']     ?? '');
$filter_month    = trim($_GET['month']      ?? '');   // YYYY-MM
$filter_year     = trim($_GET['year']       ?? '');   // YYYY
$current_page    = max(1,(int)($_GET['page'] ?? 1));
$per_page        = 12;

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ DB introspection ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
$conn   = getDBConnection();
$drCols = []; $eqCols = [];
try { $r=$conn->query('SHOW COLUMNS FROM defect_reports'); if($r) while($row=$r->fetch_assoc()) $drCols[$row['Field']]=true; } catch(Exception $e){}
try { $r=$conn->query('SHOW COLUMNS FROM equipment');      if($r) while($row=$r->fetch_assoc()) $eqCols[$row['Field']]=true; } catch(Exception $e){}

$assigneeCol    = isset($drCols['assigned_to'])        ? 'assigned_to'        : (isset($drCols['assigned_technician']) ? 'assigned_technician' : 'assigned_to');
$issueExpr      = isset($drCols['issue_description'])  ? 'r.issue_description' : (isset($drCols['defect_description'])  ? 'r.defect_description'  : "''");
$priorityExpr   = isset($drCols['priority'])           ? 'r.priority'          : "'medium'";
$statusExpr     = isset($drCols['status'])             ? 'r.status'            : "'completed'";
$reportDateExpr = isset($drCols['report_date'])        ? 'r.report_date'       : 'NOW()';
$completionExpr = isset($drCols['completion_date'])    ? 'r.completion_date'   : 'NULL';
$notesExpr      = isset($drCols['technician_notes'])   ? 'r.technician_notes'  : (isset($drCols['resolution_notes']) ? 'r.resolution_notes' : "NULL");
$eqIdCol        = isset($eqCols['equipment_id'])       ? 'equipment_id'        : (isset($eqCols['id'])   ? 'id'   : 'equipment_id');
$eqNameCol      = isset($eqCols['equipment_name'])     ? 'equipment_name'      : (isset($eqCols['name']) ? 'name' : 'equipment_name');
$eqLocationCol  = isset($eqCols['location'])           ? 'location'            : (isset($eqCols['room']) ? 'room' : 'location');
$eqJoin         = isset($drCols['equipment_name'])     ? '' : "LEFT JOIN equipment e ON e.{$eqIdCol} = r.equipment_id";
$equipmentExpr  = isset($drCols['equipment_name'])     ? 'r.equipment_name'    : "COALESCE(e.{$eqNameCol}, r.equipment_id)";
$locationExpr   = isset($drCols['location'])           ? 'r.location'          : "COALESCE(e.{$eqLocationCol}, '')";

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ completed statuses ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
$done_statuses = "'completed','verified','closed'";

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ build WHERE ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
$where_parts = ["r.{$assigneeCol} = ?", "{$statusExpr} IN ({$done_statuses})"];
$bind_types  = 's';
$bind_vals   = [$technician_id];

if ($filter_priority !== '') {
    $where_parts[] = "{$priorityExpr} = ?";
    $bind_types   .= 's';
    $bind_vals[]   = $filter_priority;
}
if ($filter_month !== '' && preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
    $where_parts[] = "DATE_FORMAT({$completionExpr}, '%Y-%m') = ?";
    $bind_types   .= 's';
    $bind_vals[]   = $filter_month;
}
if ($filter_year !== '' && preg_match('/^\d{4}$/', $filter_year)) {
    $where_parts[] = "YEAR({$completionExpr}) = ?";
    $bind_types   .= 's';
    $bind_vals[]   = $filter_year;
}
if ($filter_search !== '') {
    $where_parts[] = "({$equipmentExpr} LIKE ? OR {$issueExpr} LIKE ? OR r.report_id LIKE ?)";
    $bind_types   .= 'sss';
    $like          = '%'.$filter_search.'%';
    $bind_vals[]   = $like; $bind_vals[] = $like; $bind_vals[] = $like;
}

$where_sql = implode(' AND ', $where_parts);

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ summary stats (all-time, no extra filters) ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
$stat_total=0; $stat_critical=0; $stat_avg_days=null; $stat_this_month=0;
try {
    $sql_stat = "SELECT
                   COUNT(*) AS total,
                   SUM(CASE WHEN {$priorityExpr}='critical' THEN 1 ELSE 0 END) AS critical_cnt,
                   AVG(DATEDIFF(COALESCE({$completionExpr}, NOW()), {$reportDateExpr})) AS avg_days,
                   SUM(CASE WHEN DATE_FORMAT(COALESCE({$completionExpr},{$reportDateExpr}),'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m') THEN 1 ELSE 0 END) AS this_month
                 FROM defect_reports r {$eqJoin}
                 WHERE r.{$assigneeCol}=? AND {$statusExpr} IN ({$done_statuses})";
    $stmt_st = $conn->prepare($sql_stat);
    $stmt_st->bind_param('s', $technician_id);
    $stmt_st->execute();
    $row_st     = $stmt_st->get_result()->fetch_assoc();
    $stmt_st->close();
    $stat_total      = (int)($row_st['total']      ?? 0);
    $stat_critical   = (int)($row_st['critical_cnt']?? 0);
    $stat_avg_days   = $row_st['avg_days']!==null ? round((float)$row_st['avg_days'],1) : null;
    $stat_this_month = (int)($row_st['this_month'] ?? 0);
} catch(Exception $e){}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ available months for filter dropdown ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
$months_available = [];
try {
    $sql_mo = "SELECT DISTINCT DATE_FORMAT(COALESCE({$completionExpr},{$reportDateExpr}),'%Y-%m') AS ym
               FROM defect_reports r {$eqJoin}
               WHERE r.{$assigneeCol}=? AND {$statusExpr} IN ({$done_statuses})
               ORDER BY ym DESC LIMIT 36";
    $stmt_mo = $conn->prepare($sql_mo);
    $stmt_mo->bind_param('s', $technician_id);
    $stmt_mo->execute();
    $rows_mo = $stmt_mo->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_mo->close();
    foreach ($rows_mo as $rm) if($rm['ym']) $months_available[] = $rm['ym'];
} catch(Exception $e){}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ count query ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
$total_records = 0;
try {
    $stmt_c = $conn->prepare("SELECT COUNT(*) AS cnt FROM defect_reports r {$eqJoin} WHERE {$where_sql}");
    $stmt_c->bind_param($bind_types, ...$bind_vals);
    $stmt_c->execute();
    $total_records = (int)$stmt_c->get_result()->fetch_assoc()['cnt'];
    $stmt_c->close();
} catch(Exception $e){}

$total_pages  = max(1,(int)ceil($total_records/$per_page));
$current_page = min($current_page, $total_pages);
$offset       = ($current_page-1)*$per_page;

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ data query ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
$history = [];
try {
    $sql_data = "SELECT r.report_id,
                        {$equipmentExpr}  AS equipment_name,
                        {$issueExpr}      AS issue_description,
                        {$priorityExpr}   AS priority,
                        {$statusExpr}     AS status,
                        {$locationExpr}   AS location,
                        {$reportDateExpr} AS report_date,
                        {$completionExpr} AS completion_date,
                        {$notesExpr}      AS tech_notes
                 FROM defect_reports r {$eqJoin}
                 WHERE {$where_sql}
                 ORDER BY COALESCE({$completionExpr},{$reportDateExpr}) DESC
                 LIMIT ? OFFSET ?";
    $stmt_d = $conn->prepare($sql_data);
    $pg_types = $bind_types.'ii';
    $pg_vals  = array_merge($bind_vals, [$per_page, $offset]);
    $stmt_d->bind_param($pg_types, ...$pg_vals);
    $stmt_d->execute();
    $history = $stmt_d->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_d->close();
} catch(Exception $e){}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ helper: filter URL ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
function filterUrl(array $ov=[]): string {
    $params = [
        'priority' => $_GET['priority'] ?? '',
        'search'   => $_GET['search']   ?? '',
        'month'    => $_GET['month']    ?? '',
        'year'     => $_GET['year']     ?? '',
        'page'     => '1',
    ];
    foreach($ov as $k=>$v) $params[$k]=$v;
    $qs = http_build_query(array_filter($params, fn($v)=>$v!==''));
    return 'technician_history.php'.($qs?'?'.$qs:'');
}

$has_filters = $filter_priority||$filter_search||$filter_month||$filter_year;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="assets/logs.png">
<title>BEC Maintenance - Work History</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* -------------------------------------------
   BEC EQUIPMENT MANAGEMENT ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â TECHNICIAN PORTAL
   technician_history.php
------------------------------------------- */
:root{
  --maroon:#7B1D1D; --maroon-d:#521010; --maroon-l:#9B2C2C;
  --gold:#D4A017;   --gold-l:#F0C040;   --gold-p:#FEF9E7;
  --cream:#FFFDF8;  --surf:#FFFFFF;     --surf2:#FBF7F0;
  --bdr:#EDE0CC;    --t1:#1A0A0A;       --t2:#6B4040; --t3:#B08080;
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

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ SIDEBAR ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
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
.sb-user{margin:.75rem 1.1rem;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);border-radius:var(--r2);padding:.8rem 1rem;display:flex;align-items:center;gap:.7rem;cursor:default;position:relative;z-index:1;}
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

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ MAIN ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.main{margin-left:260px;width:calc(100% - 260px);min-height:100vh;display:flex;flex-direction:column;}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ TOPBAR ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
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
.tb-btn{width:38px;height:38px;background:var(--surf2);border:1px solid var(--bdr);border-radius:var(--r1);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--t2);font-size:.92rem;transition:all .2s;box-shadow:0 2px 0 var(--bdr);text-decoration:none;}
.tb-btn:hover{background:var(--maroon);color:#fff;transform:translateY(-2px);box-shadow:0 4px 0 var(--maroon-d);}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ CONTENT ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.content{padding:1.875rem 2rem;flex:1;}
@keyframes fadeUp{from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:translateY(0);}}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ PAGE HEADER ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;animation:fadeUp .45s ease both;}
.ph-eyebrow{font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:2px;color:var(--maroon);margin-bottom:.3rem;display:flex;align-items:center;gap:.4rem;}
.ph-title{font-family:'Poppins',sans-serif;font-size:1.55rem;font-weight:800;color:var(--t1);line-height:1.2;}
.ph-sub{font-size:.82rem;color:var(--t2);margin-top:.25rem;}
.section-strip{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;background:linear-gradient(135deg,#fff,#fff7e8);border:1px solid var(--bdr);border-left:4px solid var(--gold);border-radius:var(--r2);padding:.7rem .95rem;margin:-.15rem 0 1rem;animation:fadeUp .45s .02s ease both;} 
.section-strip .ss-label{font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:1.1px;color:var(--maroon);display:flex;align-items:center;gap:.38rem;} 
.section-strip .ss-text{font-size:.78rem;color:var(--t2);}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ STAT CARDS ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;animation:fadeUp .45s .05s ease both;}
.stat-card{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r2);padding:1.1rem 1.25rem;box-shadow:var(--s1);display:flex;align-items:center;gap:.85rem;transition:all .22s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--s2);}
.sc-ico{width:44px;height:44px;border-radius:var(--r2);display:flex;align-items:center;justify-content:center;font-size:1.05rem;flex-shrink:0;}
.sc-ico.total  {background:linear-gradient(135deg,var(--maroon-d),var(--maroon));color:var(--gold-l);}
.sc-ico.month  {background:var(--gold-p);color:var(--pc);}
.sc-ico.crit   {background:var(--rb);color:var(--rc);}
.sc-ico.speed  {background:var(--db);color:var(--dc);}
.sc-body{}
.sc-num{font-family:'Poppins',sans-serif;font-size:1.6rem;font-weight:800;color:var(--t1);line-height:1;}
.sc-lbl{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);margin-top:.15rem;}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ FILTER BAR ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.filter-bar{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r2);padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;box-shadow:var(--s1);animation:fadeUp .45s .1s ease both;}
.fb-search{flex:1;min-width:180px;position:relative;}
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
.btn-ghost{background:none;border:1px solid transparent;color:var(--t3);}
.btn-ghost:hover{background:var(--surf2);border-color:var(--bdr);color:var(--t1);}
.btn-sm{padding:.34rem .7rem;font-size:.76rem;}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ PANEL ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.panel{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r3);box-shadow:var(--s1);overflow:hidden;animation:fadeUp .45s .15s ease both;}
.panel-h{padding:1rem 1.4rem;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;}
.panel-h h3{font-family:'Poppins',sans-serif;font-size:.92rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:.4rem;margin:0;}
.panel-h h3 i{color:var(--maroon);}
.panel-count{font-size:.76rem;color:var(--t3);font-weight:700;}
.panel-h-link-btn{display:inline-flex;align-items:center;gap:.35rem;padding:.36rem .68rem;border:1px solid var(--bdr);border-radius:999px;background:var(--surf2);font-size:.7rem;font-weight:800;color:var(--t2);text-decoration:none;line-height:1;}
.panel-h-link-btn:hover{border-color:var(--maroon);background:#fff;color:var(--maroon);}
.summary-row{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;padding:.7rem 1.35rem;border-bottom:1px dashed var(--bdr);background:var(--surf2);}
.summary-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .58rem;border-radius:999px;border:1px solid var(--bdr);font-size:.68rem;font-weight:800;color:var(--t2);background:#fff;}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ TABLE ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.tbl-wrap{overflow-x:auto;}
.tbl{width:100%;border-collapse:collapse;min-width:760px;}
.tbl thead th{padding:.62rem 1.1rem;font-size:.65rem;text-transform:uppercase;letter-spacing:1px;color:var(--t3);font-weight:800;text-align:left;background:var(--surf2);border-bottom:1.5px solid var(--bdr);white-space:nowrap;}
.tbl tbody td{padding:.82rem 1.1rem;font-size:.82rem;color:var(--t1);border-bottom:1px solid var(--bdr);vertical-align:middle;}
.tbl tbody tr:last-child td{border-bottom:none;}
.tbl tbody tr{transition:background .12s,transform .1s;}
.tbl tbody tr:hover td{background:var(--surf2);}
.tbl tbody tr:hover{transform:translateX(3px);}
.tbl-id{font-family:'Poppins',sans-serif;font-weight:800;color:var(--maroon);font-size:.8rem;}
.tbl-name{font-weight:700;color:var(--t1);}
.tbl-sub{font-size:.68rem;color:var(--t3);margin-top:1px;}
.tbl-issue{font-size:.78rem;color:var(--t2);line-height:1.4;max-width:220px;}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ DURATION PILL ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.dur-pill{display:inline-flex;align-items:center;gap:.28rem;background:var(--surf2);border:1px solid var(--bdr);border-radius:20px;padding:.2rem .6rem;font-size:.68rem;font-weight:700;color:var(--t2);}
.dur-pill i{font-size:.6rem;color:var(--maroon);}
.dur-pill.fast{background:var(--db);border-color:#c3e6cb;color:var(--dc);}
.dur-pill.slow{background:var(--rb);border-color:#f5c6cb;color:var(--rc);}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ BADGES ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.badge{display:inline-flex;align-items:center;gap:.28rem;padding:.22rem .65rem;border-radius:20px;font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
.badge::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;}
.b-critical{background:#FDEDEC;color:#7B241C;}
.b-high    {background:#FEF0E7;color:#873600;}
.b-medium  {background:var(--ib);color:#154360;}
.b-low     {background:var(--surf2);color:var(--t3);border:1px solid var(--bdr);}
.b-done    {background:var(--db);color:#145A32;}
.b-verified{background:#EAF4FB;color:#1a5276;}
.b-closed  {background:var(--surf2);color:var(--t3);border:1px solid var(--bdr);}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ ACTION BUTTONS ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.act-btn{width:32px;height:32px;border-radius:var(--r1);display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;cursor:pointer;border:1px solid var(--bdr);background:var(--surf2);color:var(--t2);transition:all .2s;margin-right:3px;vertical-align:middle;}
.act-btn:hover{background:var(--maroon);color:#fff;border-color:var(--maroon-d);transform:translateY(-1px);box-shadow:0 3px 0 var(--maroon-d);}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ EMPTY STATE ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.empty-state{text-align:center;padding:4rem 1.5rem;}
.empty-state i{font-size:3rem;color:var(--bdr);margin-bottom:1rem;}
.empty-state h4{font-family:'Poppins',sans-serif;font-size:1rem;font-weight:700;color:var(--t2);margin-bottom:.4rem;}
.empty-state p{font-size:.82rem;color:var(--t3);}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ PAGINATION ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.4rem;border-top:1px solid var(--bdr);flex-wrap:wrap;gap:.5rem;}
.pg-info{font-size:.75rem;color:var(--t3);font-weight:700;}
.pg-btns{display:flex;align-items:center;gap:.3rem;}
.pg-btn{width:32px;height:32px;border-radius:var(--r1);border:1px solid var(--bdr);background:var(--surf2);color:var(--t2);font-size:.78rem;font-weight:700;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .18s;font-family:'Nunito',sans-serif;}
.pg-btn:hover{background:var(--maroon);color:#fff;border-color:var(--maroon-d);}
.pg-btn.active{background:var(--maroon);color:#fff;border-color:var(--maroon-d);box-shadow:0 3px 0 var(--maroon-d);}
.pg-btn.disabled{opacity:.38;pointer-events:none;}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ DETAIL MODAL ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.mo{position:fixed;inset:0;background:rgba(26,10,10,.62);backdrop-filter:blur(6px);z-index:500;display:none;align-items:center;justify-content:center;padding:1rem;}
.mo.open{display:flex;animation:moFI .18s ease;}
@keyframes moFI{from{opacity:0}to{opacity:1}}
.modal{background:var(--surf);border-radius:var(--r4);width:100%;max-width:600px;max-height:92vh;overflow-y:auto;box-shadow:var(--s3);animation:mUp .28s cubic-bezier(.4,0,.2,1);border:1px solid var(--bdr);}
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
.dk2{width:130px;flex-shrink:0;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);}
.dv2{font-size:.84rem;color:var(--t1);flex:1;line-height:1.55;}
.notes-box{margin-top:1.1rem;padding:1rem 1.1rem;background:var(--surf2);border-radius:var(--r2);border:1px solid var(--bdr);}
.notes-label{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--t3);margin-bottom:.4rem;}
.notes-text{font-size:.83rem;color:var(--t2);line-height:1.6;}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ TIMELINE STRIP ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
.timeline{position:relative;padding:1.2rem 1.4rem;}
.timeline::before{content:'';position:absolute;left:2.3rem;top:0;bottom:0;width:2px;background:var(--bdr);}
.tl-item{display:flex;align-items:flex-start;gap:.85rem;margin-bottom:1.1rem;position:relative;}
.tl-item:last-child{margin-bottom:0;}
.tl-dot{width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.75rem;position:relative;z-index:1;border:2px solid var(--surf);}
.tl-dot.reported {background:var(--gold-p);color:var(--pc);}
.tl-dot.started  {background:var(--ib);color:var(--ic);}
.tl-dot.completed{background:var(--db);color:var(--dc);}
.tl-dot.verified {background:#D6EAF8;color:#1a5276;}
.tl-body{}
.tl-title{font-size:.82rem;font-weight:700;color:var(--t1);}
.tl-date {font-size:.72rem;color:var(--t3);margin-top:.08rem;}

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ RESPONSIVE ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:768px){
  .sidebar{transform:translateX(-100%);}
  .sidebar.open{transform:translateX(0);}
  .main{margin-left:0;width:100%;}
  .content{padding:1.25rem 1rem;}
  .topbar{padding:0 1rem;}
  .mob-btn{display:flex;}
  .date-pill{display:none;}
  .stats-grid{grid-template-columns:1fr 1fr;}
  .filter-bar{gap:.5rem;}
}
/* panel parity with dashboard */
.panel{background:var(--surf);border-radius:var(--r3);border:1px solid var(--bdr);box-shadow:var(--s1);overflow:hidden;transition:box-shadow .25s;}
.panel:hover{box-shadow:var(--s2);}
.panel-h{padding:1rem 1.35rem;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;background:linear-gradient(to right,var(--surf2),var(--surf));}
.panel-h h3{font-family:'Poppins',sans-serif;font-size:.92rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:.4rem;margin:0;}
.panel-h h3 i{color:var(--maroon);}
/* DASHBOARD PANEL FORCE OVERRIDE */
.panel,
.task-details-card{
  background:var(--surf) !important;
  border:1px solid var(--bdr) !important;
  border-radius:var(--r3) !important;
  box-shadow:var(--s1) !important;
  overflow:hidden !important;
  transition:box-shadow .25s !important;
}
.panel:hover,
.task-details-card:hover{box-shadow:var(--s2) !important;}
.panel-h,
.task-header{
  padding:1rem 1.35rem !important;
  border-bottom:1px solid var(--bdr) !important;
  background:linear-gradient(to right,var(--surf2),var(--surf)) !important;
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
  gap:.5rem !important;
}
.panel-h h3,
.task-title h2{
  font-family:'Poppins',sans-serif !important;
  color:var(--t1) !important;
}
.panel-h h3{font-size:.92rem !important;font-weight:700 !important;}
.task-title h2{font-size:1.12rem !important;font-weight:800 !important;}
.panel-h h3 i{color:var(--maroon) !important;}
.task-content,
.info-item,
.task-description,
.task-notes,
.task-instructions,
.task-actions{
  background:var(--surf) !important;
  border-color:var(--bdr) !important;
}
.info-item,
.task-description,
.task-notes,
.task-instructions{box-shadow:var(--s1) !important;}
/* END DASHBOARD PANEL FORCE OVERRIDE */
/* My Tasks reference lock */
.sidebar{background:linear-gradient(168deg,#1E0202 0%,#350808 38%,#4A0E0E 68%,#3A0808 100%) !important;box-shadow:5px 0 30px rgba(45,5,5,.38) !important;}
.sb-seal-area{padding:1.4rem 1.25rem 1rem !important;border-bottom:1px solid rgba(255,255,255,.06) !important;display:flex !important;align-items:center !important;gap:.75rem !important;flex-direction:row !important;}
.seal-wrap{position:relative;flex-shrink:0;width:46px;height:46px;}
.seal-glow{position:absolute;inset:-3px;border-radius:50%;background:conic-gradient(var(--gold),var(--gold-l),var(--gold));animation:sglow 6s linear infinite;opacity:.7;}
@keyframes sglow{from{transform:rotate(0)}to{transform:rotate(360deg)}}
.seal-ring{display:none !important;}
.seal-inner{position:absolute !important;inset:2px !important;border-radius:50% !important;background:var(--maroon-d) !important;border:none !important;box-shadow:none !important;overflow:hidden !important;display:flex !important;align-items:center !important;justify-content:center !important;}
.seal-inner img{width:100%;height:100%;border-radius:50%;object-fit:cover;}
.sb-school-name{text-align:left !important;line-height:1.25;}
.sb-school-name strong{font-family:'Outfit',sans-serif;font-size:.8rem !important;font-weight:800 !important;color:#fff !important;}
.sb-school-name span{font-size:.57rem !important;color:rgba(255,255,255,.3) !important;text-transform:uppercase;letter-spacing:1.8px;}
.sb-divider{display:none !important;}
.nav-sep{font-size:.54rem !important;padding:.5rem 1.25rem .2rem !important;color:rgba(255,255,255,.18) !important;}
.nav-item{padding:.56rem 1.25rem !important;font-family:'DM Sans',sans-serif !important;font-size:.82rem !important;font-weight:500 !important;color:rgba(255,255,255,.42) !important;gap:.65rem !important;}
.nav-item .ni{width:30px !important;height:30px !important;font-size:.78rem !important;background:rgba(255,255,255,.05) !important;}
.sb-foot{padding:.55rem 1rem .95rem !important;}
.logout-btn{padding:.52rem .78rem !important;font-family:'DM Sans',sans-serif !important;font-size:.8rem !important;font-weight:500 !important;color:rgba(255,255,255,.42) !important;background:rgba(255,255,255,.04) !important;}
.u-av{width:32px !important;height:32px !important;font-size:.77rem !important;}
.u-name{font-size:.8rem !important;font-weight:600 !important;}
.u-role{font-size:.58rem !important;letter-spacing:1px !important;color:rgba(255,255,255,.32) !important;}
.tb-btn{width:32px !important;height:32px !important;}
.panel{background:var(--surf) !important;border:1px solid var(--bdr) !important;border-radius:var(--r3) !important;box-shadow:var(--s1) !important;display:flex;flex-direction:column;flex:1;min-height:420px;}
.panel-h{padding:1rem 1.4rem !important;border-bottom:1px solid var(--bdr) !important;background:var(--surf) !important;}
.panel-h h3{font-family:'Poppins',sans-serif !important;font-size:.92rem !important;font-weight:700 !important;color:var(--t1) !important;}
.panel-h h3 i{color:var(--maroon) !important;}
/* Shared Sidebar Parity */
.sidebar{position:fixed;left:0;top:0;width:260px;height:100vh;background:linear-gradient(168deg,#1E0202 0%,#350808 38%,#4A0E0E 68%,#3A0808 100%) !important;display:flex;flex-direction:column;z-index:300;box-shadow:5px 0 30px rgba(45,5,5,.38) !important;overflow:hidden}
.sidebar::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(212,160,23,.13),transparent);pointer-events:none}
.sb-seal{padding:1.4rem 1.25rem 1rem;border-bottom:1px solid rgba(255,255,255,.06);position:relative;z-index:1;display:flex;align-items:center;gap:.75rem}
.seal-wrap{position:relative;flex-shrink:0;width:46px;height:46px}
.seal-glow{position:absolute;inset:-3px;border-radius:50%;background:conic-gradient(var(--gold),var(--gold-l),var(--gold));animation:sglow 6s linear infinite;opacity:.7}
@keyframes sglow{from{transform:rotate(0)}to{transform:rotate(360deg)}}
.seal-inner{position:absolute;inset:2px;border-radius:50%;background:var(--maroon-d);display:flex;align-items:center;justify-content:center;overflow:hidden}
.seal-inner img{width:100%;height:100%;border-radius:50%;object-fit:cover}
.sb-brand strong{display:block;font-family:'Outfit',sans-serif;font-size:.8rem;font-weight:800;color:#fff;line-height:1.25}
.sb-brand span{font-size:.57rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:1.8px}
.sb-user{margin:.45rem 1rem .2rem;background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.07);border-radius:var(--r2);padding:.65rem .875rem;display:flex;align-items:center;gap:.65rem;position:relative;z-index:1}
.u-av{width:32px !important;height:32px !important;flex-shrink:0;background:linear-gradient(135deg,var(--gold),var(--maroon-l));border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-weight:800;font-size:.77rem !important;color:#fff;box-shadow:0 4px 0 rgba(0,0,0,.3),0 6px 14px rgba(0,0,0,.2)}
.u-name{display:block;font-size:.8rem !important;color:#fff;font-weight:600 !important}.u-meta{display:flex;align-items:center;gap:.3rem;margin-top:.1rem}
.u-dot{width:6px;height:6px;border-radius:50%;background:#4ade80;box-shadow:0 0 6px #4ade80}.u-role{font-size:.58rem !important;color:rgba(255,255,255,.32) !important;text-transform:uppercase;letter-spacing:1px !important}
.sb-nav{flex:1;padding:.25rem 0;overflow-y:auto;position:relative;z-index:1}
.nav-sep{font-size:.54rem !important;text-transform:uppercase;letter-spacing:2.5px;color:rgba(255,255,255,.18) !important;padding:.5rem 1.25rem .2rem !important;font-weight:700}
.nav-item{display:flex;align-items:center;gap:.65rem !important;padding:.56rem 1.25rem !important;color:rgba(255,255,255,.42) !important;background:none;border:none;width:100%;text-align:left;font-family:'DM Sans',sans-serif !important;font-size:.82rem !important;font-weight:500 !important;text-decoration:none;position:relative}
.nav-item .ni{width:30px !important;height:30px !important;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.78rem !important;background:rgba(255,255,255,.05) !important;flex-shrink:0}
.nav-item.active{color:#fff}.nav-item.active .ni{background:linear-gradient(135deg,var(--gold),var(--gold-l));color:var(--maroon-d)}
.nav-item.active::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(to bottom,var(--gold),var(--gold-l));border-radius:0 3px 3px 0}
.sb-foot{padding:.55rem 1rem .95rem !important;border-top:1px solid rgba(255,255,255,.07);position:relative;z-index:1}
.logout-btn{width:100%;display:flex;align-items:center;gap:.65rem;padding:.52rem .78rem !important;background:rgba(255,255,255,.04) !important;border:1px solid rgba(255,255,255,.07);color:rgba(255,255,255,.42) !important;border-radius:var(--r1);font-size:.8rem !important;font-family:'DM Sans',sans-serif !important;font-weight:500 !important;text-decoration:none}
</style>
</head>
<body>
<div class="layout">

<!-- ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â
     SIDEBAR
ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â -->
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
    <a href="technician_tasks.php" class="nav-item">
      <div class="ni"><i class="fas fa-clipboard-list"></i></div>
      My Tasks
    </a>

    <div class="nav-sep">Work</div>
    <a href="technician_task_details.php" class="nav-item">
      <div class="ni"><i class="fas fa-wrench"></i></div>
      Task Details
    </a>
    <a href="technician_history.php" class="nav-item active">
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

<!-- ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â
     MAIN
ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â -->
<div class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="tb-left">
      <button class="mob-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <i class="fas fa-bars"></i>
      </button>
      <div class="tb-seal" onclick="window.location='technician_dashboard.php'">
        <i class="fas fa-tools"></i>
      </div>
      <div>
        <div class="tb-title">Work History</div>
        <div class="tb-bread">
          <a href="technician_dashboard.php" style="color:inherit;text-decoration:none;">Dashboard</a>
          <i class="fas fa-chevron-right"></i>
          Work History
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

  <!-- CONTENT -->
  <div class="content">

    <!-- Page Header -->
    <div class="page-header">
      <div>
        <div class="ph-eyebrow"><i class="fas fa-history"></i> Completed Work</div>
        <div class="ph-title">Work History</div>
        <div class="ph-sub">A full record of every maintenance task you have completed.</div>
      </div>
    </div>

    <div class="section-strip">
      <div>
        <div class="ss-label"><i class="fas fa-layer-group"></i> History Sections</div>
        <div class="ss-text">Review completed jobs, resolution speed, and quality trends from past maintenance work.</div>
      </div>
    </div>

    <!-- Stat Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="sc-ico total"><i class="fas fa-check-double"></i></div>
        <div class="sc-body">
          <div class="sc-num" data-target="<?php echo $stat_total; ?>"><?php echo $stat_total; ?></div>
          <div class="sc-lbl">Total Completed</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="sc-ico month"><i class="fas fa-calendar-check"></i></div>
        <div class="sc-body">
          <div class="sc-num" data-target="<?php echo $stat_this_month; ?>"><?php echo $stat_this_month; ?></div>
          <div class="sc-lbl">This Month</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="sc-ico crit"><i class="fas fa-bolt"></i></div>
        <div class="sc-body">
          <div class="sc-num" data-target="<?php echo $stat_critical; ?>"><?php echo $stat_critical; ?></div>
          <div class="sc-lbl">Critical Resolved</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="sc-ico speed"><i class="fas fa-tachometer-alt"></i></div>
        <div class="sc-body">
          <div class="sc-num"><?php echo $stat_avg_days !== null ? $stat_avg_days.'d' : '-'; ?></div>
          <div class="sc-lbl">Avg. Resolution Time</div>
        </div>
      </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="technician_history.php">
      <input type="hidden" name="page" value="1">
      <div class="filter-bar">
        <div class="fb-search">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search by ID, equipment, or issue..."
                 value="<?php echo esc($filter_search); ?>">
        </div>

        <span class="fb-label">Priority:</span>
        <select name="priority" class="fb-select">
          <option value="">All</option>
          <option value="critical" <?php echo $filter_priority==='critical'?'selected':''; ?>>Critical</option>
          <option value="high"     <?php echo $filter_priority==='high'    ?'selected':''; ?>>High</option>
          <option value="medium"   <?php echo $filter_priority==='medium'  ?'selected':''; ?>>Medium</option>
          <option value="low"      <?php echo $filter_priority==='low'     ?'selected':''; ?>>Low</option>
        </select>

        <?php if (!empty($months_available)): ?>
          <span class="fb-label">Month:</span>
          <select name="month" class="fb-select">
            <option value="">All</option>
            <?php foreach($months_available as $mo):
              $label = date('F Y', strtotime($mo.'-01'));
            ?>
            <option value="<?php echo esc($mo); ?>" <?php echo $filter_month===$mo?'selected':''; ?>>
              <?php echo esc($label); ?>
            </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <button type="submit" class="btn btn-maroon btn-sm"><i class="fas fa-filter"></i> Filter</button>
        <?php if ($has_filters): ?>
          <a href="technician_history.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- History Table Panel -->
    <div class="panel">
      <div class="panel-h">
        <h3><i class="fas fa-history"></i> Completed Tasks</h3>
        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
          <a class="panel-h-link-btn" href="technician_tasks.php"><i class="fas fa-clipboard-list"></i> My Tasks</a>
          <span class="panel-count">
            Showing <?php echo $total_records>0?min($offset+1,$total_records):0; ?>-<?php echo min($offset+$per_page,$total_records); ?> of <?php echo $total_records; ?> record<?php echo $total_records!==1?'s':''; ?>
          </span>
        </div>
      </div>
      <div class="summary-row">
        <span class="summary-pill"><i class="fas fa-check-double"></i> <?php echo $stat_total; ?> total completed</span>
        <span class="summary-pill"><i class="fas fa-calendar-check"></i> <?php echo $stat_this_month; ?> this month</span>
        <span class="summary-pill"><i class="fas fa-bolt"></i> <?php echo $stat_critical; ?> critical</span>
        <span class="summary-pill"><i class="fas fa-tachometer-alt"></i> <?php echo $stat_avg_days !== null ? $stat_avg_days.'d' : '-'; ?> average</span>
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
              <th>Reported</th>
              <th>Completed</th>
              <th>Duration</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
<?php if (empty($history)): ?>
            <tr>
              <td colspan="9">
                <div class="empty-state">
                  <i class="fas fa-history"></i>
                  <h4>No history found</h4>
                  <p><?php echo $has_filters ? 'No completed tasks match your current filters. Try adjusting or clearing them.' : 'Completed tasks will appear here once you finish your first work order.'; ?></p>
                  <?php if ($has_filters): ?>
                    <a href="technician_history.php" class="btn btn-outline btn-sm" style="margin-top:1rem;">
                      <i class="fas fa-times"></i> Clear Filters
                    </a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
<?php else: foreach($history as $t):
  $st  = strtolower((string)($t['status']   ?? 'completed'));
  $p   = strtolower((string)($t['priority'] ?? 'low'));
  $pb  = match($p){'critical'=>'b-critical','high'=>'b-high','medium'=>'b-medium',default=>'b-low'};
  $sb  = match($st){'verified'=>'b-verified','closed'=>'b-closed',default=>'b-done'};
  $rid = (string)($t['report_id'] ?? '');

  $days = daysBetween($t['report_date']??null, $t['completion_date']??null);
  $dur_class = '';
  if ($days !== null) {
      if ($days <= 1) $dur_class = 'fast';
      elseif ($days > 7) $dur_class = 'slow';
  }
  $dur_label = $days === null ? '-' : ($days === 0 ? 'Same day' : $days.'d');
?>
            <tr class="history-row"
                data-id="<?php echo esc($rid); ?>"
                data-equipment="<?php echo esc($t['equipment_name']??'Equipment'); ?>"
                data-location="<?php echo esc($t['location']??''); ?>"
                data-priority="<?php echo esc(ucfirst($p)); ?>"
                data-priority-badge="<?php echo esc($pb); ?>"
                data-status="<?php echo esc(ucwords(str_replace('_',' ',$st))); ?>"
                data-status-badge="<?php echo esc($sb); ?>"
                data-reported="<?php echo esc(fmtDateFull($t['report_date']??null)); ?>"
                data-completed="<?php echo esc(fmtDateFull($t['completion_date']??null)); ?>"
                data-duration="<?php echo esc($dur_label); ?>"
                data-issue="<?php echo esc($t['issue_description']??'No description.'); ?>"
                data-notes="<?php echo esc($t['tech_notes']??''); ?>"
                style="cursor:pointer;"
                onclick="openDetailModal(this)">
              <td><span class="tbl-id"><?php echo esc($rid); ?></span></td>
              <td>
                <div class="tbl-name"><?php echo esc($t['equipment_name']??'Equipment'); ?></div>
                <div class="tbl-sub"><?php echo esc($t['location']??'Unspecified'); ?></div>
              </td>
              <td><div class="tbl-issue"><?php echo esc(mb_strimwidth((string)($t['issue_description']??''),0,80,'...')); ?></div></td>
              <td><span class="badge <?php echo esc($pb); ?>"><?php echo esc(ucfirst($p)); ?></span></td>
              <td><span class="badge <?php echo esc($sb); ?>"><?php echo esc(ucwords(str_replace('_',' ',$st))); ?></span></td>
              <td style="font-size:.78rem;color:var(--t3);"><?php echo esc(fmtDateShort($t['report_date']??null)); ?></td>
              <td style="font-size:.78rem;color:var(--t2);font-weight:700;"><?php echo esc(fmtDateShort($t['completion_date']??null)); ?></td>
              <td>
                <span class="dur-pill <?php echo esc($dur_class); ?>">
                  <i class="fas fa-clock"></i><?php echo esc($dur_label); ?>
                </span>
              </td>
              <td onclick="event.stopPropagation()">
                <a class="act-btn" href="technician_task_details.php?report_id=<?php echo urlencode($rid); ?>" title="Full Details">
                  <i class="fas fa-external-link-alt"></i>
                </a>
              </td>
            </tr>
<?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="pagination">
        <div class="pg-info">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></div>
        <div class="pg-btns">
          <a class="pg-btn <?php echo $current_page<=1?'disabled':''; ?>"
             href="<?php echo esc(filterUrl(['page'=>max(1,$current_page-1)])); ?>">
            <i class="fas fa-chevron-left"></i>
          </a>
          <?php for($pp=max(1,$current_page-2);$pp<=min($total_pages,$current_page+2);$pp++): ?>
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

<!-- ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â
     DETAIL MODAL
ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â -->
<div class="mo" id="detailModal">
  <div class="modal">
    <div class="m-head">
      <div class="mh-t">
        <h2 id="dmTitle">Task Details</h2>
        <p id="dmSub">Completed Work Order</p>
      </div>
      <button class="m-x" onclick="closeModal('detailModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="m-body">
      <!-- Row info -->
      <div id="dmRows"></div>

      <!-- Timeline -->
      <div style="margin-top:1.2rem;">
        <div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--t3);margin-bottom:.6rem;">Resolution Timeline</div>
        <div class="timeline" id="dmTimeline"></div>
      </div>

      <!-- Technician Notes -->
      <div id="dmNotesWrap"></div>
    </div>
    <div class="m-foot">
      <button class="btn btn-outline" onclick="closeModal('detailModal')">Close</button>
      <a class="btn btn-maroon" id="dmViewBtn" href="#">
        <i class="fas fa-external-link-alt"></i> Full Details
      </a>
    </div>
  </div>
</div>

<script>
/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ Date ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
document.getElementById('currentDate').textContent =
  new Date().toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ Animated counters ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
document.querySelectorAll('.sc-num[data-target]').forEach(el=>{
  const target = parseInt(el.getAttribute('data-target'));
  if (isNaN(target)) return;
  let current = 0;
  const step  = Math.max(1, Math.floor(target/30));
  const timer = setInterval(()=>{
    current = Math.min(current+step, target);
    el.textContent = current;
    if (current>=target) clearInterval(timer);
  },30);
});

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ Sidebar mobile ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
document.addEventListener('click',function(e){
  const sb = document.getElementById('sidebar');
  if (sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.mob-btn'))
    sb.classList.remove('open');
});

/* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ Detail Modal ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ */
function openDetailModal(row){
  const d = row.dataset;

  document.getElementById('dmTitle').innerHTML =
    `<span style="color:var(--gold-l);margin-right:.35rem;font-size:.82rem;">${d.id}</span>${d.equipment}`;
  document.getElementById('dmSub').textContent =
    `${d.priority} Priority - ${d.status}`;

  document.getElementById('dmRows').innerHTML = `
    <div class="dr2"><div class="dk2">Task ID</div>       <div class="dv2" style="font-family:'Poppins',sans-serif;font-weight:800;color:var(--maroon);">${d.id}</div></div>
    <div class="dr2"><div class="dk2">Equipment</div>     <div class="dv2">${d.equipment}</div></div>
    <div class="dr2"><div class="dk2">Location</div>      <div class="dv2"><i class="fas fa-map-marker-alt" style="color:var(--maroon);margin-right:.3rem;font-size:.75rem;"></i>${d.location||'Unspecified'}</div></div>
    <div class="dr2"><div class="dk2">Priority</div>      <div class="dv2"><span class="badge ${d.priorityBadge||''}">${d.priority}</span></div></div>
    <div class="dr2"><div class="dk2">Final Status</div>  <div class="dv2"><span class="badge ${d.statusBadge||''}">${d.status}</span></div></div>
    <div class="dr2"><div class="dk2">Duration</div>      <div class="dv2"><span class="dur-pill">${d.duration}</span></div></div>
    <div class="dr2"><div class="dk2">Issue</div>         <div class="dv2" style="line-height:1.6;">${d.issue}</div></div>
  `;

  /* timeline */
  const tl = document.getElementById('dmTimeline');
  let tlHtml = `
    <div class="tl-item">
      <div class="tl-dot reported"><i class="fas fa-flag"></i></div>
      <div class="tl-body"><div class="tl-title">Defect Reported</div><div class="tl-date">${d.reported}</div></div>
    </div>
    <div class="tl-item">
      <div class="tl-dot started"><i class="fas fa-play"></i></div>
      <div class="tl-body"><div class="tl-title">Work Started</div><div class="tl-date">-</div></div>
    </div>
    <div class="tl-item">
      <div class="tl-dot completed"><i class="fas fa-check"></i></div>
      <div class="tl-body"><div class="tl-title">Completed</div><div class="tl-date">${d.completed!=='N/A'?d.completed:'-'}</div></div>
    </div>`;
  if (d.statusBadge === 'b-verified') {
    tlHtml += `
    <div class="tl-item">
      <div class="tl-dot verified"><i class="fas fa-stamp"></i></div>
      <div class="tl-body"><div class="tl-title">Verified by Admin</div><div class="tl-date">-</div></div>
    </div>`;
  }
  tl.innerHTML = tlHtml;

  /* notes */
  const nw = document.getElementById('dmNotesWrap');
  if (d.notes && d.notes.trim()) {
    nw.innerHTML = `
      <div class="notes-box">
        <div class="notes-label"><i class="fas fa-sticky-note" style="margin-right:.3rem;color:var(--maroon);"></i>Technician Notes</div>
        <div class="notes-text">${d.notes}</div>
      </div>`;
  } else {
    nw.innerHTML = '';
  }

  document.getElementById('dmViewBtn').href = `technician_task_details.php?report_id=${encodeURIComponent(d.id)}`;
  document.getElementById('detailModal').classList.add('open');
}

function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.mo').forEach(o=>{
  o.addEventListener('click', e=>{ if(e.target===o) o.classList.remove('open'); });
});
</script>
</body>
</html>







