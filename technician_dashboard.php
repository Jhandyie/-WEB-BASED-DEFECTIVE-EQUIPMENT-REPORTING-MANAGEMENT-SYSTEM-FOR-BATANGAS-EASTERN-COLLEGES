<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/technician_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/webpush.php';

requireRole('technician');

$conn = getDBConnection();
$vapidPublicKey = wpPublicKey(); // applicationServerKey for Web Push
$techId = trim((string)($_SESSION['user_id'] ?? ''));
$techName = trim((string)($_SESSION['fullname'] ?? 'Technician'));
$techEmail = trim((string)($_SESSION['user_email'] ?? ''));
$techKeys = technicianIdentityKeysFromSession($_SESSION);

// Which unit does this technician belong to? ITSO technicians get a slimmer completion form
// (no cost fields); PMO (and anyone else) get the cost fields. Keyed off the Department.
$techDept = trim((string)($_SESSION['department'] ?? ''));
if ($techDept === '' && $techId !== '') {
    $dq = $conn->prepare("SELECT department FROM users WHERE user_id = ? LIMIT 1");
    if ($dq) { $dq->bind_param('s', $techId); $dq->execute(); $drow = $dq->get_result()->fetch_assoc(); $techDept = trim((string)($drow['department'] ?? '')); $dq->close(); }
}
$techIsItso = (stripos($techDept, 'itso') !== false);

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
        'accepted' => 'Received',
        'in_progress' => 'In Progress',
        'waiting_for_materials' => 'Waiting for Materials',
        'for_replacement' => 'Replacement Recommended',
        'completed' => 'Awaiting PMO Verification',
        'verified' => 'Verified',
        'closed' => 'Closed',
    ][strtolower(trim($s))] ?? ucwords(str_replace('_', ' ', $s));
}
function stone(string $s): string {
    return [
        'assigned' => 'pend',
        'accepted' => 'prog',
        'in_progress' => 'prog',
        'waiting_for_materials' => 'repl',
        'for_replacement' => 'repl',
        'completed' => 'await',
        'verified' => 'done',
        'closed' => 'done',
    ][strtolower(trim($s))] ?? 'pend';
}
function sicon(string $s): string {
    return [
        'assigned' => 'fa-clipboard-list',
        'accepted' => 'fa-hand',
        'in_progress' => 'fa-screwdriver-wrench',
        'waiting_for_materials' => 'fa-hourglass-half',
        'for_replacement' => 'fa-rotate',
        'completed' => 'fa-user-check',
        'verified' => 'fa-badge-check',
        'closed' => 'fa-circle-check',
    ][strtolower(trim($s))] ?? 'fa-circle';
}
function sprogress(string $s): int {
    return [
        'assigned' => 12, 'accepted' => 32, 'in_progress' => 55,
        'waiting_for_materials' => 55, 'for_replacement' => 60,
        'completed' => 85, 'verified' => 100, 'closed' => 100,
    ][strtolower(trim($s))] ?? 12;
}
function sstep(string $s): int {
    return [
        'assigned' => 1, 'accepted' => 1, 'in_progress' => 2,
        'waiting_for_materials' => 3, 'for_replacement' => 3,
        'completed' => 4, 'verified' => 5, 'closed' => 5,
    ][strtolower(trim($s))] ?? 1;
}
function eqicon(string $name): string {
    $n = strtolower($name);
    if (preg_match('/projector/', $n)) return 'fa-video';
    if (preg_match('/(computer|\bpc\b|desktop|laptop|cpu)/', $n)) return 'fa-desktop';
    if (preg_match('/(aircon|air.?con|\bac\b|hvac|cooling)/', $n)) return 'fa-snowflake';
    if (preg_match('/(printer|copier|xerox|scanner)/', $n)) return 'fa-print';
    if (preg_match('/(tv|television|monitor|screen|display)/', $n)) return 'fa-tv';
    if (preg_match('/(fan|ventil)/', $n)) return 'fa-fan';
    if (preg_match('/(speaker|audio|sound|amplifier|\bmic\b)/', $n)) return 'fa-volume-high';
    if (preg_match('/(light|lamp|bulb)/', $n)) return 'fa-lightbulb';
    if (preg_match('/(router|network|wifi|switch)/', $n)) return 'fa-wifi';
    if (preg_match('/(board|whiteboard)/', $n)) return 'fa-chalkboard';
    return 'fa-screwdriver-wrench';
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
        'assigned' => 'Receive this task',
        'accepted' => 'Start the repair',
        'in_progress' => 'Continue repair',
        'waiting_for_materials' => 'Waiting for materials',
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
function taskVideos(array $row): array {
    $out = [];
    $raw = $row['defect_videos'] ?? '';
    if (is_string($raw) && trim($raw) !== '') {
        $dec = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
            foreach ($dec as $v) { $v = trim((string)$v); if ($v !== '' && !in_array($v, $out, true)) $out[] = str_replace('\\', '/', $v); }
        } else {
            $out[] = str_replace('\\', '/', trim((string)$raw));
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
$startedExpr = isset($drCols['started_at']) ? 'r.started_at' : (isset($drCols['date_started']) ? 'r.date_started' : 'NULL');
$equipmentIdExpr = isset($drCols['equipment_id']) ? 'r.equipment_id' : "''";
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
// Queue-oriented views (list rendering, filters, workspace) share the "my_tasks" data shape.
$listTab = $tab === 'history' ? 'history' : 'my_tasks';
$search = trim((string)($_GET['search'] ?? ''));
$queueFilter = trim((string)($_GET['queue'] ?? 'all'));
$selectedId = trim((string)($_GET['report'] ?? ''));
$flash = ['type' => trim((string)($_GET['flash_type'] ?? '')), 'message' => trim((string)($_GET['flash'] ?? ''))];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    // Notification center: mark one / all as read (not tied to a defect report).
    if ($action === 'mark_read' || $action === 'mark_all_read') {
        try {
            if ($action === 'mark_all_read') {
                $st = $conn->prepare('UPDATE notifications SET is_read = true, read_at = NOW() WHERE user_id = ? AND is_read = false');
                $st->bind_param('s', $techId);
                $st->execute();
                $st->close();
            } else {
                $nid = trim((string)($_POST['notification_id'] ?? ''));
                if ($nid !== '') {
                    $st = $conn->prepare('UPDATE notifications SET is_read = true, read_at = NOW() WHERE user_id = ? AND notification_id = ?');
                    $st->bind_param('ss', $techId, $nid);
                    $st->execute();
                    $st->close();
                }
            }
        } catch (Exception $e) { /* notifications table optional — ignore */ }
        header('Location: technician_dashboard.php?tab=my_tasks&notif=1');
        exit;
    }

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
            if ($action === 'accept' && $current === 'assigned') {
                $updates['status'] = 'accepted';
                if (isset($drCols['accepted_at'])) $updates['accepted_at'] = date('Y-m-d H:i:s');
                if ($notes !== '' && $notesField !== '') $updates[$notesField] = $notes;
                $message = 'Task received. You can now start the repair.';
                $type = 'ok';
            } elseif ($action === 'start' && in_array($current, ['assigned','accepted'], true)) {
                $updates['status'] = 'in_progress';
                if (isset($drCols['started_at'])) $updates['started_at'] = date('Y-m-d H:i:s');
                if ($notes !== '' && $notesField !== '') $updates[$notesField] = $notes;
                $message = 'Repair in progress.';
                $type = 'ok';
            } elseif ($action === 'save' && in_array($current, ['assigned','accepted','in_progress','waiting_for_materials','for_replacement','completed'], true) && $notes !== '' && $notesField !== '') {
                $updates[$notesField] = $notes;
                $message = 'Technician notes saved.';
                $type = 'ok';
            } elseif ($action === 'waiting' && in_array($current, ['assigned','accepted','in_progress'], true)) {
                $updates['status'] = 'waiting_for_materials';
                if ($notes !== '' && $notesField !== '') $updates[$notesField] = $notes;
                $message = 'Marked as waiting for materials.';
                $type = 'ok';
            } elseif ($action === 'resume_materials' && $current === 'waiting_for_materials') {
                $updates['status'] = 'in_progress';
                if ($notes !== '' && $notesField !== '') $updates[$notesField] = $notes;
                $message = 'Materials received — repair resumed.';
                $type = 'ok';
            } elseif ($action === 'replace' && in_array($current, ['assigned','accepted','in_progress','waiting_for_materials'], true) && $notes !== '' && $notesField !== '') {
                $updates['status'] = 'for_replacement';
                $updates[$notesField] = $notes;
                $message = 'Replacement recommendation submitted.';
                $type = 'ok';
            } elseif ($action === 'resume' && $current === 'for_replacement') {
                $updates['status'] = 'in_progress';
                if ($notes !== '' && $notesField !== '') $updates[$notesField] = $notes;
                $message = 'Task returned to repair in progress.';
                $type = 'ok';
            } elseif ($action === 'complete' && in_array($current, ['in_progress','for_replacement','waiting_for_materials'], true) && $notes !== '' && $notesField !== '') {
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
            } elseif ($updates && $type === 'ok') {
                $fresh = getDefectReportById($reportId) ?: $report;
                if ($action === 'accept') {
                    notifyReporter($fresh, 'A technician has received your report ' . $reportId . ' and will begin work.', 'Received by Technician', 'A technician has acknowledged your report and will begin the repair work soon.');
                } elseif ($action === 'start') {
                    notifyReporter($fresh, 'Repair work has started on your report ' . $reportId . '.', 'Repair In Progress', 'Good news — a technician has started the repair work on your reported equipment.');
                } elseif ($action === 'complete') {
                    notifyReporter($fresh, 'Repair completed for ' . $reportId . '. Awaiting PMO verification.', 'Repair Completed', 'The technician has completed the repair work. It is now pending final verification by the Property Management Office.');
                }
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
$activeStatuses = ['assigned','accepted','in_progress','waiting_for_materials','for_replacement','completed'];
$historyStatuses = ['verified','closed'];
$activeSql = "SELECT r.report_id,$equipmentIdExpr equipment_id,r.assigned_date assigned_date,$startedExpr started_at,$equipmentExpr equipment_name,$issueExpr issue_description,$priorityExpr priority,$statusExpr status,$locationExpr location,$reportDateExpr report_date,$completionExpr completion_date,$notesExpr technician_notes,$instExpr handler_instructions,$assetExpr asset_tag,$categoryExpr category_name,r.photo_path photo_path,r.defect_photos defect_photos,r.defect_videos defect_videos FROM defect_reports r $join WHERE $assigneeWhere AND $statusExpr IN ('assigned','accepted','in_progress','waiting_for_materials','for_replacement','completed') $searchSql ORDER BY FIELD($statusExpr,'assigned','accepted','in_progress','waiting_for_materials','for_replacement','completed'), FIELD($priorityExpr,'critical','high','medium','low'), $reportDateExpr DESC";
$historySql = "SELECT r.report_id,$equipmentIdExpr equipment_id,$equipmentExpr equipment_name,$issueExpr issue_description,$priorityExpr priority,$statusExpr status,$locationExpr location,$reportDateExpr report_date,$completionExpr completion_date,$notesExpr technician_notes,$instExpr handler_instructions,$assetExpr asset_tag,$categoryExpr category_name,r.photo_path photo_path,r.defect_photos defect_photos,r.defect_videos defect_videos FROM defect_reports r $join WHERE $assigneeWhere AND $statusExpr IN ('verified','closed') $searchSql ORDER BY COALESCE($completionExpr,$reportDateExpr) DESC";
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
foreach ($activeTasks as &$t) { $t['photos'] = taskPhotos($t); $t['videos'] = taskVideos($t); }
unset($t);
foreach ($historyTasks as &$t) { $t['photos'] = taskPhotos($t); $t['videos'] = taskVideos($t); }
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

$totalItems = count($list);

/* ── Previous Repairs: maintenance_history for the equipment shown in the workspace ── */
$maintByEquip = [];
$eqIds = array_values(array_unique(array_filter(array_map(fn($r) => trim((string)($r['equipment_id'] ?? '')), $list))));
if ($eqIds) {
    try {
        $ph = implode(',', array_fill(0, count($eqIds), '?'));
        $ms = $conn->prepare("SELECT equipment_id, report_id, maintenance_type, work_description, parts_used, cost, maintenance_date FROM maintenance_history WHERE equipment_id IN ($ph) ORDER BY maintenance_date DESC");
        $ms->bind_param(str_repeat('s', count($eqIds)), ...$eqIds);
        $ms->execute();
        foreach ($ms->get_result()->fetch_all(MYSQLI_ASSOC) as $mr) { $maintByEquip[(string)$mr['equipment_id']][] = $mr; }
        $ms->close();
    } catch (Exception $e) { $maintByEquip = []; }
}

$cAssigned = count(array_filter($activeTasks, fn($r) => in_array(($r['status'] ?? ''), ['assigned','accepted'], true)));
$cProg = count(array_filter($activeTasks, fn($r) => in_array(($r['status'] ?? ''), ['in_progress','waiting_for_materials'], true)));
$cRepl = count(array_filter($activeTasks, fn($r) => ($r['status'] ?? '') === 'for_replacement'));
$cAwait = count(array_filter($activeTasks, fn($r) => ($r['status'] ?? '') === 'completed'));
$cHist = count($historyTasks);
$taskCount = count($activeTasks);
// "Assigned Today" — tasks handed to this technician today (overview metric, #13)
$cToday = count(array_filter($activeTasks, function ($r) {
    $d = strtotime((string)($r['assigned_date'] ?? $r['report_date'] ?? ''));
    return $d && date('Y-m-d', $d) === date('Y-m-d');
}));
$selectedStatus = strtolower((string)($selected['status'] ?? 'assigned'));
$selectedStatusLabel = slabel($selectedStatus);
$selectedPriority = ucfirst((string)($selected['priority'] ?? 'medium'));

/* ── SLA windows per priority — configurable in config/sla.php ── */
require_once __DIR__ . '/config/sla.php';
function slaWindowSeconds(string $p): int {
    return becSlaSeconds($p);
}
/* SLA due epoch for a task, measured from assignment (falls back to report date). */
function slaDueTs(array $row): ?int {
    $base = strtotime((string)($row['assigned_date'] ?? $row['report_date'] ?? ''));
    if (!$base) return null;
    return $base + slaWindowSeconds((string)($row['priority'] ?? 'medium'));
}
$openStatuses = ['assigned','accepted','in_progress','waiting_for_materials','for_replacement'];
$cUrgent  = count(array_filter($activeTasks, fn($r) => in_array(strtolower((string)($r['priority'] ?? '')), ['critical','high'], true) && in_array(strtolower((string)($r['status'] ?? '')), $openStatuses, true)));
$cOverdue = count(array_filter($activeTasks, function ($r) use ($openStatuses) {
    if (!in_array(strtolower((string)($r['status'] ?? '')), $openStatuses, true)) return false;
    $due = slaDueTs($r); return $due !== null && $due < time();
}));

/* ── Current Task Panel: the technician's live active repair (pinned until completed) ── */
$currentRepair = null;
foreach (['in_progress','waiting_for_materials','for_replacement','accepted'] as $wantStatus) {
    foreach ($activeTasks as $r) { if (strtolower((string)($r['status'] ?? '')) === $wantStatus) { $currentRepair = $r; break 2; } }
}
/* Quick action: next task awaiting acceptance. */
$nextToAccept = null;
foreach ($activeTasks as $r) { if (strtolower((string)($r['status'] ?? '')) === 'assigned') { $nextToAccept = $r; break; } }

/* ── Notification Center feed (table is optional; degrade gracefully) ── */
$notifications = [];
$unreadCount = 0;
try {
    $ns = $conn->prepare("SELECT notification_id, message, type, link, related_id, is_read, created_date FROM notifications WHERE user_id = ? ORDER BY created_date DESC LIMIT 60");
    $ns->bind_param('s', $techId);
    $ns->execute();
    $notifications = $ns->get_result()->fetch_all(MYSQLI_ASSOC);
    $ns->close();
    foreach ($notifications as $n) { if (!notifIsRead($n['is_read'] ?? false)) $unreadCount++; }
} catch (Exception $e) { $notifications = []; $unreadCount = 0; }

/* Robust truthy check — the Postgres adapter may return booleans as 't'/'f' strings. */
function notifIsRead($v): bool { return in_array(strtolower(trim((string)$v)), ['1','t','true','yes','on'], true); }
/* Notification presentation helpers. */
function notifIcon(string $type): string {
    $t = strtolower($type);
    if (strpos($t, 'assign') !== false) return 'fa-clipboard-list';
    if (strpos($t, 'budget') !== false || strpos($t, 'finance') !== false) return 'fa-coins';
    if (strpos($t, 'workflow') !== false || strpos($t, 'status') !== false) return 'fa-diagram-project';
    if (strpos($t, 'reminder') !== false) return 'fa-bell';
    if (strpos($t, 'claim') !== false) return 'fa-hand';
    return 'fa-circle-info';
}
function notifBucket(string $created): string {
    $t = strtotime($created); if (!$t) return 'Earlier';
    $d = date('Y-m-d', $t);
    if ($d === date('Y-m-d')) return 'Today';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return 'Earlier';
}
$notifGroups = ['Today' => [], 'Yesterday' => [], 'Earlier' => []];
foreach ($notifications as $n) { $notifGroups[notifBucket((string)($n['created_date'] ?? ''))][] = $n; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#4A0E0E">
<title>Technician Portal — BEC PMO</title>
<link rel="icon" type="image/png" href="assets/logs.png">
<link rel="apple-touch-icon" href="assets/pwa-icon-192.png">
<link rel="manifest" href="manifest.webmanifest">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Outfit:wght@600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ============================================================================
   BEC PMO — TECHNICIAN PORTAL
   Rebuilt from scratch on the admin design system (deep-maroon sidebar,
   gold seal, icon-tile nav) with the landing page's editorial polish
   (Fraunces headings, gold eyebrows, 3D buttons, soft card lifts).
   Layout: single scroll-down flow — queue → repair workspace → context.
   ============================================================================ */
:root{
  --maroon:#7B1D1D; --maroon-d:#4A0E0E; --maroon-dd:#2D0505;
  --maroon-soft:rgba(123,29,29,.07);
  --gold:#C9960C; --gold-2:#D4A017; --gold-bright:#F0C040;
  --ink:#1C1008; --ink2:#5C3838; --ink3:#8A7466;
  --paper:#F4F1EC; --surface:#FFFFFF; --field:#FBF9F6; --bdr:#E2D9CC;
  --blue:#1D4ED8; --blue-soft:#E8EFFF; --green:#1A7A33; --green-soft:#EEF7F0;
  --amber:#9A6A00; --amber-soft:#FFF7E6; --danger:#B42318; --danger-soft:#FDECEC;
  --sb:262px;
  --r1:10px; --r2:14px; --r3:18px;
  --sh1:0 2px 10px rgba(44,10,10,.06); --sh2:0 14px 34px rgba(44,10,10,.13);
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto;}}
body{
  font-family:'DM Sans',system-ui,sans-serif; font-size:15px; color:var(--ink); min-height:100vh;
  background:radial-gradient(110% 70% at 100% 0%,rgba(201,150,12,.08),transparent 46%),var(--paper);
}
img{display:block;max-width:100%;}
a{color:inherit;text-decoration:none;}
button,input,textarea,select{font:inherit;}
h1,h2,h3{font-family:'Fraunces',Georgia,serif;letter-spacing:-.01em;}

/* ═══════════════ SIDEBAR — admin pattern ═══════════════ */
.sb{position:fixed;left:0;top:0;width:var(--sb);height:100vh;z-index:400;display:flex;flex-direction:column;overflow:hidden;
  background:linear-gradient(168deg,#1E0202 0%,#350808 38%,#4A0E0E 68%,#3A0808 100%);box-shadow:5px 0 30px rgba(45,5,5,.38);}
.sb::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 110% 45% at 50% -5%,rgba(212,160,23,.12),transparent);pointer-events:none;}
.sb-top{padding:1.35rem 1.2rem .95rem;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:.75rem;position:relative;z-index:1;}
.seal-ring{position:relative;width:46px;height:46px;flex-shrink:0;}
.seal-spin{position:absolute;inset:-3px;border-radius:50%;background:conic-gradient(var(--gold-2) 0%,var(--gold-bright) 30%,var(--gold-2) 60%,var(--gold-bright) 80%,var(--gold-2) 100%);animation:sealSpin 7s linear infinite;opacity:.72;}
@keyframes sealSpin{to{transform:rotate(360deg)}}
.seal-core{position:absolute;inset:2px;border-radius:50%;overflow:hidden;background:var(--maroon-dd);}
.seal-core img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
.sb-brand strong{display:block;font-family:'Outfit',sans-serif;font-weight:800;font-size:.82rem;color:#fff;line-height:1.25;}
.sb-brand em{font-size:.57rem;font-style:normal;color:rgba(255,255,255,.32);text-transform:uppercase;letter-spacing:1.8px;}
.sb-user{margin:.55rem 1rem .25rem;padding:.65rem .875rem;background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.07);border-radius:var(--r2);display:flex;align-items:center;gap:.65rem;position:relative;z-index:1;}
.sb-user .uav{width:34px;height:34px;flex-shrink:0;border-radius:50%;background:linear-gradient(135deg,var(--gold-2),#B45309);display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-weight:900;font-size:.78rem;color:#fff;box-shadow:0 3px 0 rgba(0,0,0,.28);}
.sb-user .un{min-width:0;}
.sb-user .un b{display:block;font-size:.8rem;color:#fff;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sb-user .un span{display:block;font-size:.58rem;color:rgba(255,255,255,.34);text-transform:uppercase;letter-spacing:1px;}
.sb-nav{flex:1;padding:.4rem 0;overflow-y:auto;position:relative;z-index:1;scrollbar-width:thin;scrollbar-color:rgba(212,160,23,.45) transparent;}
.sb-nav::-webkit-scrollbar{width:6px;}
.sb-nav::-webkit-scrollbar-thumb{background:rgba(212,160,23,.4);border-radius:3px;}
.nav-sec{font-size:.54rem;text-transform:uppercase;letter-spacing:2.5px;color:rgba(255,255,255,.2);padding:.6rem 1.25rem .25rem;font-weight:700;}
.ni{display:flex;align-items:center;gap:.65rem;padding:.58rem 1.25rem;color:rgba(255,255,255,.45);width:100%;text-align:left;background:none;border:none;font-family:'DM Sans',sans-serif;font-size:.84rem;font-weight:500;cursor:pointer;transition:all .16s;position:relative;}
.ni-ic{width:30px;height:30px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.78rem;background:rgba(255,255,255,.05);flex-shrink:0;transition:all .22s;}
.ni:hover{color:rgba(255,255,255,.85);}
.ni:hover .ni-ic{background:rgba(255,255,255,.1);transform:scale(1.08);}
.ni.on{color:#fff;font-weight:600;}
.ni.on .ni-ic{background:linear-gradient(135deg,var(--gold-2),var(--gold-bright));color:var(--maroon-dd);box-shadow:0 3px 0 rgba(0,0,0,.18),0 4px 12px rgba(212,160,23,.25);}
.ni.on::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(to bottom,var(--gold-2),var(--gold-bright));border-radius:0 3px 3px 0;}
.ni .nbadge{margin-left:auto;background:rgba(255,255,255,.12);color:rgba(255,255,255,.85);font-size:.62rem;font-weight:800;min-width:22px;height:20px;padding:0 6px;border-radius:10px;display:flex;align-items:center;justify-content:center;}
.ni.on .nbadge{background:rgba(0,0,0,.22);color:#fff;}
.ni .nbadge.hot{background:linear-gradient(135deg,#DC2626,#B91C1C);color:#fff;box-shadow:0 2px 8px rgba(220,38,38,.4);}
.sb-foot{padding:.6rem 1rem 1rem;border-top:1px solid rgba(255,255,255,.06);position:relative;z-index:1;}
.lout{width:100%;display:flex;align-items:center;justify-content:center;gap:.65rem;padding:.55rem .78rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);color:rgba(255,255,255,.42);border-radius:var(--r1);cursor:pointer;font-size:.8rem;font-weight:500;transition:all .18s;}
.lout:hover{background:rgba(220,38,38,.14);color:#fca5a5;border-color:rgba(220,38,38,.22);}
.sb-scrim{position:fixed;inset:0;z-index:390;background:rgba(26,10,10,.42);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);opacity:0;pointer-events:none;transition:opacity .2s;}
body.sb-open .sb-scrim{opacity:1;pointer-events:auto;}

/* ── Floating notification bell (desktop, top-right — icon only) ── */
.bell-fab{position:fixed;top:1.1rem;right:1.4rem;z-index:350;width:46px;height:46px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1.05rem;color:var(--maroon);
  background:var(--surface);border:1.5px solid var(--bdr);box-shadow:var(--sh1);transition:all .18s;}
.bell-fab:hover{color:#fff;background:linear-gradient(135deg,var(--maroon-d),var(--maroon));border-color:transparent;
  transform:translateY(-2px);box-shadow:0 10px 24px rgba(74,14,14,.28);}
.bell-dot{position:absolute;top:-4px;right:-4px;min-width:19px;height:19px;padding:0 5px;border-radius:10px;
  background:linear-gradient(135deg,#DC2626,#B91C1C);color:#fff;font-size:.62rem;font-weight:800;
  display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(220,38,38,.4);}
body.modal-open .bell-fab{display:none;}
@media(max-width:960px){.bell-fab{display:none;}} /* mobile already has the top-bar bell */

/* ═══════════════ MAIN SHELL ═══════════════ */
.main{margin-left:var(--sb);min-height:100vh;}
.wrap{max-width:1680px;margin:0 auto;padding:26px 30px 70px;}

/* ── Desktop: auto-hiding icon rail ──
   The sidebar rests as a slim icon rail and expands to full width on hover
   as an OVERLAY (content never reflows — no jumping, no stretching). */
@media(min-width:961px){
  .sb{width:78px;transition:width .22s cubic-bezier(.4,0,.2,1),box-shadow .22s ease;}
  .sb:hover{width:262px;box-shadow:10px 0 44px rgba(20,5,5,.45);}
  .main{margin-left:78px;}
  .ni,.lout{white-space:nowrap;}
  .sb:not(:hover) .sb-top{justify-content:center;padding-left:.5rem;padding-right:.5rem;}
  .sb:not(:hover) .sb-brand{display:none;}
  .sb:not(:hover) .sb-user{justify-content:center;margin-left:.5rem;margin-right:.5rem;padding:.5rem;}
  .sb:not(:hover) .sb-user .un{display:none;}
  .sb:not(:hover) .nav-sec{visibility:hidden;height:12px;padding:0;}
  .sb:not(:hover) .ni{font-size:0;justify-content:center;gap:0;padding-left:0;padding-right:0;}
  .sb:not(:hover) .ni-ic i{font-size:.82rem;}
  .sb:not(:hover) .nbadge{display:none;}
  .sb:not(:hover) .ni.on::after{display:none;}
  .sb:not(:hover) .lout{font-size:0;gap:0;}
  .sb:not(:hover) .lout i{font-size:1rem;}
}
/* Wide desktop: two-column workspace body (forms keep the full width) */
@media(min-width:1280px){
  .ws-body{grid-template-columns:1fr 1fr;align-items:start;}
  .ws-body .sec-form{grid-column:1 / -1;}
}

/* Mobile top bar */
.topbar{display:none;align-items:center;gap:12px;position:sticky;top:0;z-index:320;margin:-10px -10px 16px;padding:10px 12px;
  background:rgba(244,241,236,.92);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);border-bottom:1px solid var(--bdr);}
.tb-btn{position:relative;width:42px;height:42px;border-radius:12px;border:1px solid var(--bdr);background:var(--surface);color:var(--maroon);display:flex;align-items:center;justify-content:center;font-size:1.02rem;cursor:pointer;}
.tb-title{flex:1;font-family:'Fraunces',serif;font-weight:700;font-size:1.08rem;color:var(--ink);}
.tb-dot{position:absolute;top:-5px;right:-5px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#C0392B;color:#fff;font-size:.6rem;font-weight:800;display:flex;align-items:center;justify-content:center;}

/* Page head — landing editorial style */
.page-head{margin-bottom:18px;}
.eyebrow{display:inline-flex;align-items:center;gap:.5rem;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:1.7px;color:var(--maroon);}
.eyebrow .dot{width:6px;height:6px;border-radius:50%;background:var(--gold);box-shadow:0 0 0 3px var(--maroon-soft);}
.page-head h1{font-size:clamp(1.45rem,3vw,1.9rem);color:var(--ink);margin:.2rem 0 .25rem;}
.page-head p{font-size:.88rem;color:var(--ink3);max-width:60ch;}
/* Installable-app prompt */
/* Compact PWA action chips (buttons only) — sit neatly on desktop and stretch on mobile */
.pwa-bar{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin:0 0 14px;}
.pwa-chip{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:.6rem 1.1rem;border:none;border-radius:999px;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:700;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.15);transition:transform .12s,filter .12s;}
.pwa-chip i{font-size:.9rem;}
.pwa-chip:hover{filter:brightness(1.08);transform:translateY(-1px);}
.pwa-chip:active{transform:translateY(0);}
.pwa-chip.install{background:linear-gradient(135deg,var(--maroon-d),var(--maroon));}
.pwa-chip.alerts{background:linear-gradient(135deg,#9A6B00,var(--gold));}
.pwa-chip[hidden]{display:none;}
@media(max-width:560px){.pwa-bar{justify-content:stretch;}.pwa-chip{flex:1 1 0;}}

/* Flash */
.flash{display:flex;align-items:flex-start;gap:.6rem;padding:12px 15px;border-radius:var(--r2);font-size:.86rem;font-weight:500;margin-bottom:16px;line-height:1.5;}
.flash.ok{background:var(--green-soft);color:var(--green);border:1px solid #CFE9D6;}
.flash.err{background:var(--danger-soft);color:var(--danger);border:1px solid #F3B9B9;}
.flash i{margin-top:.14rem;}

/* Cards */
.card{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r3);box-shadow:var(--sh1);}

/* ═══════════════ QUEUE (top of the scroll flow) ═══════════════ */
.queue-shell{padding:18px;margin-bottom:18px;}
.queue-top{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.qcount{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:22px;padding:0 8px;margin-left:6px;border-radius:11px;background:var(--maroon-soft);color:var(--maroon);font-family:'Outfit',sans-serif;font-size:.72rem;font-weight:800;vertical-align:middle;}
.search{position:relative;flex:0 1 320px;min-width:220px;}
.search i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--ink3);font-size:.8rem;pointer-events:none;}
.search input{width:100%;padding:.65rem 2.2rem .65rem 2.3rem;border:1.5px solid var(--bdr);border-radius:11px;background:var(--field);font-size:.86rem;color:var(--ink);transition:border-color .15s,box-shadow .15s;}
.search input:focus{outline:none;border-color:var(--maroon);background:#fff;box-shadow:0 0 0 3px rgba(123,29,29,.08);}
.search .clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:26px;height:26px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--ink3);font-size:.75rem;}
.search .clear:hover{background:var(--maroon-soft);color:var(--maroon);}
.chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
.chip{display:inline-flex;align-items:center;gap:6px;padding:.42rem .8rem;border-radius:20px;border:1.5px solid var(--bdr);background:#fff;color:var(--ink2);font-size:.76rem;font-weight:700;transition:all .15s;}
.chip strong{font-family:'Outfit',sans-serif;font-weight:800;font-size:.72rem;color:var(--ink3);}
.chip:hover{border-color:var(--maroon);color:var(--maroon);}
.chip.on{background:linear-gradient(135deg,var(--maroon-d),var(--maroon));color:#fff;border-color:transparent;box-shadow:0 5px 14px rgba(74,14,14,.24);}
.chip.on strong{color:rgba(255,255,255,.85);}
.qgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px;}
.qcard{position:relative;display:flex;gap:12px;align-items:flex-start;text-align:left;padding:14px 14px 14px 18px;border:1px solid var(--bdr);border-radius:var(--r2);background:var(--field);cursor:pointer;transition:transform .16s,box-shadow .16s,border-color .16s,background .16s;overflow:hidden;}
.qcard::before{content:'';position:absolute;left:0;top:0;bottom:0;width:5px;background:var(--bdr);}
.qcard.pri-crit::before{background:linear-gradient(180deg,#DC2626,#991B1B);}
.qcard.pri-hi::before{background:linear-gradient(180deg,#EA580C,#B45309);}
.qcard.pri-med::before{background:linear-gradient(180deg,var(--gold),#9A6B00);}
.qcard.pri-lo::before{background:linear-gradient(180deg,#94A3B8,#64748B);}
.qcard:hover{background:#fff;border-color:rgba(123,29,29,.28);transform:translateY(-3px);box-shadow:var(--sh2);}
.qcard.active{background:#fff;border-color:var(--maroon);box-shadow:0 0 0 3px rgba(123,29,29,.1),var(--sh2);}
.q-ic{width:44px;height:44px;flex-shrink:0;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--maroon);background:linear-gradient(135deg,var(--maroon-soft),rgba(201,150,12,.14));border:1px solid rgba(123,29,29,.12);}
.q-body{min-width:0;flex:1;}
.q-top{display:flex;align-items:baseline;justify-content:space-between;gap:8px;}
.q-top strong{font-family:'Fraunces',serif;font-size:.98rem;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.q-id{font-size:.66rem;font-weight:800;color:var(--ink3);flex-shrink:0;}
.q-loc{display:flex;align-items:center;gap:.35rem;font-size:.76rem;color:var(--ink3);margin:.25rem 0 .5rem;}
.q-loc i{color:var(--gold);font-size:.68rem;}
.q-badges{display:flex;flex-wrap:wrap;gap:5px;}

/* Badges (shared) */
.badge{display:inline-flex;align-items:center;gap:5px;padding:.28rem .6rem;border-radius:20px;font-size:.64rem;font-weight:700;border:1px solid transparent;}
.badge i{font-size:.62rem;}
.badge.pend{background:var(--amber-soft);color:var(--amber);border-color:#F0D79A;}
.badge.prog{background:var(--blue-soft);color:var(--blue);border-color:#C7D8FB;}
.badge.repl{background:#FDF2E4;color:#B45309;border-color:#F3D8B4;}
.badge.await{background:#F3EDFB;color:#6D28D9;border-color:#DDD0F2;}
.badge.done{background:var(--green-soft);color:var(--green);border-color:#CFE9D6;}
.badge.crit{background:var(--danger-soft);color:var(--danger);border-color:#F3B9B9;}
.badge.hi{background:#FDF2E4;color:#B45309;border-color:#F3D8B4;}
.badge.med{background:var(--amber-soft);color:var(--amber);border-color:#F0D79A;}
.badge.lo{background:#F1F5F9;color:#64748B;border-color:#DDE5EC;}
.badge.timer{background:#EFE7DD;color:#5B4636;border-color:#DBC7A6;}
.badge.sla{background:var(--blue-soft);color:var(--blue);border-color:#C7D8FB;}
.badge.sla.soon{background:var(--amber-soft);color:var(--amber);border-color:#F0D79A;}
.badge.sla.overdue{background:var(--danger-soft);color:var(--danger);border-color:#F3B9B9;animation:slaPulse 2.4s ease-in-out infinite;}
@keyframes slaPulse{0%,100%{box-shadow:0 0 0 0 rgba(180,35,24,0);}50%{box-shadow:0 0 0 4px rgba(180,35,24,.12);}}

/* Empty state */
.empty{text-align:center;padding:44px 20px;color:var(--ink3);}
.empty i{font-size:2rem;color:var(--bdr);display:block;margin-bottom:10px;}
.empty strong{display:block;color:var(--ink2);margin-bottom:4px;font-size:.95rem;}
.empty .empty-actions{margin-top:14px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}
.empty .empty-action{display:inline-flex;align-items:center;gap:6px;padding:.5rem .95rem;border-radius:10px;border:1.5px solid var(--bdr);background:#fff;font-size:.78rem;font-weight:700;color:var(--ink2);}
.empty .empty-action:hover{border-color:var(--maroon);color:var(--maroon);}

/* ═══════════════ REPAIR WORKSPACE (revealed below the queue) ═══════════════ */
.ws-zone{scroll-margin-top:76px;}
.ws-panel{display:none;}
.ws-panel.active{display:block;animation:wsIn .32s cubic-bezier(.22,1,.36,1);}
@keyframes wsIn{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:none;}}
.ws-card{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r3);box-shadow:var(--sh2);overflow:hidden;margin-bottom:16px;}
.ws-head{display:flex;align-items:center;gap:14px;padding:18px 20px;background:linear-gradient(135deg,var(--maroon-dd),var(--maroon));color:#fff;}
.ws-back{display:none;width:38px;height:38px;flex-shrink:0;border-radius:11px;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.1);color:#fff;cursor:pointer;font-size:.9rem;}
.ws-head-copy{flex:1;min-width:0;}
.ws-head-copy small{display:block;font-size:.6rem;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold-bright);margin-bottom:3px;}
.ws-title{font-size:1.22rem;color:#fff;}
.ws-head-tags{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;}
.ws-head-tags .badge{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.2);}
.ws-head-tags .badge.sla.overdue{background:rgba(220,38,38,.35);border-color:rgba(255,255,255,.3);}

/* Workflow stepper */
.steps{display:flex;align-items:flex-start;justify-content:space-between;gap:4px;padding:18px 22px 12px;background:linear-gradient(180deg,rgba(74,14,14,.05),transparent);}
.step{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;position:relative;text-align:center;}
.step::before{content:'';position:absolute;top:17px;left:-50%;width:100%;height:3px;background:var(--bdr);z-index:0;}
.step:first-child::before{display:none;}
.step.done::before,.step.now::before{background:linear-gradient(90deg,var(--gold),var(--maroon));}
.step .nd{position:relative;z-index:1;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#fff;border:2px solid var(--bdr);color:var(--ink3);font-size:.8rem;}
.step.done .nd{background:linear-gradient(135deg,var(--maroon),var(--gold));border-color:transparent;color:#fff;}
.step.now .nd{border-color:var(--maroon);color:var(--maroon);box-shadow:0 0 0 4px rgba(123,29,29,.1);}
.step .lb{font-size:.62rem;font-weight:700;color:var(--ink3);}
.step.done .lb,.step.now .lb{color:var(--maroon-d);}

/* Workspace inner sections */
.ws-body{padding:18px 20px 22px;display:grid;gap:16px;}
.sec{border:1px solid var(--bdr);border-radius:var(--r2);padding:16px;background:var(--field);}
.sec-h{margin-bottom:10px;}
.sec-h small{display:block;font-size:.6rem;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:var(--gold);margin-bottom:2px;}
.sec-h h3{font-size:1.02rem;color:var(--ink);}
.copy{font-size:.87rem;line-height:1.7;color:var(--ink2);}
.facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;}
.facts>div{padding:10px 12px;border-radius:11px;border:1px solid var(--bdr);background:#fff;}
.facts small{display:block;font-size:.58rem;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--ink3);margin-bottom:3px;}
.facts strong{font-size:.84rem;color:var(--ink);font-weight:600;}
.photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;}
.photos img{width:100%;height:110px;object-fit:cover;border-radius:10px;border:1px solid var(--bdr);cursor:zoom-in;transition:transform .12s,box-shadow .12s;}
.vids{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;}
.vids video{width:100%;max-height:260px;border-radius:10px;border:1px solid var(--bdr);background:#000;}
.photos img:hover,.photos img:focus-visible{transform:scale(1.03);box-shadow:0 5px 16px rgba(0,0,0,.2);outline:none;border-color:var(--maroon);}

/* Fullscreen photo viewer (tap evidence to enlarge; swipe / arrows to move between photos) */
.lb-ov{position:fixed;inset:0;z-index:12000;background:rgba(12,4,4,.94);display:none;align-items:center;justify-content:center;}
.lb-ov.open{display:flex;}
.lb-img{max-width:94vw;max-height:84vh;object-fit:contain;border-radius:8px;box-shadow:0 12px 48px rgba(0,0,0,.55);user-select:none;-webkit-user-select:none;touch-action:pan-y;}
.lb-btn{position:absolute;background:rgba(255,255,255,.14);border:none;color:#fff;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;transition:background .14s;}
.lb-btn:hover{background:rgba(255,255,255,.3);}
.lb-nav{top:50%;transform:translateY(-50%);width:50px;height:50px;font-size:1.7rem;}
.lb-prev{left:12px;} .lb-next{right:12px;}
.lb-close{top:14px;right:16px;width:44px;height:44px;font-size:1.5rem;}
.lb-count{position:absolute;bottom:18px;left:50%;transform:translateX(-50%);color:#fff;font-size:.82rem;font-weight:700;background:rgba(0,0,0,.45);padding:.32rem .85rem;border-radius:999px;letter-spacing:.02em;}
.lb-btn[hidden],.lb-count[hidden]{display:none;}
@media(max-width:560px){.lb-nav{width:44px;height:44px;font-size:1.45rem;}}

/* Forms — landing-style fields + 3D buttons */
.form label{display:block;font-size:.7rem;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--ink2);margin:.85rem 0 .3rem;}
.form input,.form textarea{width:100%;padding:.68rem .85rem;border:1.5px solid var(--bdr);border-radius:11px;background:#fff;font-size:16px;color:var(--ink);transition:border-color .15s,box-shadow .15s;}
.form textarea{min-height:92px;resize:vertical;line-height:1.55;}
.form input:focus,.form textarea:focus{outline:none;border-color:var(--maroon);box-shadow:0 0 0 3px rgba(123,29,29,.08);}
.fgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;}
.fgrid label{margin-top:0;}
.frow{display:grid;grid-template-columns:2fr .7fr 1fr 1.2fr auto;gap:8px;margin-bottom:8px;align-items:center;}
.fs{display:flex;align-items:center;gap:10px;margin:1.2rem 0 .5rem;padding-top:1rem;border-top:1px dashed var(--bdr);}
.fs:first-of-type{margin-top:.4rem;padding-top:0;border-top:none;}
.fs-num{width:26px;height:26px;flex-shrink:0;border-radius:50%;background:linear-gradient(135deg,var(--maroon-d),var(--maroon));color:#fff;font-family:'Outfit',sans-serif;font-weight:800;font-size:.74rem;display:flex;align-items:center;justify-content:center;}
.fs-tx strong{display:block;font-size:.86rem;color:var(--ink);}
.fs-tx span{font-size:.72rem;color:var(--ink3);}
.check{display:flex;align-items:center;gap:.55rem;font-size:.84rem;font-weight:500;margin-top:.8rem;cursor:pointer;color:var(--ink2);}
.check input{width:16px;height:16px;flex-shrink:0;accent-color:var(--maroon);}
.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;}
.actions button{display:inline-flex;align-items:center;gap:.5rem;border:none;border-radius:11px;padding:.78rem 1.25rem;font-weight:700;font-size:.84rem;color:#fff;cursor:pointer;transition:transform .15s,box-shadow .15s,filter .15s;}
.actions button:hover{transform:translateY(-2px);filter:brightness(1.06);}
.actions button:active{transform:translateY(1px);}
.actions .b1{background:linear-gradient(135deg,var(--maroon-d),var(--maroon));box-shadow:0 4px 0 var(--maroon-dd),0 8px 18px rgba(74,14,14,.22);}
.actions .b2{background:linear-gradient(135deg,var(--maroon),#9D3535);box-shadow:0 4px 0 var(--maroon-dd),0 8px 18px rgba(74,14,14,.2);}
.actions .b3{background:linear-gradient(135deg,#B45309,#D97706);box-shadow:0 4px 0 #7C3A05,0 8px 18px rgba(180,83,9,.22);}
.actions .b4{background:linear-gradient(135deg,var(--green),#169F66);box-shadow:0 4px 0 #0C5527,0 8px 18px rgba(26,122,51,.24);}
.actions button:disabled{opacity:.55;cursor:not-allowed;transform:none;}

/* Photo capture drops */
.photo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;}
.photo-field{display:flex;flex-direction:column;}
.photo-drop{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:18px 12px;border:2px dashed var(--bdr);border-radius:var(--r2);background:#fff;cursor:pointer;text-align:center;transition:border-color .15s,background .15s;min-height:104px;}
.photo-drop:hover,.photo-drop:focus-within{border-color:var(--maroon);background:var(--field);}
.photo-drop i{font-size:1.4rem;color:var(--maroon);}
.photo-drop-label{font-weight:700;font-size:.82rem;color:var(--ink);}
.photo-hint{font-size:.68rem;color:var(--ink3);}
.photo-input{position:absolute;width:1px;height:1px;opacity:0;}
.photo-preview{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.photo-preview .pchip{width:54px;height:54px;border-radius:10px;overflow:hidden;border:1px solid var(--bdr);}
.photo-preview .pchip img{width:100%;height:100%;object-fit:cover;}
.photo-drop.has-files{border-style:solid;border-color:var(--green);background:var(--green-soft);}
.photo-drop.has-files i{color:var(--green);}

/* ═══════════════ CONTEXT (below the workspace) ═══════════════ */
.ctx{display:none;}
.ctx.active{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;}
.ctx .card{padding:16px;}
.tl{display:grid;gap:12px;}
.tl-step{display:flex;gap:10px;align-items:flex-start;}
.tl-dot{width:26px;height:26px;flex-shrink:0;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.66rem;font-weight:800;background:#fff;border:2px solid var(--bdr);color:var(--ink3);}
.tl-dot.done{background:linear-gradient(135deg,var(--maroon),var(--gold));border-color:transparent;color:#fff;}
.tl-dot.act{border-color:var(--maroon);color:var(--maroon);box-shadow:0 0 0 3px rgba(123,29,29,.1);}
.tl-step strong{display:block;font-size:.84rem;color:var(--ink);}
.tl-step p{font-size:.74rem;color:var(--ink3);line-height:1.5;margin-top:2px;}
.hist{display:grid;gap:10px;}
.hrow{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--bdr);border-radius:11px;background:var(--field);}
.hrow .hic{width:30px;height:30px;flex-shrink:0;border-radius:9px;background:var(--maroon-soft);color:var(--maroon);display:flex;align-items:center;justify-content:center;font-size:.72rem;}
.hrow strong{display:block;font-size:.8rem;color:var(--ink);}
.hrow .hw{display:block;font-size:.68rem;color:var(--ink3);margin-top:2px;}
.hrow .hd{display:block;font-size:.74rem;color:var(--ink2);margin-top:4px;line-height:1.5;}

/* ═══════════════ NOTIFICATION MODAL ═══════════════ */
.modal{position:fixed;inset:0;z-index:2000;display:none;align-items:center;justify-content:center;padding:22px;
  background:rgba(37,17,17,.6);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);}
.modal.open{display:flex;}
body.modal-open{overflow:hidden;}
.modal-dialog{width:min(620px,100%);max-height:calc(100vh - 44px);overflow:auto;background:#fff;border-radius:var(--r3);box-shadow:0 40px 90px rgba(45,5,5,.4);animation:mIn .28s cubic-bezier(.22,1,.36,1);}
@keyframes mIn{from{opacity:0;transform:translateY(18px) scale(.98);}to{opacity:1;transform:none;}}
.m-top{position:sticky;top:0;z-index:2;display:flex;justify-content:space-between;align-items:center;gap:14px;padding:16px 18px;background:linear-gradient(135deg,var(--maroon-dd),var(--maroon));color:#fff;}
.m-top small{display:block;font-size:.6rem;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:var(--gold-bright);margin-bottom:2px;}
.m-top h2{font-size:1.15rem;color:#fff;}
.m-x{flex-shrink:0;width:38px;height:38px;border-radius:11px;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.12);color:#fff;cursor:pointer;font-size:.9rem;}
.m-x:hover{background:rgba(255,255,255,.22);}
.m-body{padding:16px 18px 20px;}
.m-tools{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;}
.m-tools span{font-size:.76rem;font-weight:700;color:var(--ink3);}
.m-tools button{display:inline-flex;align-items:center;gap:6px;border:1.5px solid var(--bdr);background:#fff;border-radius:9px;padding:.42rem .8rem;font-size:.74rem;font-weight:700;color:var(--ink2);cursor:pointer;}
.m-tools button:hover{border-color:var(--maroon);color:var(--maroon);}
.ngroup{font-size:.62rem;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:var(--ink3);margin:14px 0 8px;}
.nitem{display:flex;gap:10px;align-items:flex-start;padding:11px 12px;border:1px solid var(--bdr);border-radius:var(--r2);background:var(--field);margin-bottom:8px;}
.nitem.unread{background:#FFFDF5;border-color:#EAD9A8;}
.nitem .nic{width:32px;height:32px;flex-shrink:0;border-radius:10px;background:var(--maroon-soft);color:var(--maroon);display:flex;align-items:center;justify-content:center;font-size:.76rem;}
.nitem .nmsg{font-size:.84rem;color:var(--ink);line-height:1.5;}
.nitem .nmeta{display:flex;flex-wrap:wrap;gap:12px;font-size:.68rem;color:var(--ink3);margin-top:4px;}
.nitem .nmeta a{color:var(--maroon);font-weight:700;}
.nread{margin-left:auto;flex-shrink:0;}
.nread button{width:28px;height:28px;border-radius:9px;border:1.5px solid var(--bdr);background:#fff;color:var(--green);cursor:pointer;font-size:.7rem;}
.nread button:hover{border-color:var(--green);background:var(--green-soft);}

/* ═══════════════ MOBILE BOTTOM NAV ═══════════════ */
.bnav{display:none;}
@media(max-width:960px){
  html{overflow-x:hidden;} /* nothing should push the page wider than the phone */
  .sb{transform:translateX(calc(-100% - 10px));transition:transform .26s cubic-bezier(.4,0,.2,1);}
  body.sb-open .sb{transform:none;box-shadow:0 0 60px rgba(0,0,0,.5);}
  .main{margin-left:0;min-width:0;}
  .wrap{padding:12px 12px 90px;max-width:100%;}
  .ctx.active{grid-template-columns:1fr;} /* Workflow + Asset History stack instead of cramming side-by-side */
  .ws-body{grid-template-columns:1fr;}
  /* let grid/flex children shrink so long text wraps instead of clipping / forcing width */
  .facts>div,.qcard,.qcard>*,.hrow,.hrow>*,.tl-step,.tl-step>*,.sec,.card,.ws-head,.ws-head>*{min-width:0;}
  .facts strong,.qcard .qc-sub,.qcard .qc-loc,.hrow .hd,.hrow strong,.ws-title,.tl-step p{overflow-wrap:anywhere;white-space:normal;}
  .topbar{display:flex;}
  .ws-back{display:flex;align-items:center;justify-content:center;}
  .bnav{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:300;background:linear-gradient(180deg,#3A0808,#2D0505);
    border-top:1px solid rgba(201,150,12,.25);padding:4px 4px calc(4px + env(safe-area-inset-bottom));box-shadow:0 -6px 20px rgba(45,5,5,.35);}
  .bn{position:relative;flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;padding:8px 4px;
    background:none;border:none;color:rgba(255,248,238,.72);font-size:.6rem;font-weight:700;cursor:pointer;}
  .bn i{font-size:1.12rem;}
  .bn.on{color:var(--gold-bright);}
  .bn.on::before{content:'';position:absolute;top:0;left:50%;transform:translateX(-50%);width:26px;height:3px;border-radius:3px;background:var(--gold-bright);}
  .bn .tb-dot{top:2px;right:calc(50% - 22px);}
}
@media(max-width:640px){
  .qgrid{grid-template-columns:1fr;}
  .frow{grid-template-columns:1fr 1fr;}
  .steps .lb{display:none;}
  .steps{padding:14px 12px 10px;}
  .step .nd{width:30px;height:30px;font-size:.72rem;}
  .step::before{top:14px;}
  .modal{padding:0;}
  .modal-dialog{width:100%;height:100dvh;max-height:100dvh;border-radius:0;}
  .ws-head{flex-wrap:wrap;}
  .ws-head-tags{justify-content:flex-start;}
  .actions button{flex:1 1 100%;justify-content:center;min-height:48px;}
  .facts{grid-template-columns:1fr 1fr;}
}
@media(max-width:420px){
  .frow{grid-template-columns:1fr;}
  .facts{grid-template-columns:1fr;}
}

/* ── Budget: existing-request status ── */
.br-existing{background:#fff;border:1px solid var(--bdr);border-left:4px solid var(--gold);border-radius:var(--r2);padding:12px 14px;margin-bottom:14px;}
.br-existing-title{display:flex;align-items:center;gap:.5rem;font-size:.8rem;font-weight:700;color:var(--ink2);margin-bottom:8px;}
.br-existing-title i{color:var(--green);}
.br-row{display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;padding:7px 0;border-top:1px dashed var(--bdr);font-size:.76rem;}
.br-row:first-of-type{border-top:none;}
.br-id{font-weight:800;color:var(--maroon);}
.br-id i{color:var(--gold);margin-right:3px;}
.br-amt{font-family:'Outfit',sans-serif;font-weight:800;color:var(--ink);}
.br-st{padding:.2rem .55rem;border-radius:14px;font-weight:700;font-size:.64rem;}
.br-st.wait{background:var(--amber-soft);color:var(--amber);}
.br-st.ok{background:var(--green-soft);color:var(--green);}
.br-st.bad{background:var(--danger-soft);color:var(--danger);}
.br-fin{color:#1D4ED8;font-weight:600;font-size:.7rem;}
.br-when{margin-left:auto;color:var(--ink3);font-size:.68rem;}
.fin-note{display:flex;align-items:flex-start;gap:.55rem;margin-top:.9rem;padding:10px 12px;border-radius:11px;background:var(--amber-soft);border:1px solid #F0D79A;font-size:.78rem;color:var(--ink2);line-height:1.5;}
.fin-note i{color:var(--amber);margin-top:.15rem;}
.add-row{display:inline-flex;align-items:center;gap:.45rem;margin-top:2px;padding:.5rem .9rem;border-radius:10px;border:1.5px dashed var(--bdr);background:#fff;color:var(--maroon);font-size:.78rem;font-weight:700;cursor:pointer;transition:all .15s;}
.add-row:hover{border-color:var(--maroon);background:var(--maroon-soft);}
.row-del{width:36px;height:36px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;border-radius:9px;border:1.5px solid var(--bdr);background:#fff;color:#9E8070;font-size:.8rem;cursor:pointer;transition:all .15s;}
.row-del:hover{border-color:#DC2626;background:#FEF2F2;color:#DC2626;}
@media(max-width:900px){.row-del{justify-self:start;}}

/* ── Chip fields (parts / tools / materials) ── */
.chipfield label{margin-top:0;}
.chip-entry{display:flex;gap:6px;}
.chip-entry .chip-in{flex:1;}
.chip-add{width:42px;flex-shrink:0;border-radius:11px;border:1.5px solid var(--bdr);background:#fff;color:var(--maroon);cursor:pointer;font-size:.85rem;transition:all .15s;}
.chip-add:hover{background:linear-gradient(135deg,var(--maroon-d),var(--maroon));color:#fff;border-color:transparent;}
.chip-list{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.chip-item{display:inline-flex;align-items:center;gap:6px;padding:.32rem .4rem .32rem .7rem;border-radius:16px;background:var(--maroon-soft);border:1px solid rgba(123,29,29,.16);color:var(--maroon);font-size:.74rem;font-weight:700;}
.chip-item button{width:18px;height:18px;border-radius:50%;border:none;background:rgba(123,29,29,.15);color:var(--maroon);cursor:pointer;font-size:.58rem;display:flex;align-items:center;justify-content:center;}
.chip-item button:hover{background:var(--danger);color:#fff;}

/* ── Branded toasts (replace browser alert popups) ── */
.toasts{position:fixed;top:1rem;right:1rem;z-index:4000;display:flex;flex-direction:column;gap:8px;max-width:min(380px,calc(100vw - 2rem));}
.toast{display:flex;gap:.6rem;align-items:flex-start;background:#fff;border:1px solid var(--bdr);border-left:4px solid var(--maroon);
  border-radius:12px;padding:.8rem .95rem;box-shadow:0 14px 34px rgba(44,10,10,.22);font-size:.83rem;color:var(--ink);line-height:1.5;
  white-space:pre-line;animation:toastIn .28s cubic-bezier(.22,1,.36,1);}
.toast.err{border-left-color:#DC2626;}
.toast.ok{border-left-color:#1A7A33;}
.toast i{margin-top:.15rem;flex-shrink:0;}
.toast.err i{color:#DC2626;}
.toast.ok i{color:#1A7A33;}
.toast .tx-close{margin-left:auto;flex-shrink:0;background:none;border:none;color:var(--ink3);cursor:pointer;font-size:.8rem;padding:.1rem;}
@keyframes toastIn{from{opacity:0;transform:translateX(16px);}to{opacity:1;transform:none;}}
@media(max-width:960px){.toasts{top:64px;}}

/* ── Inline field validation (missing required info) ── */
.req{color:#DC2626;font-style:normal;font-weight:800;}
.f-err{border-color:#DC2626 !important;background:#FFF8F8 !important;box-shadow:0 0 0 3px rgba(220,38,38,.12) !important;animation:fShake .3s ease;}
@keyframes fShake{0%,100%{transform:translateX(0);}25%{transform:translateX(-4px);}75%{transform:translateX(4px);}}
.f-flash{animation:fFlash .65s ease 2;}
@keyframes fFlash{0%,100%{outline:3px solid rgba(220,38,38,0);outline-offset:2px;}50%{outline:6px solid rgba(220,38,38,.45);outline-offset:3px;}}
.f-msg{display:flex;align-items:center;gap:.4rem;margin-top:.35rem;font-size:.74rem;font-weight:700;color:#DC2626;}
.f-msg i{font-size:.72rem;}

/* ── Contextual ACTION loader — each action gets its own animation ── */
.axl{position:fixed;inset:0;z-index:3000;display:flex;align-items:center;justify-content:center;
  background:rgba(37,17,17,.66);-webkit-backdrop-filter:blur(7px);backdrop-filter:blur(7px);
  opacity:0;visibility:hidden;transition:opacity .18s ease,visibility .18s ease;}
.axl.show{opacity:1;visibility:visible;}
.axl-box{display:flex;flex-direction:column;align-items:center;gap:16px;text-align:center;}
.axl-stage{position:relative;width:104px;height:104px;display:flex;align-items:center;justify-content:center;}
.axl-ring{position:absolute;inset:0;border-radius:50%;border:3px solid rgba(201,150,12,.25);border-top-color:#F0C040;animation:axlSpin .9s linear infinite;}
.axl-core{width:76px;height:76px;border-radius:50%;background:linear-gradient(135deg,#4A0E0E,#7B1D1D);
  border:1.5px solid rgba(240,192,64,.55);display:flex;align-items:center;justify-content:center;
  box-shadow:0 0 30px rgba(201,150,12,.35),inset 0 0 14px rgba(0,0,0,.3);}
.axl-core i{font-size:1.8rem;color:#F0C040;}
.axl-label{font-family:'Fraunces',serif;font-weight:700;font-size:1.02rem;color:#fff;letter-spacing:.01em;}
.axl-sub{font-size:.72rem;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:rgba(240,192,64,.85);}
@keyframes axlSpin{to{transform:rotate(360deg);}}
/* per-action icon animations */
.axl.ax-receive .axl-core i{animation:axGrab 1s ease-in-out infinite;}
@keyframes axGrab{0%,100%{transform:translateY(0) rotate(0);}35%{transform:translateY(-7px) rotate(-14deg);}65%{transform:translateY(2px) rotate(6deg);}}
.axl.ax-start .axl-core i{transform-origin:80% 80%;animation:axWrench 1s ease-in-out infinite;}
@keyframes axWrench{0%,100%{transform:rotate(-22deg);}50%{transform:rotate(28deg);}}
.axl.ax-save .axl-core i{animation:axSave 1s ease-in-out infinite;}
@keyframes axSave{0%,100%{transform:scale(1);}40%{transform:scale(.82) translateY(3px);}70%{transform:scale(1.08);}}
.axl.ax-wait .axl-core i{animation:axFlip 1.4s ease-in-out infinite;}
@keyframes axFlip{0%,15%{transform:rotate(0);}50%,65%{transform:rotate(180deg);}100%{transform:rotate(360deg);}}
.axl.ax-resume .axl-core i{animation:axSlide 1s ease-in-out infinite;}
@keyframes axSlide{0%,100%{transform:translateX(-5px);opacity:.75;}50%{transform:translateX(6px);opacity:1;}}
.axl.ax-replace .axl-core i{animation:axSpin2 1.1s linear infinite;}
@keyframes axSpin2{to{transform:rotate(360deg);}}
.axl.ax-budget .axl-core i{animation:axCoin 1s ease-in-out infinite;}
@keyframes axCoin{0%,100%{transform:translateY(0) scale(1);}30%{transform:translateY(-9px) scale(1.06);}55%{transform:translateY(2px) scale(.95);}}
.axl.ax-complete .axl-core i{animation:axStamp 1.2s ease-in-out infinite;}
@keyframes axStamp{0%,100%{transform:scale(1);}30%{transform:scale(1.28);}45%{transform:scale(.94);}60%{transform:scale(1.06);}}
.axl.ax-notif .axl-core i{transform-origin:50% 0;animation:axBell 1s ease-in-out infinite;}
@keyframes axBell{0%,100%{transform:rotate(0);}20%{transform:rotate(18deg);}40%{transform:rotate(-14deg);}60%{transform:rotate(8deg);}80%{transform:rotate(-4deg);}}
@media(prefers-reduced-motion:reduce){.axl .axl-core i,.axl-ring{animation:none !important;}}

/* ── Compact task cards on phones (dense list, less vertical space) ── */
@media(max-width:640px){
  .qgrid{gap:7px;}
  .qcard{padding:10px 10px 10px 14px;gap:9px;align-items:center;border-radius:12px;}
  .q-ic{width:34px;height:34px;border-radius:9px;font-size:.85rem;}
  .q-top strong{font-size:.88rem;}
  .q-id{font-size:.6rem;}
  .q-loc{margin:.1rem 0 .3rem;font-size:.68rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;}
  .q-loc i{display:none;}
  .q-badges{gap:4px;}
  .q-badges .badge{padding:.16rem .45rem;font-size:.56rem;gap:3px;}
  .q-badges .badge i{font-size:.54rem;}
}
/* Minimalist missing-fields popup */
.te-modal{position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;padding:1.2rem;background:rgba(20,6,6,.55);backdrop-filter:blur(3px);}
.te-modal.show{display:flex;}
.te-box{background:#fff;border-radius:16px;max-width:400px;width:100%;padding:1.7rem 1.5rem 1.4rem;text-align:center;box-shadow:0 24px 64px rgba(30,6,6,.4);animation:tePop .18s ease;}
@keyframes tePop{from{transform:scale(.94);opacity:0;}to{transform:scale(1);opacity:1;}}
.te-ic{width:54px;height:54px;border-radius:50%;background:#FDECEC;color:#C0392B;display:flex;align-items:center;justify-content:center;font-size:1.45rem;margin:0 auto 1rem;}
.te-box h3{font-family:'Outfit',sans-serif;font-size:1.15rem;font-weight:800;color:var(--ink);margin-bottom:.4rem;}
.te-box p{font-size:.85rem;color:var(--ink2);line-height:1.55;margin-bottom:1rem;}
.te-list{list-style:none;text-align:left;margin:0 0 1.15rem;padding:0;display:flex;flex-direction:column;gap:.4rem;max-height:190px;overflow:auto;}
.te-list li{font-size:.8rem;color:var(--ink);display:flex;align-items:center;gap:.55rem;padding:.55rem .75rem;background:#FBF3F3;border-radius:9px;border-left:3px solid #C0392B;}
.te-list li i{color:#C0392B;font-size:.72rem;flex-shrink:0;}
.te-btn{width:100%;padding:.82rem;border:none;border-radius:11px;background:linear-gradient(135deg,var(--maroon-d),var(--maroon));color:#fff;font-family:'Outfit',sans-serif;font-weight:700;font-size:.9rem;cursor:pointer;transition:filter .15s;}
.te-btn:hover{filter:brightness(1.08);}
</style>
</head>
<body>

<div class="sb-scrim" data-sb-close></div>

<!-- Floating notification bell (desktop, right side — icon only) -->
<button class="bell-fab" type="button" data-modal-target="notifModal" aria-label="Notifications" title="Notifications">
  <i class="fas fa-bell"></i>
  <?php if ($unreadCount > 0): ?><span class="bell-dot"><?php echo $unreadCount > 9 ? '9+' : (int)$unreadCount; ?></span><?php endif; ?>
</button>

<!-- ═══════════ SIDEBAR ═══════════ -->
<aside class="sb" id="sb">
  <div class="sb-top">
    <div class="seal-ring">
      <span class="seal-spin"></span>
      <span class="seal-core"><img src="assets/logs.png" alt="BEC seal" onerror="this.style.display='none'"></span>
    </div>
    <div class="sb-brand">
      <strong>BEC PMO</strong>
      <em>Technician Portal</em>
    </div>
  </div>

  <div class="sb-user">
    <span class="uav"><?php echo e(initials($techName)); ?></span>
    <span class="un"><b><?php echo e($techName); ?></b><span>Maintenance Technician</span></span>
  </div>

  <nav class="sb-nav">
    <div class="nav-sec">Workspace</div>
    <a class="ni <?php echo $tab === 'my_tasks' ? 'on' : ''; ?>" href="?tab=my_tasks">
      <span class="ni-ic"><i class="fas fa-list-check"></i></span> My Tasks
      <span class="nbadge"><?php echo (int)$taskCount; ?></span>
    </a>
    <a class="ni <?php echo $tab === 'history' ? 'on' : ''; ?>" href="?tab=history">
      <span class="ni-ic"><i class="fas fa-clock-rotate-left"></i></span> Work History
      <span class="nbadge"><?php echo (int)$cHist; ?></span>
    </a>
  </nav>

  <div class="sb-foot">
    <a class="lout" href="technician/logout.php"><i class="fas fa-right-from-bracket"></i> Log Out</a>
  </div>
</aside>

<!-- ═══════════ MAIN ═══════════ -->
<main class="main">
  <div class="wrap">

    <!-- Mobile top bar -->
    <div class="topbar">
      <button class="tb-btn" type="button" data-sb-toggle aria-label="Open menu"><i class="fas fa-bars"></i></button>
      <div class="tb-title"><?php echo $tab === 'history' ? 'Work History' : 'My Tasks'; ?></div>
      <button class="tb-btn" type="button" data-modal-target="notifModal" aria-label="Notifications">
        <i class="fas fa-bell"></i>
        <?php if ($unreadCount > 0): ?><span class="tb-dot"><?php echo $unreadCount > 9 ? '9+' : (int)$unreadCount; ?></span><?php endif; ?>
      </button>
    </div>

    <?php if ($flash['message'] !== ''): ?>
    <div class="flash <?php echo $flash['type'] === 'ok' ? 'ok' : 'err'; ?>">
      <i class="fas <?php echo $flash['type'] === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
      <?php echo e($flash['message']); ?>
    </div>
    <?php endif; ?>

    <!-- Page head -->
    <header class="page-head">
      <span class="eyebrow"><span class="dot"></span> Property Management Office · Technician</span>
      <h1><?php echo $tab === 'history' ? 'Work History' : 'My Tasks'; ?><span class="qcount"><?php echo (int)$totalItems; ?></span></h1>
      <p><?php echo $tab === 'history'
          ? 'Your verified and closed repair records, kept for accountability and reference.'
          : 'Select a task below to open its repair workspace. Work through each stage and submit your completion report when done.'; ?></p>
    </header>

    <!-- Compact PWA actions: buttons only. Each chip shows only when it applies. -->
    <div class="pwa-bar" id="pwaBar" hidden>
      <button type="button" class="pwa-chip install" id="icInstall" hidden><i class="fas fa-download"></i>Install app</button>
      <button type="button" class="pwa-chip alerts" id="notifEnable" hidden><i class="fas fa-bell"></i>Enable alerts</button>
    </div>

    <!-- ═══ QUEUE ═══ -->
    <section class="card queue-shell" id="queue">
      <div class="queue-top">
        <span class="eyebrow"><span class="dot"></span> <?php echo $tab === 'history' ? 'Completed records' : 'Assigned to you'; ?></span>
        <form class="search" method="get">
          <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
          <input type="hidden" name="queue" value="<?php echo e($queueFilter); ?>">
          <i class="fas fa-magnifying-glass"></i>
          <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Search report, equipment, location…">
          <?php if ($search !== ''): ?><a class="clear" href="?tab=<?php echo e($tab); ?>&queue=<?php echo e($queueFilter); ?>" aria-label="Clear search"><i class="fas fa-xmark"></i></a><?php endif; ?>
        </form>
      </div>

      <div class="chips">
        <?php
          $chipSet = $tab === 'history'
            ? [['all','All','fa-layer-group'],['verified','Verified','fa-certificate'],['closed','Closed','fa-circle-check']]
            : [['all','All','fa-layer-group'],['urgent','Urgent','fa-bolt'],['assigned','New','fa-clipboard-list'],['in_progress','Active','fa-screwdriver-wrench'],['completed','Awaiting PMO','fa-user-check']];
          foreach ($chipSet as [$fk,$fl,$fi]):
        ?>
        <a class="chip <?php echo $queueFilter === $fk ? 'on' : ''; ?>" href="?tab=<?php echo e($tab); ?>&queue=<?php echo e($fk); ?><?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">
          <i class="fas <?php echo e($fi); ?>"></i> <?php echo e($fl); ?> <strong><?php echo (int)($queueCounts[$fk] ?? 0); ?></strong>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if (!$list): ?>
      <div class="empty">
        <i class="fas fa-clipboard-check"></i>
        <strong>Nothing in this view.</strong>
        <div><?php echo $search !== '' ? 'Try clearing your search or switching queues.' : 'No records in this queue right now.'; ?></div>
        <div class="empty-actions">
          <?php if ($search !== ''): ?><a class="empty-action" href="?tab=<?php echo e($tab); ?>"><i class="fas fa-xmark"></i> Clear Search</a><?php endif; ?>
          <a class="empty-action" href="?tab=my_tasks"><i class="fas fa-list-check"></i> My Tasks</a>
        </div>
      </div>
      <?php else: ?>
      <div class="qgrid">
        <?php foreach ($list as $row):
          $st = strtolower((string)($row['status'] ?? 'assigned'));
          $pr = strtolower((string)($row['priority'] ?? 'medium'));
          $due = $tab !== 'history' ? slaDueTs($row) : null;
          $started = strtotime((string)($row['started_at'] ?? ''));
          $isSel = ((string)$row['report_id'] === (string)$selectedId);
        ?>
        <button class="qcard pri-<?php echo e(ptone($pr)); ?> <?php echo $isSel ? 'active' : ''; ?>" type="button" data-ws-target="ws-<?php echo e((string)$row['report_id']); ?>">
          <span class="q-ic"><i class="fas <?php echo e(eqicon((string)($row['equipment_name'] ?? ''))); ?>"></i></span>
          <span class="q-body">
            <span class="q-top"><strong><?php echo e((string)($row['equipment_name'] ?? 'Equipment')); ?></strong><span class="q-id">#<?php echo e((string)$row['report_id']); ?></span></span>
            <span class="q-loc"><i class="fas fa-location-dot"></i> <?php echo e((string)($row['location'] ?? 'Unspecified')); ?></span>
            <span class="q-badges">
              <span class="badge <?php echo e(stone($st)); ?>"><i class="fas <?php echo e(sicon($st)); ?>"></i><?php echo e(slabel($st)); ?></span>
              <span class="badge <?php echo e(ptone($pr)); ?>"><i class="fas <?php echo e(picon($pr)); ?>"></i><?php echo e(ucfirst($pr)); ?></span>
              <?php if ($due !== null): ?><span class="badge sla sla-chip" data-due="<?php echo $due; ?>"><i class="fas fa-gauge-high"></i> —</span><?php endif; ?>
              <?php if ($started && in_array($st, ['in_progress','waiting_for_materials','for_replacement'], true)): ?><span class="badge timer rep-timer" data-started="<?php echo $started; ?>"><i class="fas fa-stopwatch"></i> —</span><?php endif; ?>
            </span>
          </span>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- ═══ REPAIR WORKSPACE (scrolls into view when a task is selected) ═══ -->
    <div class="ws-zone" id="wsZone">
      <?php foreach ($list as $row):
        $st = strtolower((string)($row['status'] ?? 'assigned'));
        $rid_e = e((string)$row['report_id']);
        $isSel = ((string)$row['report_id'] === (string)$selectedId);
        $due = $tab !== 'history' ? slaDueTs($row) : null;
        $started = strtotime((string)($row['started_at'] ?? ''));
        $photos = taskPhotos($row);
        $videos = taskVideos($row);
        $curStep = sstep($st);
      ?>
      <article class="ws-panel <?php echo $isSel ? 'active' : ''; ?>" id="ws-<?php echo $rid_e; ?>">
        <div class="ws-card">
          <div class="ws-head">
            <button class="ws-back" type="button" data-ws-back aria-label="Back to task list"><i class="fas fa-arrow-left"></i></button>
            <div class="ws-head-copy">
              <small>Repair Workspace · Report <?php echo $rid_e; ?></small>
              <h2 class="ws-title"><?php echo e((string)($row['equipment_name'] ?? 'Equipment')); ?></h2>
            </div>
            <div class="ws-head-tags">
              <?php if ($started && in_array($st, ['in_progress','waiting_for_materials','for_replacement'], true)): ?><span class="badge timer rep-timer" data-started="<?php echo $started; ?>"><i class="fas fa-stopwatch"></i> —</span><?php endif; ?>
              <?php if ($due !== null): ?><span class="badge sla sla-chip" data-due="<?php echo $due; ?>"><i class="fas fa-gauge-high"></i> —</span><?php endif; ?>
            </div>
          </div>

          <div class="steps">
            <?php foreach ([[1,'Received','fa-hand'],[2,'In Progress','fa-screwdriver-wrench'],[3,'Materials','fa-hourglass-half'],[4,'Completed','fa-user-check'],[5,'Verified','fa-certificate']] as [$si,$sl,$sic2]):
              $cls = $si < $curStep ? 'done' : ($si === $curStep ? 'now' : ''); ?>
            <div class="step <?php echo $cls; ?>">
              <div class="nd"><i class="fas <?php echo $si < $curStep ? 'fa-check' : $sic2; ?>"></i></div>
              <div class="lb"><?php echo $sl; ?></div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="ws-body">
            <div class="sec">
              <div class="sec-h"><small>At a glance</small><h3>Task Overview</h3></div>
              <div class="q-badges" style="margin-bottom:12px">
                <span class="badge <?php echo e(stone($st)); ?>"><i class="fas <?php echo e(sicon($st)); ?>"></i><?php echo e(slabel($st)); ?></span>
                <span class="badge <?php echo e(ptone((string)($row['priority'] ?? 'medium'))); ?>"><i class="fas <?php echo e(picon((string)($row['priority'] ?? 'medium'))); ?>"></i><?php echo e(ucfirst((string)($row['priority'] ?? 'medium'))); ?> priority</span>
              </div>
              <div class="facts">
                <div><small>Asset Tag</small><strong><?php echo e((string)($row['asset_tag'] ?? 'Not specified')); ?></strong></div>
                <div><small>Location</small><strong><?php echo e((string)($row['location'] ?? 'Unspecified')); ?></strong></div>
                <div><small>Category</small><strong><?php echo e((string)($row['category_name'] ?? 'Not specified')); ?></strong></div>
                <div><small>Reported</small><strong><?php echo e(fdt((string)($row['report_date'] ?? ''))); ?></strong></div>
              </div>
            </div>

            <div class="sec">
              <div class="sec-h"><small>Issue summary</small><h3>Issue Description</h3></div>
              <div class="copy"><?php echo nl2br(e((string)($row['issue_description'] ?? 'No issue description recorded.'))); ?></div>
            </div>

            <?php if (trim((string)($row['handler_instructions'] ?? '')) !== ''): ?>
            <div class="sec">
              <div class="sec-h"><small>PMO guidance</small><h3>Assignment Instructions</h3></div>
              <div class="copy"><?php echo nl2br(e((string)$row['handler_instructions'])); ?></div>
            </div>
            <?php endif; ?>

            <?php if (trim((string)($row['technician_notes'] ?? '')) !== ''): ?>
            <div class="sec">
              <div class="sec-h"><small>Recorded work</small><h3>Latest Technician Notes</h3></div>
              <div class="copy"><?php echo nl2br(e((string)$row['technician_notes'])); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($photos): ?>
            <div class="sec">
              <div class="sec-h"><small>Evidence</small><h3>Photo Evidence</h3></div>
              <div class="photos">
                <?php foreach ($photos as $photo): ?><img src="<?php echo e($photo); ?>" alt="Defect photo — tap to enlarge" loading="lazy" tabindex="0" role="button"><?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($videos)): ?>
            <div class="sec">
              <div class="sec-h"><small>Evidence</small><h3>Video Evidence</h3></div>
              <div class="vids">
                <?php foreach ($videos as $vid): ?><video src="<?php echo e($vid); ?>" controls preload="metadata" playsinline></video><?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'my_tasks'): ?>
            <div class="sec sec-form">
              <div class="sec-h"><small>Update case</small><h3>Repair Progress</h3></div>
              <form class="form" method="post">
                <input type="hidden" name="report_id" value="<?php echo $rid_e; ?>">
                <label for="notes_<?php echo $rid_e; ?>">Progress note</label>
                <textarea id="notes_<?php echo $rid_e; ?>" name="technician_notes" placeholder="Record inspection findings, progress, or the reason for waiting / replacement."><?php echo e((string)($row['technician_notes'] ?? '')); ?></textarea>
                <div class="actions">
                  <?php if ($st === 'assigned'): ?><button class="b1" type="submit" name="action" value="accept"><i class="fas fa-hand"></i> Receive Task</button><?php endif; ?>
                  <?php if ($st === 'accepted'): ?><button class="b1" type="submit" name="action" value="start"><i class="fas fa-play"></i> Start Repair</button><?php endif; ?>
                  <?php if (in_array($st, ['assigned','accepted','in_progress','waiting_for_materials','for_replacement','completed'], true)): ?><button class="b2" type="submit" name="action" value="save"><i class="fas fa-floppy-disk"></i> Save Note</button><?php endif; ?>
                  <?php if (in_array($st, ['accepted','in_progress'], true)): ?><button class="b3" type="submit" name="action" value="waiting"><i class="fas fa-hourglass-half"></i> Waiting for Materials</button><?php endif; ?>
                  <?php if ($st === 'waiting_for_materials'): ?><button class="b1" type="submit" name="action" value="resume_materials"><i class="fas fa-box-open"></i> Materials Received — Resume</button><?php endif; ?>
                  <?php if (in_array($st, ['accepted','in_progress','waiting_for_materials'], true)): ?><button class="b3" type="submit" name="action" value="replace"><i class="fas fa-rotate"></i> Recommend Replacement</button><?php endif; ?>
                  <?php if ($st === 'for_replacement'): ?><button class="b2" type="submit" name="action" value="resume"><i class="fas fa-play"></i> Resume Repair</button><?php endif; ?>
                </div>
              </form>
            </div>

            <?php if (in_array($st, ['in_progress','waiting_for_materials','for_replacement'], true)): ?>
            <div class="sec sec-form">
              <div class="sec-h"><small>Finish the job</small><h3>Completion Report</h3></div>
              <form class="form tech-ajax" method="post" action="technician_complete_task.php" enctype="multipart/form-data" data-reload="1">
                <input type="hidden" name="report_id" value="<?php echo $rid_e; ?>">
                <input type="hidden" name="action" value="complete">
                <div class="fs"><span class="fs-num">1</span><span class="fs-tx"><strong><?php echo $techIsItso ? 'Timing' : 'Timing &amp; Cost'; ?></strong><span>Pre-filled from when you pressed Start — adjust only if needed</span></span></div>
                <div class="fgrid">
                  <div><label>Date started</label><input type="datetime-local" name="date_started" value="<?php echo $started ? date('Y-m-d\TH:i', $started) : ''; ?>"></div>
                  <div><label>Repair duration</label><input type="text" name="repair_duration" placeholder="Leave blank — computed automatically"></div>
                  <?php if (!$techIsItso): ?>
                  <div><label>Estimated cost — repair &amp; maintenance (₱)</label><input type="number" step="0.01" min="0" inputmode="decimal" name="estimated_cost" placeholder="0.00"></div>
                  <div><label>Actual cost — tallied by you (₱)</label><input type="number" step="0.01" min="0" inputmode="decimal" name="repair_cost" placeholder="0.00"></div>
                  <?php endif; ?>
                </div>
                <div class="fs"><span class="fs-num">2</span><span class="fs-tx"><strong>Diagnosis &amp; Work Done</strong><span>Only these two are required — the rest is optional detail</span></span></div>
                <label>Diagnosis <em class="req">*</em></label>
                <textarea name="diagnosis" placeholder="What was found to be wrong?" data-req="Diagnosis"></textarea>
                <label>Actions performed</label>
                <textarea name="actions_performed" placeholder="Optional — specific steps you took."></textarea>
                <label>Repair procedures</label>
                <textarea name="repair_procedures" placeholder="Optional — procedures followed, for the maintenance record."></textarea>
                <label>Repair summary <em class="req">*</em></label>
                <textarea name="work_performed" placeholder="Overall summary of the repair." data-req="Repair summary"></textarea>
                <div class="fs"><span class="fs-num">3</span><span class="fs-tx"><strong>Parts, Tools &amp; Materials</strong><span>Add each item one by one — press Enter or “+” after each</span></span></div>
                <div class="fgrid">
                  <div class="chipfield" data-chipfield>
                    <label>Parts replaced</label>
                    <input type="hidden" name="parts_replaced" value="">
                    <div class="chip-entry">
                      <input type="text" class="chip-in" placeholder="e.g. capacitor">
                      <button type="button" class="chip-add" aria-label="Add item"><i class="fas fa-plus"></i></button>
                    </div>
                    <div class="chip-list"></div>
                  </div>
                  <div class="chipfield" data-chipfield>
                    <label>Tools &amp; materials used</label>
                    <input type="hidden" name="materials_used" value="">
                    <div class="chip-entry">
                      <input type="text" class="chip-in" placeholder="e.g. multimeter, thermal paste">
                      <button type="button" class="chip-add" aria-label="Add item"><i class="fas fa-plus"></i></button>
                    </div>
                    <div class="chip-list"></div>
                  </div>
                </div>
                <div class="fs"><span class="fs-num">4</span><span class="fs-tx"><strong>Photo Documentation</strong><span>Optional — before, during &amp; after evidence of the repair</span></span></div>
                <div class="photo-grid">
                  <div class="photo-field">
                    <label class="photo-drop">
                      <input type="file" class="photo-input" name="before_photos[]" accept="image/*" multiple>
                      <i class="fas fa-camera"></i>
                      <span class="photo-drop-label">Before photos</span>
                      <span class="photo-hint">Tap to capture or choose</span>
                    </label>
                    <div class="photo-preview"></div>
                  </div>
                  <div class="photo-field">
                    <label class="photo-drop">
                      <input type="file" class="photo-input" name="during_photos[]" accept="image/*" multiple>
                      <i class="fas fa-camera"></i>
                      <span class="photo-drop-label">During photos</span>
                      <span class="photo-hint">Tap to capture or choose</span>
                    </label>
                    <div class="photo-preview"></div>
                  </div>
                  <div class="photo-field">
                    <label class="photo-drop">
                      <input type="file" class="photo-input" name="after_photos[]" accept="image/*" multiple>
                      <i class="fas fa-camera"></i>
                      <span class="photo-drop-label">After photos</span>
                      <span class="photo-hint">Tap to capture or choose</span>
                    </label>
                    <div class="photo-preview"></div>
                  </div>
                </div>
                <div class="actions">
                  <button class="b4" type="submit"><i class="fas fa-clipboard-check"></i> Submit Completion Report</button>
                </div>
              </form>
            </div>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>

      <!-- ═══ CONTEXT (per selected task) ═══ -->
      <?php foreach ($list as $row):
        $st = strtolower((string)($row['status'] ?? 'assigned'));
        $isSel = ((string)$row['report_id'] === (string)$selectedId);
        $histRows = $maintByEquip[trim((string)($row['equipment_id'] ?? ''))] ?? [];
      ?>
      <div class="ctx <?php echo $isSel ? 'active' : ''; ?>" id="rail-<?php echo e((string)$row['report_id']); ?>">
        <section class="card">
          <div class="sec-h"><small>Workflow</small><h3>Maintenance Flow</h3></div>
          <div class="tl">
            <?php foreach (workflowSteps($st) as $stepRow): ?>
            <div class="tl-step">
              <span class="tl-dot <?php echo $stepRow['done'] ? 'done' : ($stepRow['active'] ? 'act' : ''); ?>"><?php echo $stepRow['done'] ? '✓' : ($stepRow['active'] ? '!' : '•'); ?></span>
              <div><strong><?php echo e($stepRow['label']); ?></strong><p><?php echo e($stepRow['desc']); ?></p></div>
            </div>
            <?php endforeach; ?>
          </div>
        </section>
        <section class="card">
          <div class="sec-h"><small>Asset history</small><h3>Previous Repairs</h3></div>
          <?php if (!$histRows): ?>
          <div class="empty" style="padding:22px 12px"><i class="fas fa-clock-rotate-left"></i><div>No earlier maintenance records for this equipment.</div></div>
          <?php else: ?>
          <div class="hist">
            <?php foreach (array_slice($histRows, 0, 6) as $h): ?>
            <div class="hrow">
              <span class="hic"><i class="fas fa-screwdriver-wrench"></i></span>
              <div>
                <strong><?php echo e(ucwords(str_replace('_', ' ', (string)($h['maintenance_type'] ?? 'Maintenance')))); ?></strong>
                <span class="hw"><?php echo e(fdate((string)($h['maintenance_date'] ?? ''))); ?><?php if (trim((string)($h['cost'] ?? '')) !== '' && (float)$h['cost'] > 0): ?> · ₱<?php echo e(number_format((float)$h['cost'], 2)); ?><?php endif; ?></span>
                <?php if (trim((string)($h['work_description'] ?? '')) !== ''): ?><span class="hd"><?php echo e((string)$h['work_description']); ?></span><?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>
      </div>
      <?php endforeach; ?>
    </div><!-- /ws-zone -->

  </div>
</main>

<!-- ═══════════ NOTIFICATION MODAL ═══════════ -->
<div class="modal" id="notifModal" aria-hidden="true">
  <div class="modal-dialog" role="dialog" aria-label="Notifications">
    <div class="m-top">
      <div><small>Technician Portal</small><h2>Notifications</h2></div>
      <button class="m-x" type="button" data-modal-close aria-label="Close"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="m-body">
      <div class="m-tools">
        <span><?php echo $unreadCount > 0 ? ((int)$unreadCount . ' unread') : 'All caught up'; ?></span>
        <?php if ($unreadCount > 0): ?>
        <form method="post" style="margin:0"><input type="hidden" name="action" value="mark_all_read"><button type="submit"><i class="fas fa-check-double"></i> Mark all read</button></form>
        <?php endif; ?>
      </div>
      <?php $anyNotif = false; foreach ($notifGroups as $g) { if ($g) { $anyNotif = true; break; } } ?>
      <?php if (!$anyNotif): ?>
      <div class="empty"><i class="fas fa-bell-slash"></i><strong>No notifications</strong><div>New assignments and reminders will appear here.</div></div>
      <?php else: ?>
        <?php foreach ($notifGroups as $groupLabel => $items): if (!$items) continue; ?>
        <div class="ngroup"><?php echo e($groupLabel); ?></div>
        <?php foreach ($items as $n): $unread = !notifIsRead($n['is_read'] ?? false); ?>
        <div class="nitem <?php echo $unread ? 'unread' : ''; ?>">
          <span class="nic"><i class="fas <?php echo e(notifIcon((string)($n['type'] ?? ''))); ?>"></i></span>
          <div style="min-width:0;flex:1">
            <div class="nmsg"><?php echo e((string)($n['message'] ?? '')); ?></div>
            <div class="nmeta">
              <span><i class="fas fa-clock"></i> <?php echo e(fdt((string)($n['created_date'] ?? ''))); ?></span>
              <?php if (trim((string)($n['related_id'] ?? '')) !== ''): ?><a href="?tab=my_tasks&report=<?php echo urlencode((string)$n['related_id']); ?>"><i class="fas fa-up-right-from-square"></i> Open task</a><?php endif; ?>
            </div>
          </div>
          <?php if ($unread): ?>
          <form method="post" class="nread"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notification_id" value="<?php echo e((string)($n['notification_id'] ?? '')); ?>"><button type="submit" aria-label="Mark as read" title="Mark as read"><i class="fas fa-check"></i></button></form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════ CONTEXTUAL ACTION LOADER ═══════════ -->
<div class="axl" id="axl" role="status" aria-live="polite">
  <div class="axl-box">
    <div class="axl-stage">
      <div class="axl-ring"></div>
      <div class="axl-core"><i class="fas fa-gear" id="axlIcon"></i></div>
    </div>
    <div>
      <div class="axl-label" id="axlLabel">Working…</div>
      <div class="axl-sub" id="axlSub">Please wait</div>
    </div>
  </div>
</div>

<!-- ═══════════ MOBILE BOTTOM NAV ═══════════ -->
<nav class="bnav" aria-label="Primary">
  <a class="bn <?php echo $tab === 'my_tasks' ? 'on' : ''; ?>" href="?tab=my_tasks"><i class="fas fa-list-check"></i><span>Tasks</span><?php if ($taskCount > 0): ?><em class="tb-dot" style="font-style:normal"><?php echo $taskCount > 9 ? '9+' : (int)$taskCount; ?></em><?php endif; ?></a>
  <button class="bn" type="button" data-modal-target="notifModal"><i class="fas fa-bell"></i><span>Alerts</span><?php if ($unreadCount > 0): ?><em class="tb-dot" style="font-style:normal"><?php echo $unreadCount > 9 ? '9+' : (int)$unreadCount; ?></em><?php endif; ?></button>
  <a class="bn <?php echo $tab === 'history' ? 'on' : ''; ?>" href="?tab=history"><i class="fas fa-clock-rotate-left"></i><span>History</span></a>
</nav>

<div class="toasts" id="toasts" aria-live="polite"></div>
<script>
/* ══════════ Technician Portal behaviour (rebuilt) ══════════ */
const body = document.body;

/* Branded toast (replaces browser alert popups) */
function techToast(type, msg) {
  const host = document.getElementById('toasts');
  if (!host) { alert(msg); return; }
  const t = document.createElement('div');
  t.className = 'toast ' + (type === 'ok' ? 'ok' : 'err');
  const ic = document.createElement('i');
  ic.className = 'fas ' + (type === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation');
  const tx = document.createElement('div'); tx.textContent = msg;
  const x = document.createElement('button'); x.className = 'tx-close'; x.innerHTML = '<i class="fas fa-xmark"></i>';
  x.addEventListener('click', function () { t.remove(); });
  t.appendChild(ic); t.appendChild(tx); t.appendChild(x);
  host.appendChild(t);
  setTimeout(function () { t.remove(); }, 6500);
}

/* Sidebar (mobile drawer) */
document.querySelectorAll('[data-sb-toggle]').forEach(function (b) {
  b.addEventListener('click', function () { body.classList.toggle('sb-open'); });
});
document.querySelectorAll('[data-sb-close]').forEach(function (b) {
  b.addEventListener('click', function () { body.classList.remove('sb-open'); });
});
window.addEventListener('resize', function () { if (window.innerWidth > 960) body.classList.remove('sb-open'); });

/* Task selection → reveal its repair workspace below and scroll to it */
const wsPanels = document.querySelectorAll('.ws-panel');
const qcards   = document.querySelectorAll('.qcard[data-ws-target]');
const ctxRails = document.querySelectorAll('.ctx');
function selectWorkspace(id, scroll) {
  let found = false;
  wsPanels.forEach(function (p) { const on = p.id === id; p.classList.toggle('active', on); if (on) found = true; });
  qcards.forEach(function (c) { c.classList.toggle('active', c.getAttribute('data-ws-target') === id); });
  const railId = id.replace(/^ws-/, 'rail-');
  ctxRails.forEach(function (r) { r.classList.toggle('active', r.id === railId); });
  if (found && scroll) {
    const z = document.getElementById('wsZone');
    if (z) z.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}
qcards.forEach(function (card) {
  card.addEventListener('click', function () { selectWorkspace(card.getAttribute('data-ws-target'), true); });
});
document.querySelectorAll('[data-ws-back]').forEach(function (b) {
  b.addEventListener('click', function () {
    const q = document.getElementById('queue');
    if (q) q.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});
/* Deep link (?report=...) — scroll to the preselected panel */
(function () {
  const pre = document.querySelector('.ws-panel.active');
  if (pre) setTimeout(function () { pre.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 250);
})();

/* Notification modal */
function closeAnyModal(m) { m.classList.remove('open'); m.setAttribute('aria-hidden', 'true'); body.classList.remove('modal-open'); }
document.querySelectorAll('[data-modal-target]').forEach(function (t) {
  t.addEventListener('click', function () {
    const m = document.getElementById(t.getAttribute('data-modal-target'));
    if (!m) return;
    m.classList.add('open'); m.setAttribute('aria-hidden', 'false'); body.classList.add('modal-open');
  });
});
document.querySelectorAll('[data-modal-close]').forEach(function (b) {
  b.addEventListener('click', function () { const m = b.closest('.modal'); if (m) closeAnyModal(m); });
});
document.querySelectorAll('.modal').forEach(function (m) {
  m.addEventListener('click', function (e) { if (e.target === m) closeAnyModal(m); });
});
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') return;
  const open = document.querySelector('.modal.open');
  if (open) { closeAnyModal(open); return; }
  body.classList.remove('sb-open');
});
/* Re-open notifications after a mark-as-read round trip */
if (new URLSearchParams(location.search).get('notif') === '1') {
  const nm = document.getElementById('notifModal');
  if (nm) { nm.classList.add('open'); nm.setAttribute('aria-hidden', 'false'); body.classList.add('modal-open'); }
}

/* Live repair timer + SLA countdown */
function fmtDur(sec) {
  sec = Math.max(0, Math.floor(sec));
  const d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
  if (d > 0) return d + 'd ' + h + 'h';
  if (h > 0) return h + 'h ' + m + 'm';
  return m + 'm';
}
function tickTimers() {
  const now = Date.now() / 1000;
  document.querySelectorAll('.rep-timer').forEach(function (el) {
    const s = parseInt(el.getAttribute('data-started'), 10);
    if (!s) return;
    el.innerHTML = '<i class="fas fa-stopwatch"></i> ' + fmtDur(now - s);
  });
  document.querySelectorAll('.sla-chip').forEach(function (el) {
    const due = parseInt(el.getAttribute('data-due'), 10);
    if (!due) return;
    const diff = due - now;
    if (diff <= 0) {
      el.classList.add('overdue');
      el.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Overdue ' + fmtDur(-diff);
    } else {
      el.classList.toggle('soon', diff < 86400);
      el.innerHTML = '<i class="fas fa-gauge-high"></i> Due in ' + fmtDur(diff);
    }
  });
}
tickTimers();
setInterval(tickTimers, 30000);

/* ── Contextual loading — every action shows ITS OWN animation ── */
const AXL_KINDS = {
  accept:           { cls: 'ax-receive',  icon: 'fa-hand',             label: 'Receiving task…',              sub: 'Confirming the assignment' },
  start:            { cls: 'ax-start',    icon: 'fa-screwdriver-wrench', label: 'Starting repair…',           sub: 'Your repair timer begins' },
  save:             { cls: 'ax-save',     icon: 'fa-floppy-disk',      label: 'Saving your note…',            sub: 'Recording progress' },
  waiting:          { cls: 'ax-wait',     icon: 'fa-hourglass-half',   label: 'Marking as waiting…',          sub: 'The PMO will see the hold' },
  resume_materials: { cls: 'ax-resume',   icon: 'fa-box-open',         label: 'Materials received…',          sub: 'Resuming the repair' },
  resume:           { cls: 'ax-resume',   icon: 'fa-play',             label: 'Resuming repair…',             sub: 'Back in progress' },
  replace:          { cls: 'ax-replace',  icon: 'fa-rotate',           label: 'Recommending replacement…',    sub: 'Forwarding to the PMO' },
  complete:         { cls: 'ax-complete', icon: 'fa-clipboard-check',  label: 'Submitting completion report…', sub: 'Uploading photos & details' },
  mark_read:        { cls: 'ax-notif',    icon: 'fa-bell',             label: 'Updating notifications…',      sub: 'Marking as read' },
  mark_all_read:    { cls: 'ax-notif',    icon: 'fa-bell',             label: 'Updating notifications…',      sub: 'Marking everything read' },
  default:          { cls: 'ax-replace',  icon: 'fa-gear',             label: 'Working…',                     sub: 'Please wait' }
};
function actionLoader(show, kind) {
  const l = document.getElementById('axl');
  if (!l) return;
  if (show) {
    const k = AXL_KINDS[kind] || AXL_KINDS.default;
    l.className = 'axl show ' + k.cls;
    document.getElementById('axlIcon').className = 'fas ' + k.icon;
    document.getElementById('axlLabel').textContent = k.label;
    document.getElementById('axlSub').textContent = k.sub;
  } else {
    l.className = 'axl';
  }
}
/* ── Inline red warnings for missing required info ── */
function fieldError(el, msg) {
  el.classList.add('f-err');
  let m = el.nextElementSibling;
  if (!(m && m.classList && m.classList.contains('f-msg'))) {
    m = document.createElement('div'); m.className = 'f-msg';
    el.insertAdjacentElement('afterend', m);
  }
  m.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + msg;
}
function fieldOk(el) {
  el.classList.remove('f-err');
  const m = el.nextElementSibling;
  if (m && m.classList && m.classList.contains('f-msg')) m.remove();
}
function validateForm(f, submitterVal) {
  const bad = [];
  f.querySelectorAll('[data-req]').forEach(function (el) {
    if (!el.value.trim()) { fieldError(el, (el.dataset.req || 'This field') + ' is required.'); bad.push(el); }
    else fieldOk(el);
  });
  /* waiting / replacement need a reason in the note */
  if (submitterVal === 'waiting' || submitterVal === 'replace') {
    const t = f.querySelector('textarea[name="technician_notes"]');
    if (t && !t.value.trim()) {
      fieldError(t, 'Please add a note explaining the ' + (submitterVal === 'waiting' ? 'material hold.' : 'replacement recommendation.'));
      bad.push(t);
    }
  }
  if (bad.length) {
    var seen = {}, names = [];
    bad.forEach(function (el) { var n = el.dataset.req || 'A required field'; if (!seen[n]) { seen[n] = 1; names.push(n); } });
    if (window.showTechErr) {
      window.showTechErr(names, bad[0]);
    } else {
      techToast('err', 'Still needed: ' + names.slice(0, 3).join(', ') + '.');
      bad[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }
  return bad.length === 0;
}
/* red state clears live as the technician types */
document.addEventListener('input', function (e) {
  const el = e.target;
  if (el.classList && el.classList.contains('f-err') && el.value.trim()) fieldOk(el);
});

/* Normal POST forms (repair progress, notifications): contextual loader + locked button */
document.querySelectorAll('form:not(.tech-ajax):not(.search)').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    const kind = (e.submitter && e.submitter.value) || (f.querySelector('input[name=action]') || {}).value || 'default';
    if (!validateForm(f, kind)) { e.preventDefault(); return; }
    // The buttons carry name="action" (Receive/Start/Save/…). Disabling them below
    // would drop the clicked button from the POST (disabled controls aren't submitted),
    // so $_POST['action'] would arrive empty. Preserve it as a hidden input first.
    if (e.submitter && e.submitter.name) {
      const keep = document.createElement('input');
      keep.type = 'hidden'; keep.name = e.submitter.name; keep.value = e.submitter.value;
      f.appendChild(keep);
    }
    actionLoader(true, kind);
    f.querySelectorAll('button[type=submit]').forEach(function (b) {
      b.disabled = true;
      b.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Working…';
    });
  });
});

/* AJAX submit for the completion report (keeps file uploads working) */
document.querySelectorAll('form.tech-ajax').forEach(function (f) {
  f.addEventListener('submit', async function (e) {
    e.preventDefault();

    /* Missing required info → red field warnings, no submit */
    if (!validateForm(f)) return;

    /* Pre-flight photo size check — catch oversized uploads BEFORE sending
       (per-file 10 MB, ~38 MB total: matches the server's limits). */
    let totalBytes = 0; const tooBig = [];
    f.querySelectorAll('input[type=file]').forEach(function (inp) {
      Array.from(inp.files || []).forEach(function (file) {
        totalBytes += file.size;
        if (file.size > 10 * 1024 * 1024) tooBig.push(file.name + ' (' + (file.size / 1048576).toFixed(1) + ' MB)');
      });
    });
    if (tooBig.length) {
      techToast('err', 'These photos are over the 10 MB per-photo limit:\n• ' + tooBig.join('\n• ') + '\nPlease retake or resize them and try again.');
      return;
    }
    if (totalBytes > 38 * 1024 * 1024) {
      techToast('err', 'Your photos total ' + (totalBytes / 1048576).toFixed(1) + ' MB — over the 40 MB upload limit. Please use fewer or smaller photos.');
      return;
    }

    const btn = f.querySelector('button[type=submit]');
    const orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Submitting…'; }
    const actionUrl = f.getAttribute('action') || '';
    actionLoader(true, 'complete');
    try {
      const res = await fetch(actionUrl, { method: 'POST', body: new FormData(f) });
      const data = await res.json().catch(function () {
        return { success: false, message: 'The server sent an unreadable response (HTTP ' + res.status + '). If you attached photos, try smaller ones; otherwise contact the PMO.' };
      });
      if (data.success) {
        if (f.dataset.reload) { window.location.reload(); return; }
      } else {
        techToast('err', data.message || 'Action failed. Please check the form and try again.');
      }
    } catch (err) {
      techToast('err', 'Connection error. Please try again.');
    }
    actionLoader(false);
    if (btn) { btn.disabled = false; btn.innerHTML = orig; }
  });
});

/* ── Chip fields: unlimited parts / tools / materials, joined into one value ── */
document.querySelectorAll('[data-chipfield]').forEach(function (cf) {
  const hidden = cf.querySelector('input[type=hidden]');
  const entry = cf.querySelector('.chip-in');
  const list = cf.querySelector('.chip-list');
  const items = [];
  function sync() { hidden.value = items.join(', '); }
  function render() {
    list.innerHTML = '';
    items.forEach(function (t, i) {
      const chip = document.createElement('span'); chip.className = 'chip-item';
      const label = document.createElement('span'); label.textContent = t;
      const x = document.createElement('button'); x.type = 'button'; x.innerHTML = '<i class="fas fa-xmark"></i>'; x.setAttribute('aria-label', 'Remove');
      x.addEventListener('click', function () { items.splice(i, 1); render(); sync(); });
      chip.appendChild(label); chip.appendChild(x); list.appendChild(chip);
    });
  }
  function add() {
    const v = entry.value.trim().replace(/,+$/, '');
    if (!v) return;
    items.push(v); entry.value = ''; render(); sync(); entry.focus();
  }
  cf.querySelector('.chip-add').addEventListener('click', add);
  entry.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(); }
  });
  /* absorb any leftover typed text on submit so nothing is lost */
  const form = cf.closest('form');
  if (form) form.addEventListener('submit', function () {
    const v = entry.value.trim();
    if (v) { items.push(v); entry.value = ''; sync(); }
  }, true);
});

/* Photo capture previews */
document.addEventListener('change', function (e) {
  const inp = e.target;
  if (!inp || !inp.classList || !inp.classList.contains('photo-input')) return;
  const field = inp.closest('.photo-field');
  const drop = inp.closest('.photo-drop');
  const prev = field ? field.querySelector('.photo-preview') : null;
  if (!prev) return;
  prev.innerHTML = '';
  const files = Array.from(inp.files || []);
  if (drop) drop.classList.toggle('has-files', files.length > 0);
  files.slice(0, 8).forEach(function (file) {
    if (!file.type || file.type.indexOf('image/') !== 0) return;
    const chip = document.createElement('span'); chip.className = 'pchip';
    const img = document.createElement('img'); img.alt = '';
    img.src = URL.createObjectURL(file);
    img.onload = function () { URL.revokeObjectURL(img.src); };
    chip.appendChild(img); prev.appendChild(chip);
  });
});
</script>
<script>
/* PWA: register the service worker (installable technician app) */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('sw.js').catch(function () {/* non-fatal */});
  });
}

/* PWA: in-page "Install app" prompt.
   The banner is ALWAYS shown (unless already installed or dismissed) and adapts its guidance
   so there is never a dead end: one-tap install when the browser offers it, share-sheet steps
   on iOS, address-bar/menu steps on desktop, and a clear "needs HTTPS" notice when the page is
   opened over an insecure LAN address (the usual reason install fails on a phone). */
(function () {
  var bar = document.getElementById('pwaBar');
  var btn = document.getElementById('icInstall');
  if (!bar || !btn) return;
  var deferred = null;
  var installed = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  var bipSupported = ('onbeforeinstallprompt' in window);
  var host = location.hostname;
  var isLocalhost = host === 'localhost' || host === '127.0.0.1' || host === '[::1]';
  var secure = (location.protocol === 'https:') || isLocalhost || window.isSecureContext === true;

  // Shared: show the bar whenever either chip is visible, hide it when both are gone.
  function syncBar() {
    var n = document.getElementById('notifEnable');
    bar.hidden = !((btn && !btn.hidden) || (n && !n.hidden));
  }
  window.pwaSyncBar = syncBar;

  function showInstall() { btn.hidden = installed; syncBar(); }
  function guide() {                                  // tapping when a one-tap prompt isn't available
    if (!window.techToast) return;
    if (!secure) return techToast('ok', 'To install on a phone, open the site through its secure https:// address.');
    if (isIOS) return techToast('ok', 'On iPhone/iPad: tap Share, then “Add to Home Screen.”');
    if (bipSupported) return techToast('ok', 'Use the install icon in your browser’s address bar, or menu → Install app.');
    return techToast('ok', 'Open this page in Chrome or Edge, then menu → Install app.');
  }

  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; showInstall(); });
  btn.addEventListener('click', async function () {
    if (deferred) {
      deferred.prompt();
      try { await deferred.userChoice; } catch (e) {}
      deferred = null; btn.hidden = true; syncBar();
      return;
    }
    guide();
  });
  window.addEventListener('appinstalled', function () { btn.hidden = true; syncBar(); if (window.techToast) techToast('ok', 'App installed — open it from your home screen.'); });
  showInstall();
})();

/* ── Web Push: opt in to task-assignment alerts (Enable alerts chip) ── */
(function () {
  var enable = document.getElementById('notifEnable');
  var VAPID = <?php echo json_encode($vapidPublicKey); ?>;
  if (!enable || !('serviceWorker' in navigator) || !('PushManager' in window) || !window.Notification || !VAPID) return;
  function sync() { if (window.pwaSyncBar) window.pwaSyncBar(); else { var b = document.getElementById('pwaBar'); if (b) b.hidden = false; } }
  function b64ToU8(s) {
    var pad = '='.repeat((4 - s.length % 4) % 4), b = (s + pad).replace(/-/g, '+').replace(/_/g, '/'), raw = atob(b), u = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) u[i] = raw.charCodeAt(i);
    return u;
  }
  async function subscribe() {
    try {
      var reg = await navigator.serviceWorker.ready;
      var sub = await reg.pushManager.getSubscription();
      if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToU8(VAPID) });
      await fetch('push_subscribe.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub) });
      enable.hidden = true; sync();
      if (window.techToast) techToast('ok', 'Task alerts enabled — you\'ll be notified of new assignments.');
    } catch (e) { if (window.techToast) techToast('err', 'Could not enable alerts: ' + e.message); }
  }
  enable.addEventListener('click', async function () {
    var perm = await Notification.requestPermission();
    if (perm === 'granted') subscribe();
    else if (window.techToast) techToast('err', 'Notifications are blocked — enable them in your browser settings.');
  });
  if (Notification.permission === 'granted') subscribe();       // already allowed → keep subscription fresh
  else if (Notification.permission === 'default') { enable.hidden = false; sync(); } // offer it
})();
</script>

<!-- Fullscreen photo viewer for evidence (tap to enlarge; swipe or arrows to move between photos) -->
<div class="lb-ov" id="lbOv" aria-hidden="true" role="dialog" aria-label="Photo viewer">
  <button class="lb-btn lb-close" id="lbClose" type="button" aria-label="Close">&times;</button>
  <button class="lb-btn lb-nav lb-prev" id="lbPrev" type="button" aria-label="Previous photo">&#8249;</button>
  <img class="lb-img" id="lbImg" alt="Evidence photo">
  <button class="lb-btn lb-nav lb-next" id="lbNext" type="button" aria-label="Next photo">&#8250;</button>
  <div class="lb-count" id="lbCount"></div>
</div>
<script>
(function () {
  var ov = document.getElementById('lbOv');
  if (!ov) return;
  var img = document.getElementById('lbImg'), prev = document.getElementById('lbPrev'),
      next = document.getElementById('lbNext'), closeB = document.getElementById('lbClose'),
      count = document.getElementById('lbCount');
  var set = [], idx = 0;
  function render() {
    img.src = set[idx] || '';
    var multi = set.length > 1;
    count.textContent = (idx + 1) + ' / ' + set.length;
    prev.hidden = !multi; next.hidden = !multi; count.hidden = !multi;
  }
  function open(list, i) {
    set = list; idx = i; render();
    ov.classList.add('open'); ov.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
  function close() {
    ov.classList.remove('open'); ov.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = ''; img.src = '';
  }
  function go(d) { if (set.length < 2) return; idx = (idx + d + set.length) % set.length; render(); }

  document.addEventListener('click', function (e) {
    var t = e.target;
    var grid = (t.tagName === 'IMG') ? t.closest('.photos') : null;
    if (grid) {
      var imgs = Array.prototype.slice.call(grid.querySelectorAll('img'));
      open(imgs.map(function (x) { return x.getAttribute('src'); }), imgs.indexOf(t));
      return;
    }
    if (t === ov || t.closest('#lbClose')) close();
    else if (t.closest('#lbPrev')) go(-1);
    else if (t.closest('#lbNext')) go(1);
  });
  document.addEventListener('keydown', function (e) {
    if (ov.classList.contains('open')) {
      if (e.key === 'Escape') close();
      else if (e.key === 'ArrowLeft') go(-1);
      else if (e.key === 'ArrowRight') go(1);
      return;
    }
    var a = document.activeElement;
    if ((e.key === 'Enter' || e.key === ' ') && a && a.tagName === 'IMG' && a.closest('.photos')) { e.preventDefault(); a.click(); }
  });
  // swipe on touch devices
  var sx = 0, sy = 0;
  ov.addEventListener('touchstart', function (e) { if (e.touches.length === 1) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; } }, { passive: true });
  ov.addEventListener('touchend', function (e) {
    var c = e.changedTouches[0], dx = c.clientX - sx, dy = c.clientY - sy;
    if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) go(dx < 0 ? 1 : -1);
  }, { passive: true });
})();
</script>

<!-- Minimalist missing-fields popup for the completion form -->
<div class="te-modal" id="teErrModal" aria-hidden="true">
  <div class="te-box" role="dialog" aria-modal="true" aria-labelledby="teErrTitle">
    <div class="te-ic"><i class="fas fa-triangle-exclamation"></i></div>
    <h3 id="teErrTitle">Some details are missing</h3>
    <p id="teErrMsg">Please complete the following before submitting your report.</p>
    <ul class="te-list" id="teErrList"></ul>
    <button type="button" class="te-btn" id="teErrClose">Review the form</button>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('teErrModal');
  if (!modal) return;
  var first = null;
  window.showTechErr = function (items, firstEl) {
    first = firstEl || null;
    document.getElementById('teErrTitle').textContent = (items && items.length === 1) ? 'One more detail needed' : 'Some details are missing';
    var list = document.getElementById('teErrList');
    list.innerHTML = '';
    (items || []).forEach(function (t) {
      var li = document.createElement('li');
      var ic = document.createElement('i'); ic.className = 'fas fa-circle-exclamation';
      var sp = document.createElement('span'); sp.textContent = t;
      li.appendChild(ic); li.appendChild(sp); list.appendChild(li);
    });
    modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false');
  };
  function close() {
    modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true');
    if (first) { var b = first; first = null; b.scrollIntoView({ behavior: 'smooth', block: 'center' }); setTimeout(function () { try { b.focus({ preventScroll: true }); } catch (e) {} b.classList.remove('f-flash'); void b.offsetWidth; b.classList.add('f-flash'); }, 300); }
  }
  document.getElementById('teErrClose').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('show')) close(); });
})();
</script>
<?php require_once __DIR__ . '/includes/csrf_inject.php'; ?>
<?php require __DIR__ . '/includes/technician_assistant.php'; ?>
<?php require __DIR__ . '/includes/site_transitions.php'; ?>
</body>
</html>
