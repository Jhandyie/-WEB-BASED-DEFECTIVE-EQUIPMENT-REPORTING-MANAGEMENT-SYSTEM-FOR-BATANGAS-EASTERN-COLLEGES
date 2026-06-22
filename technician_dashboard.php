<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/technician_guard.php';
require_once __DIR__ . '/config/database.php';

requireRole('technician');

$conn = getDBConnection();
$techId = trim((string)($_SESSION['user_id'] ?? ''));
$techName = trim((string)($_SESSION['fullname'] ?? 'Technician'));
$techEmail = trim((string)($_SESSION['user_email'] ?? ''));
$techKeys = technicianIdentityKeysFromSession($_SESSION);

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fdate($v, $fallback = 'N/A'): string { $t = strtotime((string)$v); return $t ? date('M d, Y', $t) : $fallback; }
function fdt($v, $fallback = 'N/A'): string { $t = strtotime((string)$v); return $t ? date('M d, Y h:i A', $t) : $fallback; }
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return count($parts) >= 2 ? strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts)-1], 0, 1)) : strtoupper(substr($name ?: 'T', 0, 2));
}
function slabel(string $s): string {
    return [
        'assigned' => 'Assigned',
        'in_progress' => 'Inspection / Repair',
        'for_replacement' => 'Replacement Recommended',
        'completed' => 'Awaiting PMO Verification',
        'verified' => 'Verified',
        'closed' => 'Closed',
    ][strtolower(trim($s))] ?? ucwords(str_replace('_', ' ', $s));
}
function stone(string $s): string {
    return [
        'assigned' => 'pend',
        'in_progress' => 'prog',
        'for_replacement' => 'repl',
        'completed' => 'await',
        'verified' => 'done',
        'closed' => 'done',
    ][strtolower(trim($s))] ?? 'pend';
}
function sicon(string $s): string {
    return [
        'assigned' => 'fa-clipboard-list',
        'in_progress' => 'fa-screwdriver-wrench',
        'for_replacement' => 'fa-rotate',
        'completed' => 'fa-user-check',
        'verified' => 'fa-badge-check',
        'closed' => 'fa-circle-check',
    ][strtolower(trim($s))] ?? 'fa-circle';
}
function ptone(string $p): string {
    return [
        'critical' => 'crit',
        'high' => 'high',
        'medium' => 'med',
        'low' => 'low',
    ][strtolower(trim($p))] ?? 'med';
}
function picon(string $p): string {
    return [
        'critical' => 'fa-triangle-exclamation',
        'high' => 'fa-arrow-up',
        'medium' => 'fa-equals',
        'low' => 'fa-arrow-down',
    ][strtolower(trim($p))] ?? 'fa-equals';
}
function nextActionLabel(string $status): string {
    return [
        'assigned' => 'Needs inspection',
        'in_progress' => 'Continue repair',
        'for_replacement' => 'Review replacement recommendation',
        'completed' => 'Waiting for PMO',
        'verified' => 'Verified record',
        'closed' => 'Closed record',
    ][strtolower(trim($status))] ?? 'Review task';
}
function taskPhotos(array $row): array {
    $out = [];
    foreach (['photo_path', 'photo_url', 'image_path'] as $field) {
        $raw = trim((string)($row[$field] ?? ''));
        if ($raw === '') continue;
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            foreach ($decoded as $v) { $v = trim((string)$v); if ($v !== '' && !in_array($v, $out, true)) $out[] = str_replace('\\', '/', $v); }
        } elseif (!in_array($raw, $out, true)) {
            $out[] = str_replace('\\', '/', $raw);
        }
    }
    $rid = trim((string)($row['report_id'] ?? ''));
    if ($rid !== '') {
        foreach ([__DIR__ . '/uploads/reports/' . $rid . '.*', __DIR__ . '/uploads/defect_reports/' . $rid . '.*'] as $pattern) {
            foreach (glob($pattern) ?: [] as $m) {
                $p = str_replace('\\', '/', ltrim(str_replace(__DIR__, '', $m), '\\/'));
                if (!in_array($p, $out, true)) $out[] = $p;
            }
        }
    }
    return $out;
}
function workflowSteps(string $status): array {
    $status = strtolower(trim($status));
    $reached = fn(array $targets) => in_array($status, $targets, true);
    return [
        ['label' => 'Inspection', 'desc' => 'Arrive on site and inspect the defective equipment.', 'done' => $reached(['in_progress','for_replacement','completed','verified','closed']), 'active' => $status === 'assigned'],
        ['label' => 'Repair Decision', 'desc' => 'Decide whether repair is feasible or replacement is needed.', 'done' => $reached(['for_replacement','completed','verified','closed']), 'active' => $status === 'in_progress'],
        ['label' => 'Repair / Recommendation', 'desc' => 'Complete the repair or recommend replacement for PMO action.', 'done' => $reached(['completed','verified','closed']), 'active' => $status === 'for_replacement'],
        ['label' => 'PMO Verification', 'desc' => 'PMO verifies that the equipment works properly or continues further action.', 'done' => $reached(['verified','closed']), 'active' => $status === 'completed'],
    ];
}

$drCols = technicianFetchDefectReportColumns($conn);
$eqCols = [];
try { $r = $conn->query('SHOW COLUMNS FROM equipment'); if ($r) while ($c = $r->fetch_assoc()) $eqCols[$c['Field']] = true; } catch (Exception $e) {}
$assigneeCol = technicianResolveAssigneeColumn($drCols);
[$assigneeWhere, $assigneeTypes, $assigneeVals] = technicianBuildAssigneeMatchClause('r.' . $assigneeCol, $techKeys);
$issueExpr = isset($drCols['issue_description']) ? 'r.issue_description' : (isset($drCols['defect_description']) ? 'r.defect_description' : "''");
$priorityExpr = isset($drCols['priority']) ? 'r.priority' : "'medium'";
$statusExpr = isset($drCols['status']) ? 'r.status' : "'assigned'";
$reportDateExpr = isset($drCols['report_date']) ? 'r.report_date' : 'NOW()';
$completionExpr = isset($drCols['completion_date']) ? 'r.completion_date' : 'NULL';
$notesField = isset($drCols['technician_notes']) ? 'technician_notes' : (isset($drCols['resolution_notes']) ? 'resolution_notes' : (isset($drCols['notes']) ? 'notes' : ''));
$notesExpr = $notesField !== '' ? 'r.' . $notesField : "''";
$instExpr = isset($drCols['handler_instructions']) ? 'r.handler_instructions' : (isset($drCols['instructions']) ? 'r.instructions' : "''");
$join = '';
$equipmentExpr = isset($drCols['equipment_name']) ? 'r.equipment_name' : 'CAST(r.equipment_id AS CHAR)';
$locationExpr = isset($drCols['location']) ? 'r.location' : "''";
$assetExpr = "''";
$categoryExpr = isset($drCols['category']) ? 'r.category' : "''";
if (isset($drCols['equipment_id']) && (isset($eqCols['equipment_id']) || isset($eqCols['id']))) {
    $eqId = isset($eqCols['equipment_id']) ? 'equipment_id' : 'id';
    $join = "LEFT JOIN equipment e ON e.$eqId = r.equipment_id";
    if (!isset($drCols['equipment_name'])) {
        $equipmentNameParts = [];
        if (isset($eqCols['equipment_name'])) $equipmentNameParts[] = 'e.equipment_name';
        if (isset($eqCols['name'])) $equipmentNameParts[] = 'e.name';
        $equipmentNameParts[] = 'CAST(r.equipment_id AS CHAR)';
        $equipmentExpr = 'COALESCE(' . implode(', ', $equipmentNameParts) . ')';
    }
    if (!isset($drCols['location'])) {
        $locationParts = [];
        if (isset($eqCols['location'])) $locationParts[] = 'e.location';
        if (isset($eqCols['room'])) $locationParts[] = 'e.room';
        $locationParts[] = "''";
        $locationExpr = 'COALESCE(' . implode(', ', $locationParts) . ')';
    }
    $assetExpr = "COALESCE(e.asset_tag, '')";
}

$tab = trim((string)($_GET['tab'] ?? 'my_tasks'));
if (!in_array($tab, ['my_tasks', 'history'], true)) $tab = 'my_tasks';
$search = trim((string)($_GET['search'] ?? ''));
$queueFilter = trim((string)($_GET['queue'] ?? 'all'));
$selectedId = trim((string)($_GET['report'] ?? ''));
$flash = ['type' => trim((string)($_GET['flash_type'] ?? '')), 'message' => trim((string)($_GET['flash'] ?? ''))];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $reportId = trim((string)($_POST['report_id'] ?? ''));
    $tab = 'my_tasks';
    $selectedId = $reportId;
    $report = $reportId !== '' ? getDefectReportById($reportId) : null;
    $message = 'Unable to update the task.';
    $type = 'err';
    if ($report) {
        $assignedValue = (string)($report['assigned_to'] ?? ($report['assigned_technician'] ?? ''));
        if (technicianOwnsAssigneeValue($assignedValue, $techKeys)) {
            $current = strtolower(trim((string)($report['status'] ?? 'assigned')));
            $notes = trim((string)($_POST['technician_notes'] ?? ''));
            $updates = [];
            if ($action === 'start' && $current === 'assigned') {
                $updates['status'] = 'in_progress';
                if (isset($drCols['started_at'])) $updates['started_at'] = date('Y-m-d H:i:s');
                if ($notes !== '' && $notesField !== '') $updates[$notesField] = $notes;
                $message = 'Inspection started.';
                $type = 'ok';
            } elseif ($action === 'save' && in_array($current, ['assigned','in_progress','for_replacement','completed'], true) && $notes !== '' && $notesField !== '') {
                $updates[$notesField] = $notes;
                if ($current === 'assigned') $updates['status'] = 'in_progress';
                $message = 'Technician notes saved.';
                $type = 'ok';
            } elseif ($action === 'replace' && in_array($current, ['assigned','in_progress'], true) && $notes !== '' && $notesField !== '') {
                $updates['status'] = 'for_replacement';
                $updates[$notesField] = $notes;
                $message = 'Replacement recommendation submitted.';
                $type = 'ok';
            } elseif ($action === 'resume' && $current === 'for_replacement') {
                $updates['status'] = 'in_progress';
                if ($notes !== '' && $notesField !== '') $updates[$notesField] = $notes;
                $message = 'Task returned to repair in progress.';
                $type = 'ok';
            } elseif ($action === 'complete' && in_array($current, ['in_progress','for_replacement'], true) && $notes !== '' && $notesField !== '') {
                $updates['status'] = 'completed';
                if (isset($drCols['completion_date'])) $updates['completion_date'] = date('Y-m-d H:i:s');
                $updates[$notesField] = $notes;
                $message = 'Task submitted for PMO verification.';
                $type = 'ok';
            } else {
                $message = 'Please complete the required technician notes for this action.';
            }
            if ($updates && !updateDefectReport($reportId, $updates)) {
                $message = 'Unable to save the task update.';
                $type = 'err';
            }
        } else {
            $message = 'That task is not assigned to your account.';
        }
    } else {
        $message = 'Task not found.';
    }
    header('Location: technician_dashboard.php?' . http_build_query(['tab' => 'my_tasks', 'report' => $selectedId, 'flash_type' => $type, 'flash' => $message]));
    exit;
}

$searchSql = '';
$searchTypes = '';
$searchVals = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $searchSql = " AND (($equipmentExpr) LIKE ? OR ($issueExpr) LIKE ? OR r.report_id LIKE ? OR ($locationExpr) LIKE ?)";
    $searchTypes = 'ssss';
    $searchVals = [$like, $like, $like, $like];
}
$activeStatuses = ['assigned','in_progress','for_replacement','completed'];
$historyStatuses = ['verified','closed'];
$activeSql = "SELECT r.report_id,$equipmentExpr equipment_name,$issueExpr issue_description,$priorityExpr priority,$statusExpr status,$locationExpr location,$reportDateExpr report_date,$completionExpr completion_date,$notesExpr technician_notes,$instExpr handler_instructions,$assetExpr asset_tag,$categoryExpr category_name FROM defect_reports r $join WHERE $assigneeWhere AND $statusExpr IN ('assigned','in_progress','for_replacement','completed') $searchSql ORDER BY FIELD($statusExpr,'assigned','in_progress','for_replacement','completed'), FIELD($priorityExpr,'critical','high','medium','low'), $reportDateExpr DESC";
$historySql = "SELECT r.report_id,$equipmentExpr equipment_name,$issueExpr issue_description,$priorityExpr priority,$statusExpr status,$locationExpr location,$reportDateExpr report_date,$completionExpr completion_date,$notesExpr technician_notes,$instExpr handler_instructions,$assetExpr asset_tag,$categoryExpr category_name FROM defect_reports r $join WHERE $assigneeWhere AND $statusExpr IN ('verified','closed') $searchSql ORDER BY COALESCE($completionExpr,$reportDateExpr) DESC";
$activeStmt = $conn->prepare($activeSql);
$activeStmt->bind_param($assigneeTypes . $searchTypes, ...array_merge($assigneeVals, $searchVals));
$activeStmt->execute();
$activeTasks = $activeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$activeStmt->close();
$historyStmt = $conn->prepare($historySql);
$historyStmt->bind_param($assigneeTypes . $searchTypes, ...array_merge($assigneeVals, $searchVals));
$historyStmt->execute();
$historyTasks = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();
foreach ($activeTasks as &$t) $t['photos'] = taskPhotos($t);
unset($t);
foreach ($historyTasks as &$t) $t['photos'] = taskPhotos($t);
unset($t);

$filterConfig = $tab === 'history'
    ? [
        'all' => fn(array $row): bool => true,
        'verified' => fn(array $row): bool => strtolower((string)($row['status'] ?? '')) === 'verified',
        'closed' => fn(array $row): bool => strtolower((string)($row['status'] ?? '')) === 'closed',
      ]
    : [
        'all' => fn(array $row): bool => true,
        'urgent' => fn(array $row): bool => in_array(strtolower((string)($row['priority'] ?? '')), ['critical', 'high'], true),
        'assigned' => fn(array $row): bool => strtolower((string)($row['status'] ?? '')) === 'assigned',
        'in_progress' => fn(array $row): bool => strtolower((string)($row['status'] ?? '')) === 'in_progress',
        'completed' => fn(array $row): bool => strtolower((string)($row['status'] ?? '')) === 'completed',
      ];
if (!isset($filterConfig[$queueFilter])) $queueFilter = 'all';

$queueCounts = [];
foreach ($filterConfig as $key => $matcher) {
    $target = $tab === 'history' ? $historyTasks : $activeTasks;
    $queueCounts[$key] = count(array_filter($target, $matcher));
}

$activeViewTasks = array_values(array_filter($activeTasks, $filterConfig[$queueFilter] ?? $filterConfig['all']));
$historyViewTasks = array_values(array_filter($historyTasks, $filterConfig[$queueFilter] ?? $filterConfig['all']));

$selected = null;
$list = $tab === 'history' ? $historyViewTasks : $activeViewTasks;
foreach ($list as $row) if ($selectedId !== '' && (string)$row['report_id'] === $selectedId) $selected = $row;
if (!$selected && $list) { $selected = $list[0]; $selectedId = (string)$selected['report_id']; }

$perPage = 6;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalItems = count($list);
$totalPages = max(1, (int)ceil($totalItems / $perPage));
if ($currentPage > $totalPages) $currentPage = $totalPages;
$pageOffset = ($currentPage - 1) * $perPage;
$pagedSource = array_slice($list, $pageOffset, $perPage);

$cAssigned = count(array_filter($activeTasks, fn($r) => ($r['status'] ?? '') === 'assigned'));
$cProg = count(array_filter($activeTasks, fn($r) => ($r['status'] ?? '') === 'in_progress'));
$cRepl = count(array_filter($activeTasks, fn($r) => ($r['status'] ?? '') === 'for_replacement'));
$cAwait = count(array_filter($activeTasks, fn($r) => ($r['status'] ?? '') === 'completed'));
$cHist = count($historyTasks);
$taskCount = count($activeTasks);
$selectedStatus = strtolower((string)($selected['status'] ?? 'assigned'));
$selectedStatusLabel = slabel($selectedStatus);
$selectedPriority = ucfirst((string)($selected['priority'] ?? 'medium'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Technician Dashboard</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Outfit:wght@600;700;800&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="css/typography.css">
<style>
:root {
  --bg: #f6efe6;
  --bg-soft: #fffaf4;
  --surface: #ffffff;
  --surface-alt: #fbf6ef;
  --line: #eadbc7;
  --line-strong: #d9c1a0;
  --text: #261515;
  --muted: #725b5b;
  --maroon: #7b1d1d;
  --maroon-dark: #511010;
  --gold: #c89b2d;
  --gold-soft: #f5e7bb;
  --blue: #1d4ed8;
  --blue-soft: #e8efff;
  --green: #0f7a4f;
  --green-soft: #e5f7ef;
  --amber: #a16207;
  --amber-soft: #fff4d8;
  --shadow-sm: 0 10px 30px rgba(91, 42, 21, 0.08);
  --shadow-md: 0 18px 48px rgba(91, 42, 21, 0.14);
  --radius-lg: 28px;
  --radius-md: 20px;
  --radius-sm: 14px;
}

* { box-sizing: border-box; }

html { scroll-behavior: smooth; }

body {
  margin: 0;
  min-height: 100vh;
  font-family: 'DM Sans', sans-serif;
  font-size: 15px;
  color: var(--text);
  background:
    radial-gradient(circle at top right, rgba(200, 155, 45, 0.18), transparent 22rem),
    linear-gradient(180deg, #fffaf5 0%, #f7ecdf 45%, #f1e4d6 100%);
}

img { display: block; max-width: 100%; }
a { color: inherit; text-decoration: none; }
button, input, textarea { font: inherit; }

.shell {
  display: grid;
  grid-template-columns: 290px minmax(0, 1fr);
  min-height: 100vh;
}

.sidebar-scrim {
  position: fixed;
  inset: 0;
  background: rgba(26, 10, 10, 0.38);
  backdrop-filter: blur(3px);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
  z-index: 250;
}

.sidebar {
  position: sticky;
  top: 0;
  align-self: start;
  min-height: 100vh;
  padding: 22px 16px;
  background: linear-gradient(168deg, #1e0202 0%, #350808 38%, #4a0e0e 68%, #3a0808 100%);
  color: #fff8ee;
  border-right: 1px solid rgba(255, 232, 205, 0.12);
  box-shadow: 4px 0 24px rgba(45, 5, 5, 0.4);
  overflow: hidden;
}

.sidebar::before {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse 100% 50% at 50% 0%, rgba(212, 160, 23, 0.1), transparent);
  pointer-events: none;
}

.sidebar::after {
  content: '';
  position: absolute;
  bottom: -60px;
  left: -40px;
  width: 200px;
  height: 200px;
  border-radius: 50%;
  background: rgba(212, 160, 23, 0.04);
  pointer-events: none;
}

.side-inner {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  min-height: calc(100vh - 44px);
  gap: 18px;
}

.side-brand,
.profile,
.side-link,
.logout,
.card,
.flash,
.item,
.modal-card {
  border: 1px solid rgba(255, 255, 255, 0.08);
}

.side-brand,
.profile {
  display: flex;
  gap: 12px;
  align-items: center;
  padding: 14px 14px;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.05);
  backdrop-filter: blur(8px);
}

.side-brand {
  gap: 10px;
}

.side-logo {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: #fff;
  border: 1px solid rgba(123, 29, 29, 0.14);
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 0 0 3px rgba(123, 29, 29, 0.15);
  overflow: hidden;
  flex-shrink: 0;
}

.side-logo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.side-brand small,
.profile span,
.subtle,
.timeline-copy,
.copy,
.meta-card small,
.panel-head small,
.detail-hero small,
.flash,
.empty {
  color: var(--muted);
}

.sidebar small {
  display: block;
  color: rgba(255, 255, 255, 0.3);
  font-size: 0.57rem;
  letter-spacing: 1.8px;
  text-transform: uppercase;
}

.side-brand strong,
.profile strong {
  font-family: 'Outfit', sans-serif;
  letter-spacing: 0;
}

.side-brand strong {
  display: block;
  margin-top: 1px;
  font-size: 0.78rem;
  font-weight: 600;
  line-height: 1.25;
  color: #fff;
}

.side-brand .brand-copy {
  min-width: 0;
}

.profile {
  align-items: center;
  transition: background 0.18s ease;
}

.profile:hover {
  background: rgba(255, 255, 255, 0.09);
}

.avatar {
  width: 42px;
  height: 42px;
  border-radius: 14px;
  display: grid;
  place-items: center;
  font-family: 'Outfit', sans-serif;
  font-size: 0.8rem;
  font-weight: 800;
  color: #fff;
  background: linear-gradient(135deg, #d4a017, #9b2c2c);
  box-shadow: 0 6px 0 rgba(0, 0, 0, 0.18);
}

.profile strong {
  display: block;
  font-size: 0.82rem;
  font-weight: 600;
  color: #fff;
}

.profile span {
  display: block;
  margin-top: 2px;
  font-size: 0.6rem;
  color: rgba(255, 255, 255, 0.35);
  text-transform: uppercase;
  letter-spacing: 1px;
}

.side-nav {
  display: grid;
  gap: 6px;
}

.side-nav-label {
  padding: 0.5rem 1rem 0.2rem;
  font-size: 0.54rem;
  text-transform: uppercase;
  letter-spacing: 2.5px;
  color: rgba(255, 255, 255, 0.18);
  font-weight: 700;
}

.side-link,
.logout {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0.75rem 1rem;
  border-radius: 14px;
  background: none;
  border: none;
  color: rgba(255, 255, 255, 0.58);
  font-size: 0.82rem;
  font-weight: 500;
  transition: color 0.18s ease, transform 0.18s ease;
  position: relative;
}

.side-link i,
.logout i {
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  font-size: 0.78rem;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.05);
  transition: background 0.18s ease, transform 0.18s ease, color 0.18s ease;
}

.side-link .side-count {
  margin-left: auto;
  min-width: 24px;
  padding: 1px 6px;
  border-radius: 999px;
  text-align: center;
  font-size: 0.58rem;
  font-weight: 900;
  color: #2d0505;
  background: #d4a017;
}

.side-link:hover,
.logout:hover,
.side-link.active {
  transform: none;
  color: rgba(255, 255, 255, 0.82);
}

.side-link:hover i,
.logout:hover i {
  background: rgba(255, 255, 255, 0.1);
  transform: scale(1.08);
}

.side-link.active {
  color: #fff;
  font-weight: 600;
}

.side-link.active i {
  background: linear-gradient(135deg, #d4a017, #f0c040);
  color: #2d0505;
  box-shadow: 0 3px 0 rgba(0, 0, 0, 0.2);
}

.side-link.active::after {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 3px;
  background: linear-gradient(to bottom, #d4a017, #f0c040);
  border-radius: 0 3px 3px 0;
}

.side-footer {
  margin-top: auto;
}

.logout {
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(255, 255, 255, 0.07);
  font-size: 0.81rem;
}

.logout:hover {
  color: #fca5a5;
  background: rgba(220, 38, 38, 0.15);
  border-color: rgba(220, 38, 38, 0.25);
}

.logout:hover i {
  background: rgba(255, 255, 255, 0.08);
  transform: rotate(180deg);
}

.main {
  min-width: 0;
}

.wrap {
  width: min(1380px, calc(100% - 40px));
  margin: 0 auto;
  padding: 28px 0 40px;
}

.card,
.flash,
.item,
.modal-card {
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(255, 249, 242, 0.98));
  box-shadow: var(--shadow-sm);
}

.stat-grid {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 16px;
  margin-bottom: 18px;
}

.card {
  border-radius: var(--radius-lg);
}

.card.stat {
  position: relative;
  overflow: hidden;
  padding: 20px 20px 18px;
  min-height: 142px;
}

.card.stat::before {
  content: '';
  position: absolute;
  inset: 0 auto auto 0;
  width: 100%;
  height: 5px;
  background: linear-gradient(90deg, var(--maroon), var(--gold));
}

.card.stat small {
  display: inline-flex;
  padding: 6px 10px;
  border-radius: 999px;
  background: var(--surface-alt);
  color: var(--maroon);
  font-weight: 700;
  font-size: 0.68rem;
  letter-spacing: 0.02em;
}

.card.stat strong {
  display: block;
  margin-top: 16px;
  font-family: 'Fraunces', serif;
  font-size: clamp(1.55rem, 2.2vw, 1.95rem);
  color: var(--maroon-dark);
}

.card.stat span {
  display: block;
  margin-top: 8px;
  font-size: 0.82rem;
  line-height: 1.55;
  color: var(--muted);
}

.toolbar {
  padding: 16px 18px;
  margin-bottom: 18px;
}

.toolbar-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.toolbar-copy strong {
  display: block;
  font-family: 'Outfit', sans-serif;
  font-size: 0.92rem;
  color: var(--maroon-dark);
}

.toolbar-copy span {
  display: block;
  margin-top: 3px;
  font-size: 0.76rem;
  color: var(--muted);
}

.sidebar-toggle {
  display: none;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 1px solid var(--line);
  background: #fff8ef;
  color: var(--maroon);
  border-radius: 999px;
  padding: 10px 14px;
  font-size: 0.76rem;
  font-weight: 700;
  cursor: pointer;
}

.tabs {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.tab,
.search button,
.search a,
.ghost-btn,
.actions button {
  border-radius: 999px;
  font-weight: 700;
  transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease;
}

.tab {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border: 1px solid var(--line);
  color: var(--muted);
  background: var(--bg-soft);
  font-size: 0.84rem;
}

.tab:hover,
.tab.active {
  color: #fffaf2;
  border-color: transparent;
  background: linear-gradient(135deg, var(--maroon), #9d3535 70%, var(--gold));
  box-shadow: 0 12px 24px rgba(123, 29, 29, 0.18);
}

.search {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1 1 380px;
  justify-content: flex-end;
}

.search input {
  width: min(420px, 100%);
  padding: 14px 16px;
  border-radius: 16px;
  border: 1px solid var(--line);
  background: #fffdfa;
  color: var(--text);
  font-size: 0.86rem;
  outline: none;
}

.search input:focus {
  border-color: rgba(123, 29, 29, 0.4);
  box-shadow: 0 0 0 4px rgba(123, 29, 29, 0.08);
}

.search button,
.search a,
.ghost-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  border: 1px solid var(--line);
  background: #fff8ef;
  color: var(--maroon);
  font-size: 0.82rem;
}

.search button {
  cursor: pointer;
}

.search button:hover,
.search a:hover,
.ghost-btn:hover,
.actions button:hover {
  transform: translateY(-1px);
}

.queue-filters {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}

.filter-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 12px;
  border-radius: 999px;
  border: 1px solid var(--line);
  background: #fffaf4;
  color: var(--muted);
  font-size: 0.74rem;
  font-weight: 700;
}

.filter-chip strong {
  color: inherit;
  font-size: 0.68rem;
}

.filter-chip.active {
  color: #fff7ee;
  border-color: transparent;
  background: linear-gradient(135deg, var(--maroon), #9d3535 70%, var(--gold));
  box-shadow: 0 10px 22px rgba(123, 29, 29, 0.16);
}

.flash {
  margin-bottom: 18px;
  padding: 16px 18px;
  border-radius: 18px;
  font-weight: 700;
}

.flash.ok {
  border-color: rgba(15, 122, 79, 0.18);
  background: linear-gradient(180deg, #f4fff9, #ecfbf2);
  color: var(--green);
}

.flash.err {
  border-color: rgba(123, 29, 29, 0.18);
  background: linear-gradient(180deg, #fff8f6, #fff0ed);
  color: var(--maroon);
}

.workspace,
.list-shell,
.list-stack,
.list-grid {
  min-width: 0;
}

.list-shell {
  padding: 20px;
}

.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}

.panel-head small,
.detail-hero small {
  display: block;
  margin-bottom: 4px;
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.section-title {
  margin: 0;
  font-size: clamp(0.98rem, 1.5vw, 1.1rem);
  color: var(--maroon-dark);
}

.ghost-btn {
  white-space: nowrap;
}

.list-head {
  margin-bottom: 16px;
}

.list-head strong {
  display: block;
  font-size: 0.9rem;
  color: var(--text);
}

.subtle {
  font-size: 0.8rem;
  line-height: 1.5;
}

.list-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
  gap: 16px;
}

.item-trigger {
  padding: 0;
  border: 0;
  background: transparent;
  text-align: left;
  cursor: pointer;
}

.item {
  height: 100%;
  padding: 15px;
  border-radius: 20px;
  transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.item-topline {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 8px;
}

.report-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  border-radius: 999px;
  background: #fff7ea;
  border: 1px solid var(--line);
  color: var(--maroon);
  font-size: 0.66rem;
  font-weight: 700;
}

.quick-note {
  font-size: 0.68rem;
  color: var(--muted);
}

.item:hover,
.item-trigger:focus-visible .item {
  transform: translateY(-3px);
  border-color: rgba(123, 29, 29, 0.16);
  box-shadow: var(--shadow-md);
}

.item-trigger:focus-visible {
  outline: none;
}

.row,
.badges,
.item-meta,
.grid,
.actions,
.step,
.photos {
  display: flex;
  gap: 10px;
}

.row {
  align-items: flex-start;
  justify-content: space-between;
}

.item-title {
  margin: 0;
  font-size: 0.92rem;
  color: var(--maroon-dark);
}

.item-issue,
.copy,
.timeline-copy {
  font-size: 0.84rem;
  line-height: 1.65;
}

.item-issue {
  margin: 10px 0 12px;
  font-size: 0.76rem;
  line-height: 1.55;
  color: var(--text);
}

.item-meta {
  flex-wrap: wrap;
  gap: 8px;
}

.meta-card {
  flex: 1 1 150px;
  min-width: 0;
  padding: 10px 12px;
  border-radius: 14px;
  border: 1px solid var(--line);
  background: var(--surface-alt);
}

.meta-card strong,
.grid strong {
  display: block;
  margin-top: 4px;
  font-size: 0.8rem;
  color: var(--text);
}

.badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 7px 10px;
  border-radius: 999px;
  font-size: 0.66rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  border: 1px solid transparent;
  white-space: nowrap;
}

.badge.pend,
.badge.await {
  background: var(--amber-soft);
  color: var(--amber);
  border-color: rgba(161, 98, 7, 0.15);
}

.badge.prog {
  background: var(--blue-soft);
  color: var(--blue);
  border-color: rgba(29, 78, 216, 0.15);
}

.badge.repl,
.badge.high {
  background: #fff1e6;
  color: #b45309;
  border-color: rgba(180, 83, 9, 0.12);
}

.badge.done {
  background: var(--green-soft);
  color: var(--green);
  border-color: rgba(15, 122, 79, 0.15);
}

.badge.crit {
  background: #fff0f0;
  color: #b91c1c;
  border-color: rgba(185, 28, 28, 0.12);
}

.badge.med,
.badge.low {
  background: #f4ecff;
  color: #6d28d9;
  border-color: rgba(109, 40, 217, 0.12);
}

.empty {
  padding: 34px 20px;
  border: 1px dashed var(--line-strong);
  border-radius: 24px;
  background: linear-gradient(180deg, #fffdfa, #fbf5ed);
  text-align: center;
}

.empty strong {
  display: block;
  margin-bottom: 6px;
  font-size: 0.92rem;
  color: var(--maroon-dark);
}

.empty-actions {
  display: flex;
  justify-content: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 16px;
}

.empty-action {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 11px 14px;
  border-radius: 999px;
  border: 1px solid var(--line);
  background: #fff7ea;
  color: var(--maroon);
  font-weight: 700;
}

.empty i {
  display: block;
  margin-bottom: 12px;
  font-size: 1.5rem;
  color: var(--gold);
}

.modal {
  position: fixed;
  inset: 0;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 22px;
  background: rgba(37, 17, 17, 0.64);
  backdrop-filter: blur(6px);
  z-index: 1000;
}

.modal.open {
  display: flex;
}

body.modal-open {
  overflow: hidden;
}

.modal-dialog {
  width: min(980px, 100%);
  max-height: calc(100vh - 44px);
  overflow: auto;
  padding: 22px;
  border-radius: 30px;
  background: linear-gradient(180deg, #fffdfa, #f8efe6);
  box-shadow: 0 28px 70px rgba(33, 15, 15, 0.28);
  border: 1px solid rgba(255, 255, 255, 0.6);
}

.modal-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 18px;
  margin-bottom: 18px;
  position: sticky;
  top: -22px;
  z-index: 3;
  padding: 0 0 12px;
  background: linear-gradient(180deg, rgba(255, 253, 250, 0.98), rgba(248, 239, 230, 0.96));
  backdrop-filter: blur(8px);
}

.modal-head small {
  display: block;
  margin-bottom: 4px;
  font-size: 0.68rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--muted);
}

.modal-title {
  margin: 0;
  font-size: clamp(1.2rem, 2vw, 1.45rem);
  color: var(--maroon-dark);
}

.modal-close {
  flex-shrink: 0;
  width: 46px;
  height: 46px;
  border-radius: 16px;
  border: 1px solid var(--line);
  background: #fff8ef;
  color: var(--maroon);
  cursor: pointer;
}

.modal-stack {
  display: grid;
  gap: 16px;
}

.modal-card {
  padding: 18px;
  border-radius: 24px;
}

.detail-hero {
  background:
    linear-gradient(135deg, rgba(123, 29, 29, 0.06), rgba(200, 155, 45, 0.18)),
    linear-gradient(180deg, #fffdfb, #fbf3e7);
}

.grid {
  flex-wrap: wrap;
}

.grid > div {
  flex: 1 1 180px;
  min-width: 0;
  padding: 12px 14px;
  border-radius: 16px;
  border: 1px solid var(--line);
  background: rgba(255, 255, 255, 0.72);
}

.timeline {
  display: grid;
  gap: 14px;
}

.step {
  align-items: flex-start;
}

.dot {
  width: 32px;
  height: 32px;
  border-radius: 999px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  font-weight: 700;
  color: var(--muted);
  background: #f6eadf;
  border: 1px solid var(--line);
}

.dot.act {
  color: var(--amber);
  background: var(--amber-soft);
}

.dot.done {
  color: var(--green);
  background: var(--green-soft);
}

.photos {
  flex-wrap: wrap;
}

.photos img {
  width: min(100%, 220px);
  aspect-ratio: 4 / 3;
  object-fit: cover;
  border-radius: 18px;
  border: 1px solid var(--line);
  box-shadow: 0 10px 24px rgba(91, 42, 21, 0.12);
}

.form label {
  display: block;
  margin-bottom: 8px;
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--maroon-dark);
}

.form textarea {
  width: 100%;
  min-height: 170px;
  resize: vertical;
  padding: 16px;
  border-radius: 20px;
  border: 1px solid var(--line);
  background: #fffefb;
  outline: none;
}

.form textarea:focus {
  border-color: rgba(123, 29, 29, 0.38);
  box-shadow: 0 0 0 4px rgba(123, 29, 29, 0.08);
}

.actions {
  flex-wrap: wrap;
  margin-top: 16px;
}

.modal-action-bar {
  position: relative;
}

.actions button {
  border: 0;
  padding: 12px 18px;
  cursor: pointer;
  color: #fff;
  font-size: 0.82rem;
}

.actions .b1,
.actions .b2 {
  background: linear-gradient(135deg, var(--maroon), #9d3535);
}

.actions .b3 {
  background: linear-gradient(135deg, #b45309, #d97706);
}

.actions .b4 {
  background: linear-gradient(135deg, var(--green), #169f66);
}

@media (max-width: 1200px) {
  .stat-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 960px) {
  .shell {
    grid-template-columns: 1fr;
  }

  .sidebar-scrim {
    display: block;
  }

  .sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: min(320px, calc(100vw - 24px));
    height: 100vh;
    min-height: 100vh;
    padding: 14px;
    transform: translateX(calc(-100% - 18px));
    transition: transform 0.24s ease;
    border-right: 0;
    border-radius: 0 24px 24px 0;
    z-index: 300;
  }

  body.sidebar-open .sidebar {
    transform: translateX(0);
  }

  body.sidebar-open .sidebar-scrim {
    opacity: 1;
    pointer-events: auto;
  }

  .side-inner {
    min-height: calc(100vh - 28px);
    gap: 12px;
  }

  .side-brand,
  .profile {
    border-radius: 16px;
    padding: 12px;
  }

  .side-nav {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }

  .side-nav-label {
    grid-column: 1 / -1;
    padding: 0 .2rem;
  }

  .side-link {
    min-width: 0;
    padding: 0.78rem 0.85rem;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.06);
  }

  .side-link.active {
    background: rgba(255, 255, 255, 0.08);
  }

  .logout {
    justify-content: center;
  }

  .wrap {
    width: min(100% - 28px, 1380px);
    padding-top: 18px;
  }

  .stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .toolbar {
    padding: 14px;
  }

  .sidebar-toggle {
    display: inline-flex;
  }

  .tabs {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: stretch;
  }

  .tab {
    justify-content: center;
    min-width: 0;
  }

  .search {
    grid-column: 1 / -1;
    margin-left: 0;
    justify-content: stretch;
    padding: 10px;
    border-radius: 18px;
    background: var(--surface-alt);
    border: 1px solid var(--line);
  }

  .search input {
    width: 100%;
  }

  .queue-filters {
    gap: 8px;
  }

  .list-shell {
    padding: 16px;
  }

  .list-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  .modal {
    padding: 14px;
  }

  .modal-dialog {
    width: min(100%, 900px);
    max-height: calc(100vh - 28px);
    padding: 18px;
    border-radius: 24px;
  }

  .photos img {
    width: calc(50% - 5px);
  }
}

@media (max-width: 820px) {
  .wrap {
    width: min(100% - 22px, 1380px);
    padding-top: 14px;
  }

  .stat-grid {
    gap: 12px;
  }

  .card.stat {
    min-height: 124px;
    padding: 16px;
  }

  .panel-head {
    align-items: flex-start;
  }

  .ghost-btn {
    align-self: flex-start;
  }

  .list-grid {
    grid-template-columns: 1fr;
  }

  .item {
    padding: 14px;
  }

  .item-meta {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .sidebar,
  .wrap,
  .modal,
  .modal-dialog,
  .list-shell,
  .toolbar,
  .card.stat,
  .modal-card {
    border-radius: 0;
  }

  .sidebar {
    padding: 12px;
    border-radius: 0 0 18px 18px;
  }

  .wrap {
    width: 100%;
    padding: 10px 10px 24px;
  }

  .stat-grid,
  .list-grid {
    grid-template-columns: 1fr;
  }

  .side-brand,
  .profile {
    padding: 10px 12px;
  }

  .side-logo {
    width: 34px;
    height: 34px;
  }

  .avatar {
    width: 38px;
    height: 38px;
    border-radius: 12px;
  }

  .side-nav {
    grid-template-columns: 1fr;
  }

  .side-link,
  .logout {
    padding: 0.78rem 0.9rem;
    font-size: 0.78rem;
  }

  .side-link i,
  .logout i {
    width: 30px;
    height: 30px;
  }

  .card.stat {
    min-height: 112px;
    padding: 14px;
  }

  .toolbar,
  .list-shell {
    padding: 14px;
  }

  .toolbar-top {
    align-items: flex-start;
  }

  .tabs {
    grid-template-columns: 1fr;
    gap: 10px;
  }

  .tab {
    justify-content: flex-start;
    padding: 11px 14px;
  }

  .modal {
    padding: 10px;
  }

  .modal-dialog {
    max-height: calc(100vh - 20px);
    padding: 14px;
    border-radius: 22px;
  }

  .modal-top,
  .panel-head,
  .row {
    flex-direction: column;
    align-items: stretch;
  }

  .badges,
  .actions {
    flex-wrap: wrap;
  }

  .item-topline {
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
  }

  .item-meta {
    grid-template-columns: 1fr;
  }

  .grid > div {
    flex-basis: 100%;
  }

  .photos {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .photos img {
    width: 100%;
  }

  .search {
    flex-direction: column;
    align-items: stretch;
    padding: 8px;
  }

  .queue-filters {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .filter-chip {
    justify-content: space-between;
    min-width: 0;
  }

  .modal-action-bar {
    position: sticky;
    bottom: -14px;
    margin: 16px -14px -14px;
    padding: 12px 14px calc(12px + env(safe-area-inset-bottom, 0px));
    background: linear-gradient(180deg, rgba(248, 239, 230, 0.7), rgba(255, 252, 248, 0.98));
    backdrop-filter: blur(10px);
    border-top: 1px solid var(--line);
  }

  .actions button,
  .empty-action {
    width: 100%;
    justify-content: center;
  }
}

@media (max-width: 430px) {
  body {
    font-size: 14px;
  }

  .wrap {
    padding: 8px 8px 18px;
  }

  .side-brand strong,
  .profile strong {
    font-size: 0.76rem;
  }

  .sidebar small,
  .profile span,
  .side-nav-label {
    letter-spacing: 1.2px;
  }

  .card.stat strong {
    font-size: clamp(1.32rem, 7vw, 1.65rem);
  }

  .card.stat span,
  .subtle,
  .item-issue,
  .copy,
  .timeline-copy {
    font-size: 0.74rem;
  }

  .item-title,
  .modal-title {
    font-size: 0.92rem;
  }

  .badge,
  .report-chip,
  .search button,
  .search a,
  .ghost-btn,
  .actions button {
    font-size: 0.64rem;
  }

  .modal-close {
    width: 40px;
    height: 40px;
    border-radius: 14px;
  }

  .queue-filters {
    grid-template-columns: 1fr;
  }
}

@media (min-width: 900px) and (max-width: 1280px) and (max-height: 900px) {
  .stat-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .list-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
</head>
<body>
<div class="sidebar-scrim" data-close-sidebar></div>
<div class="shell">
  <aside class="sidebar" id="technicianSidebar">
    <div class="side-inner">
      <div class="side-brand">
        <div class="side-logo"><img src="assets/logs.png" alt="BEC logo"></div>
        <div class="brand-copy">
          <small>BEC Equipment</small>
          <strong>Technician Console</strong>
        </div>
      </div>

      <div class="profile">
        <div class="avatar"><?php echo e(initials($techName)); ?></div>
        <div>
          <strong><?php echo e($techName); ?></strong>
          <span><?php echo e($techEmail !== '' ? $techEmail : 'Technician account'); ?></span>
        </div>
      </div>

      <nav class="side-nav">
        <div class="side-nav-label">Workspace</div>
        <a class="side-link <?php echo $tab === 'my_tasks' ? 'active' : ''; ?>" href="?tab=my_tasks<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>"><i class="fas fa-list-check"></i> My Tasks <span class="side-count"><?php echo $taskCount; ?></span></a>
        <a class="side-link <?php echo $tab === 'history' ? 'active' : ''; ?>" href="?tab=history<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>"><i class="fas fa-clock-rotate-left"></i> Work History <span class="side-count"><?php echo $cHist; ?></span></a>
      </nav>
      <div class="side-footer">
        <a class="logout" href="technician/logout.php"><i class="fas fa-right-from-bracket"></i> Log Out</a>
      </div>
    </div>
  </aside>

  <main class="main">
    <div class="wrap">
      <section class="stat-grid">
        <div class="card stat"><small>Assigned</small><strong><?php echo $cAssigned; ?></strong><span>Fresh tasks waiting for field inspection.</span></div>
        <div class="card stat"><small>In Progress</small><strong><?php echo $cProg; ?></strong><span>Active repair or diagnostic work underway.</span></div>
        <div class="card stat"><small>Replacement Cases</small><strong><?php echo $cRepl; ?></strong><span>Reports that need PMO replacement follow-through.</span></div>
        <div class="card stat"><small>Awaiting PMO</small><strong><?php echo $cAwait; ?></strong><span>Completed work pending formal verification.</span></div>
        <div class="card stat"><small>Work History</small><strong><?php echo $cHist; ?></strong><span>Verified or closed technician records.</span></div>
      </section>

      <section class="card toolbar">
        <div class="toolbar-top">
          <div class="toolbar-copy">
            <strong><?php echo $tab === 'history' ? 'Resolved Records' : 'Technician Work Queue'; ?></strong>
            <span><?php echo $tab === 'history' ? 'Review verified and closed records quickly.' : 'Use quick filters to jump to the next best task.'; ?></span>
          </div>
          <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-expanded="false" aria-controls="technicianSidebar"><i class="fas fa-bars"></i> Menu</button>
        </div>
        <div class="tabs">
          <a class="tab <?php echo $tab === 'my_tasks' ? 'active' : ''; ?>" href="?tab=my_tasks<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>"><i class="fas fa-list-check"></i> My Tasks</a>
          <a class="tab <?php echo $tab === 'history' ? 'active' : ''; ?>" href="?tab=history<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>"><i class="fas fa-clock-rotate-left"></i> Work History</a>
          <form class="search" method="get">
          <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
          <input type="hidden" name="queue" value="<?php echo e($queueFilter); ?>">
          <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Search report, equipment, location, or issue">
          <button type="submit"><i class="fas fa-magnifying-glass"></i> Search</button>
          <?php if ($search !== ''): ?><a href="?tab=<?php echo e($tab); ?>&queue=<?php echo e($queueFilter); ?>"><i class="fas fa-xmark"></i> Clear</a><?php endif; ?>
          </form>
        </div>
      </section>

  <?php if ($flash['message'] !== ''): ?>
  <div class="flash <?php echo $flash['type'] === 'ok' ? 'ok' : 'err'; ?>"><?php echo e($flash['message']); ?></div>
  <?php endif; ?>

  <section class="workspace">
    <div class="card list-shell">
      <div class="panel-head">
        <div>
          <small><?php echo $tab === 'history' ? 'Completed Records' : 'Active Task Queue'; ?></small>
          <h2 class="section-title"><?php echo $tab === 'history' ? 'Work History' : 'My Tasks'; ?></h2>
        </div>
        <div class="ghost-btn"><?php echo $totalItems; ?> items</div>
      </div>
      <?php $source = $list; ?>
      <div class="list-stack">
      <div class="list-head">
        <div>
          <strong><?php echo $tab === 'history' ? 'Resolved technician records' : 'Assigned repair workload'; ?></strong>
          <div class="subtle"><?php echo $search !== '' ? 'Filtered by your current search.' : 'Showing the current queue for this view.'; ?></div>
        </div>
      </div>
      <div class="queue-filters">
        <?php if ($tab === 'history'): ?>
          <?php foreach ([['all','All Records','fa-layer-group'],['verified','Verified','fa-badge-check'],['closed','Closed','fa-circle-check']] as [$filterKey,$filterLabel,$filterIcon]): ?>
            <a class="filter-chip <?php echo $queueFilter === $filterKey ? 'active' : ''; ?>" href="?tab=<?php echo e($tab); ?>&queue=<?php echo e($filterKey); ?><?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">
              <i class="fas <?php echo e($filterIcon); ?>"></i> <?php echo e($filterLabel); ?> <strong><?php echo (int)($queueCounts[$filterKey] ?? 0); ?></strong>
            </a>
          <?php endforeach; ?>
        <?php else: ?>
          <?php foreach ([['all','All Tasks','fa-layer-group'],['urgent','Urgent','fa-bolt'],['assigned','Assigned','fa-clipboard-list'],['in_progress','In Progress','fa-screwdriver-wrench'],['completed','Awaiting PMO','fa-user-check']] as [$filterKey,$filterLabel,$filterIcon]): ?>
            <a class="filter-chip <?php echo $queueFilter === $filterKey ? 'active' : ''; ?>" href="?tab=<?php echo e($tab); ?>&queue=<?php echo e($filterKey); ?><?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">
              <i class="fas <?php echo e($filterIcon); ?>"></i> <?php echo e($filterLabel); ?> <strong><?php echo (int)($queueCounts[$filterKey] ?? 0); ?></strong>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php if (!$source): ?>
      <div class="empty">
        <i class="fas fa-clipboard-check"></i>
        <strong>No records match the current view.</strong>
        <div><?php echo $search !== '' ? 'Try clearing the current search or switch to another queue.' : 'There are no technician records in this section right now.'; ?></div>
        <div class="empty-actions">
          <?php if ($search !== ''): ?><a class="empty-action" href="?tab=<?php echo e($tab); ?>"><i class="fas fa-xmark"></i> Clear Search</a><?php endif; ?>
          <a class="empty-action" href="?tab=my_tasks"><i class="fas fa-list-check"></i> Go to My Tasks</a>
        </div>
      </div>
      <?php else: ?>
      <div class="list-grid">
      <?php foreach ($source as $row): ?>
      <button class="item-trigger" type="button" data-modal-target="modal-<?php echo e((string)$row['report_id']); ?>">
        <div class="item">
          <div class="item-topline">
            <div class="report-chip"><i class="fas fa-hashtag"></i> Report <?php echo e((string)$row['report_id']); ?></div>
            <div class="quick-note"><?php echo e(nextActionLabel((string)($row['status'] ?? 'assigned'))); ?></div>
          </div>
          <div class="row">
            <div>
              <h3 class="item-title"><?php echo e((string)($row['equipment_name'] ?? 'Equipment')); ?></h3>
              <div class="subtle" style="margin-top:6px"><?php echo e((string)($row['location'] ?? 'Unspecified')); ?></div>
            </div>
            <div class="badges">
              <span class="badge <?php echo e(stone((string)($row['status'] ?? 'assigned'))); ?>"><i class="fas <?php echo e(sicon((string)($row['status'] ?? 'assigned'))); ?>"></i><?php echo e(slabel((string)($row['status'] ?? 'assigned'))); ?></span>
              <span class="badge <?php echo e(ptone((string)($row['priority'] ?? 'medium'))); ?>"><i class="fas <?php echo e(picon((string)($row['priority'] ?? 'medium'))); ?>"></i><?php echo e(ucfirst((string)($row['priority'] ?? 'medium'))); ?></span>
            </div>
          </div>
          <div class="item-issue"><?php echo e((string)($row['issue_description'] ?? 'No issue description recorded.')); ?></div>
          <div class="item-meta">
            <div class="meta-card"><small>Status Focus</small><strong><?php echo e(slabel((string)($row['status'] ?? 'assigned'))); ?></strong></div>
            <div class="meta-card"><small><?php echo $tab === 'history' ? 'Completed' : 'Reported'; ?></small><strong><?php echo e(fdate((string)($tab === 'history' ? ($row['completion_date'] ?? $row['report_date'] ?? '') : ($row['report_date'] ?? '')))); ?></strong></div>
          </div>
        </div>
      </button>
      <?php endforeach; ?>
      </div>
      <?php endif; ?>
      </div>
    </div>
  </section>

  <?php foreach ($source as $row): ?>
  <?php $modalStatus = strtolower((string)($row['status'] ?? 'assigned')); ?>
  <div class="modal" id="modal-<?php echo e((string)$row['report_id']); ?>" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-top">
        <div class="modal-head">
          <small>Task Details</small>
          <h2 class="modal-title"><?php echo e((string)($row['equipment_name'] ?? 'Equipment')); ?></h2>
          <div class="subtle" style="margin-top:6px">Report <?php echo e((string)$row['report_id']); ?> is currently <?php echo e(slabel($modalStatus)); ?>.</div>
        </div>
        <button class="modal-close" type="button" data-modal-close aria-label="Close modal"><i class="fas fa-xmark"></i></button>
      </div>

      <div class="modal-stack">
        <div class="modal-card detail-hero">
          <div class="row">
            <div class="badges">
              <span class="badge <?php echo e(stone($modalStatus)); ?>"><i class="fas <?php echo e(sicon($modalStatus)); ?>"></i><?php echo e(slabel($modalStatus)); ?></span>
              <span class="badge <?php echo e(ptone((string)($row['priority'] ?? 'medium'))); ?>"><i class="fas <?php echo e(picon((string)($row['priority'] ?? 'medium'))); ?>"></i><?php echo e(ucfirst((string)($row['priority'] ?? 'medium'))); ?></span>
            </div>
          </div>
          <div class="grid" style="margin-top:10px">
            <div><small>Asset Tag</small><strong><?php echo e((string)($row['asset_tag'] ?? 'Not specified')); ?></strong></div>
            <div><small>Location</small><strong><?php echo e((string)($row['location'] ?? 'Unspecified')); ?></strong></div>
            <div><small>Category</small><strong><?php echo e((string)($row['category_name'] ?? 'Not specified')); ?></strong></div>
            <div><small>Reported</small><strong><?php echo e(fdt((string)($row['report_date'] ?? ''))); ?></strong></div>
          </div>
        </div>

        <div class="modal-card">
          <div class="panel-head" style="margin:0">
            <div>
              <small>Issue Summary</small>
              <h2 class="section-title">Issue Description</h2>
            </div>
          </div>
          <div class="copy"><?php echo nl2br(e((string)($row['issue_description'] ?? 'No issue description recorded.'))); ?></div>
        </div>

        <?php if (trim((string)($row['handler_instructions'] ?? '')) !== ''): ?>
        <div class="modal-card">
          <div class="panel-head" style="margin:0">
            <div>
              <small>PMO Guidance</small>
              <h2 class="section-title">Assignment Instructions</h2>
            </div>
          </div>
          <div class="copy"><?php echo nl2br(e((string)$row['handler_instructions'])); ?></div>
        </div>
        <?php endif; ?>

        <?php if (trim((string)($row['technician_notes'] ?? '')) !== ''): ?>
        <div class="modal-card">
          <div class="panel-head" style="margin:0">
            <div>
              <small>Recorded Work</small>
              <h2 class="section-title">Latest Technician Notes</h2>
            </div>
          </div>
          <div class="copy"><?php echo nl2br(e((string)$row['technician_notes'])); ?></div>
        </div>
        <?php endif; ?>

        <div class="modal-card">
          <div class="panel-head" style="margin:0">
            <div>
              <small>Workflow</small>
              <h2 class="section-title">Maintenance Flow</h2>
            </div>
          </div>
          <div class="timeline">
            <?php foreach (workflowSteps((string)($row['status'] ?? 'assigned')) as $step): ?>
            <div class="step">
              <div class="dot <?php echo $step['done'] ? 'done' : ($step['active'] ? 'act' : ''); ?>"><?php echo $step['done'] ? '✓' : ($step['active'] ? '!' : '•'); ?></div>
              <div><strong><?php echo e($step['label']); ?></strong><div class="timeline-copy" style="margin-top:4px"><?php echo e($step['desc']); ?></div></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (!empty($row['photos'])): ?>
        <div class="modal-card">
          <div class="panel-head" style="margin:0">
            <div>
              <small>Evidence</small>
              <h2 class="section-title">Photo Evidence</h2>
            </div>
          </div>
          <div class="photos">
            <?php foreach ($row['photos'] as $photo): ?><img src="<?php echo e($photo); ?>" alt="Defect photo"><?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'my_tasks'): ?>
        <div class="modal-card">
          <div class="panel-head" style="margin:0">
            <div>
              <small>Update Case</small>
              <h2 class="section-title">Technician Actions</h2>
            </div>
          </div>
          <form class="form" method="post">
            <input type="hidden" name="report_id" value="<?php echo e((string)$row['report_id']); ?>">
            <label for="technician_notes_<?php echo e((string)$row['report_id']); ?>">Inspection / repair summary</label>
            <textarea id="technician_notes_<?php echo e((string)$row['report_id']); ?>" name="technician_notes" placeholder="Record the inspection findings, repair work performed, test results, or the reason replacement is recommended."><?php echo e((string)($row['technician_notes'] ?? '')); ?></textarea>
            <div class="actions modal-action-bar">
              <?php if ($modalStatus === 'assigned'): ?><button class="b1" type="submit" name="action" value="start">Start Inspection</button><?php endif; ?>
              <?php if (in_array($modalStatus, ['assigned','in_progress','for_replacement','completed'], true)): ?><button class="b2" type="submit" name="action" value="save">Save Notes</button><?php endif; ?>
              <?php if (in_array($modalStatus, ['assigned','in_progress'], true)): ?><button class="b3" type="submit" name="action" value="replace">Recommend Replacement</button><?php endif; ?>
              <?php if ($modalStatus === 'for_replacement'): ?><button class="b2" type="submit" name="action" value="resume">Resume Repair</button><?php endif; ?>
              <?php if (in_array($modalStatus, ['in_progress','for_replacement'], true)): ?><button class="b4" type="submit" name="action" value="complete">Submit to PMO Verification</button><?php endif; ?>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
    </div>
  </main>
</div>
<script>
const body = document.body;
const sidebar = document.getElementById('technicianSidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarClosers = document.querySelectorAll('[data-close-sidebar]');

function setSidebarOpen(open) {
  body.classList.toggle('sidebar-open', open);
  if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
}

if (sidebarToggle) {
  sidebarToggle.addEventListener('click', () => {
    setSidebarOpen(!body.classList.contains('sidebar-open'));
  });
}

sidebarClosers.forEach((node) => {
  node.addEventListener('click', () => setSidebarOpen(false));
});

window.addEventListener('resize', () => {
  if (window.innerWidth > 960) setSidebarOpen(false);
});

document.querySelectorAll('[data-modal-target]').forEach((trigger) => {
  trigger.addEventListener('click', () => {
    const modal = document.getElementById(trigger.getAttribute('data-modal-target'));
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
  });
});
document.querySelectorAll('[data-modal-close]').forEach((button) => {
  button.addEventListener('click', () => {
    const modal = button.closest('.modal');
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
  });
});
document.querySelectorAll('.modal').forEach((modal) => {
  modal.addEventListener('click', (event) => {
    if (event.target !== modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
  });
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && body.classList.contains('sidebar-open')) {
    setSidebarOpen(false);
  }
  if (event.key !== 'Escape') return;
  document.querySelectorAll('.modal.open').forEach((modal) => {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
  });
  document.body.classList.remove('modal-open');
});
</script>
</body>
</html>
