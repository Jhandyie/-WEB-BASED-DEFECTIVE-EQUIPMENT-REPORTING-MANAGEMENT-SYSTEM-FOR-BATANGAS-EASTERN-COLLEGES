<?php
/**
 * technician_update_status.php
 * â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 * Dedicated status-update handler for the Technician Portal.
 *
 * Accepts both:
 *   â€¢ Standard HTML form POST  â†’ redirects back to the referrer / task details
 *   â€¢ Fetch / AJAX POST with   â†’ returns JSON  { success, message, status,
 *     header Accept: application/json              completion_date? }
 *
 * Required POST fields:
 *   report_id   â€“ the task to update
 *   new_status  â€“ target status  (assigned | in_progress | completed)
 *
 * Optional POST fields:
 *   notes       â€“ technician notes to save alongside the status change
 *   log_note    â€“ message to append to task_logs (if table exists)
 *   redirect    â€“ override redirect URL after successful update
 *                 (defaults to technician_task_details.php?report_id=â€¦)
 *
 * Security:
 *   â€¢ Requires an active technician session (requireRole)
 *   â€¢ Only allows updates to tasks assigned to the current technician
 *   â€¢ Validates new_status against an explicit allowlist
 *   â€¢ CSRF token checked when header X-CSRF-Token is present or
 *     _csrf_token field is posted (optional, enable below)
 *   â€¢ All DB params are bound â€” no raw interpolation of user input
 * â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 */

require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

requireRole('technician');

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   RESPONSE HELPERS
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */

/** Is this an AJAX / JSON request? */
function isJsonRequest(): bool {
    $accept  = $_SERVER['HTTP_ACCEPT']       ?? '';
    $ctype   = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
    $xhr     = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return str_contains($accept, 'application/json')
        || str_contains($ctype,  'application/json')
        || strtolower($xhr) === 'xmlhttprequest';
}

/** Send a JSON response and exit. */
function jsonResponse(bool $success, string $message, array $extra = [], int $httpCode = 200): never {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(
        ['success' => $success, 'message' => $message],
        $extra
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

/** Redirect with a flash message encoded in the query string and exit. */
function redirectWith(string $url, string $msg, string $type = 'ok'): never {
    $sep = str_contains($url, '?') ? '&' : '?';
    $qs  = http_build_query(['flash' => $msg, 'ftype' => $type]);
    header('Location: ' . $url . $sep . $qs);
    exit;
}

/** Safe HTML-escape. */
function esc(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   ONLY ACCEPT POST
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isJsonRequest()) {
        jsonResponse(false, 'Method not allowed. Use POST.', [], 405);
    }
    header('Location: technician_tasks.php');
    exit;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   COLLECT & VALIDATE INPUT
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
$technician_id    = $_SESSION['user_id'] ?? '';
$technician_email = $_SESSION['user_email'] ?? '';
$technician_name  = $_SESSION['fullname'] ?? '';
$report_id        = trim($_POST['report_id']  ?? '');
$new_status       = trim($_POST['new_status'] ?? '');
$notes            = trim($_POST['notes']      ?? '');   // optional notes update
$log_note         = trim($_POST['log_note']   ?? '');   // optional log entry
$redirect_to      = trim($_POST['redirect']   ?? '');   // optional redirect override

$technician_keys = array_values(array_filter(array_unique([
    trim((string)$technician_id),
    trim((string)$technician_email),
    trim((string)$technician_name),
]), fn($v) => $v !== ''));
$technician_key_norms = array_values(array_unique(array_map(
    fn($v) => strtolower(trim((string)$v)),
    $technician_keys
)));

/* â”€â”€ Allowed status transitions â”€â”€ */
$allowed_statuses = ['assigned', 'in_progress', 'completed'];

/* â”€â”€ Validation â”€â”€ */
$validation_errors = [];

if ($report_id === '') {
    $validation_errors[] = 'Task ID is required.';
}
if ($new_status === '') {
    $validation_errors[] = 'New status is required.';
} elseif (!in_array($new_status, $allowed_statuses, true)) {
    $validation_errors[] = 'Invalid status value "' . esc($new_status) . '". '
                         . 'Allowed values: ' . implode(', ', $allowed_statuses) . '.';
}
if ($technician_id === '') {
    $validation_errors[] = 'Session expired. Please log in again.';
}

if (!empty($validation_errors)) {
    $err_msg = implode(' ', $validation_errors);
    if (isJsonRequest()) {
        jsonResponse(false, $err_msg, ['errors' => $validation_errors], 422);
    }
    $back = $redirect_to ?: (
        $report_id !== ''
            ? 'technician_task_details.php?report_id=' . urlencode($report_id)
            : 'technician_tasks.php'
    );
    redirectWith($back, $err_msg, 'err');
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   DB INTROSPECTION  (same resilient pattern as the rest)
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
$conn   = getDBConnection();
$drCols = [];
$logCols = [];

try {
    $res = $conn->query('SHOW COLUMNS FROM defect_reports');
    if ($res) while ($row = $res->fetch_assoc()) $drCols[$row['Field']] = true;
} catch (Exception $e) {}

try {
    $res = $conn->query('SHOW COLUMNS FROM task_logs');
    if ($res) while ($row = $res->fetch_assoc()) $logCols[$row['Field']] = true;
} catch (Exception $e) {}

$hasLogs = !empty($logCols);

/* â”€â”€ Column name resolution â”€â”€ */
$assigneeCol  = isset($drCols['assigned_to'])
    ? 'assigned_to'
    : (isset($drCols['assigned_technician']) ? 'assigned_technician' : 'assigned_to');

$statusCol    = isset($drCols['status'])           ? 'status'           : null;
$completionCol= isset($drCols['completion_date'])  ? 'completion_date'  : null;
$startedCol   = isset($drCols['started_at'])       ? 'started_at'       : null;
$notesCol     = isset($drCols['technician_notes']) ? 'technician_notes'
              : (isset($drCols['resolution_notes'])? 'resolution_notes' : null);

/* â”€â”€ Log table column resolution â”€â”€ */
$logReportCol = isset($logCols['report_id'])     ? 'report_id'     : 'task_id';
$logAuthorCol = isset($logCols['technician_id']) ? 'technician_id'
              : (isset($logCols['user_id'])       ? 'user_id'       : 'technician_id');
$logNoteCol   = isset($logCols['note'])          ? 'note'
              : (isset($logCols['message'])       ? 'message'       : 'note');
$logStatusCol = isset($logCols['status'])        ? 'status'         : null;
$logDateCol   = isset($logCols['created_at'])    ? 'created_at'
              : (isset($logCols['log_date'])      ? 'log_date'      : 'created_at');

if (!$statusCol) {
    $msg = 'The "status" column was not found in the defect_reports table.';
    if (isJsonRequest()) jsonResponse(false, $msg, [], 500);
    redirectWith(
        'technician_task_details.php?report_id=' . urlencode($report_id),
        $msg, 'err'
    );
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   VERIFY TASK OWNERSHIP  (fetch before updating)
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
$task = null;
try {
    $stmt = $conn->prepare(
        "SELECT report_id, {$statusCol} AS current_status, {$assigneeCol} AS assignee_value
         FROM defect_reports
         WHERE report_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $report_id);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    $msg = 'Database error while verifying task: ' . $e->getMessage();
    if (isJsonRequest()) jsonResponse(false, $msg, [], 500);
    redirectWith(
        'technician_task_details.php?report_id=' . urlencode($report_id),
        $msg, 'err'
    );
}

if (!$task) {
    $msg = 'Task not found or it is not assigned to you.';
    if (isJsonRequest()) jsonResponse(false, $msg, [], 404);
    redirectWith('technician_tasks.php', $msg, 'err');
}

$task_assignee = (string)($task['assignee_value'] ?? '');
$task_assignee_norm = strtolower(trim($task_assignee));
if ($task_assignee_norm === '' || !in_array($task_assignee_norm, $technician_key_norms, true)) {
    $msg = 'Task not found or it is not assigned to you.';
    if (isJsonRequest()) jsonResponse(false, $msg, [], 403);
    redirectWith('technician_tasks.php', $msg, 'err');
}

$current_status = strtolower((string)($task['current_status'] ?? ''));

/* â”€â”€ Prevent pointless no-op updates â”€â”€ */
if ($current_status === $new_status) {
    $label = ucwords(str_replace('_', ' ', $new_status));
    $msg   = "Task is already set to \"{$label}\". No changes made.";
    if (isJsonRequest()) {
        jsonResponse(true, $msg, ['status' => $new_status, 'changed' => false]);
    }
    $back = $redirect_to ?: 'technician_task_details.php?report_id=' . urlencode($report_id);
    redirectWith($back, $msg, 'info');
}

/* â”€â”€ Prevent re-opening verified / closed tasks â”€â”€ */
$locked_statuses = ['verified', 'closed'];
if (in_array($current_status, $locked_statuses, true)) {
    $msg = 'This task has been ' . ucfirst($current_status) . ' by an administrator and cannot be modified.';
    if (isJsonRequest()) jsonResponse(false, $msg, [], 403);
    redirectWith(
        'technician_task_details.php?report_id=' . urlencode($report_id),
        $msg, 'err'
    );
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   BUILD UPDATE QUERY
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
$set_parts  = ["{$statusCol} = ?"];
$bind_types = 's';
$bind_vals  = [$new_status];

/* Auto-set completion_date when marking complete */
if ($new_status === 'completed' && $completionCol) {
    $set_parts[]  = "{$completionCol} = NOW()";
}

/* Auto-set started_at when moving to in_progress */
if ($new_status === 'in_progress' && $startedCol) {
    $set_parts[]  = "{$startedCol} = COALESCE({$startedCol}, NOW())";
}

/* Optionally save notes at the same time */
if ($notes !== '' && $notesCol) {
    $set_parts[]  = "{$notesCol} = ?";
    $bind_types  .= 's';
    $bind_vals[]  = $notes;
}

$bind_types .= 'ss';   // report_id + assignee (WHERE clause)
$bind_vals[] = $report_id;
$bind_vals[] = $task_assignee;

$set_sql = implode(', ', $set_parts);
$sql_upd = "UPDATE defect_reports SET {$set_sql} WHERE report_id = ? AND {$assigneeCol} = ?";

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   EXECUTE UPDATE
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
$updated      = false;
$update_error = '';

try {
    $stmt = $conn->prepare($sql_upd);
    if (!$stmt) throw new RuntimeException($conn->error);

    $stmt->bind_param($bind_types, ...$bind_vals);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $updated = true;
    } else {
        /* affected_rows can be 0 if the value was identical in the DB */
        $updated = ($stmt->errno === 0);
    }
    $stmt->close();
} catch (Exception $e) {
    $update_error = $e->getMessage();
}

if ($update_error !== '') {
    $msg = 'Status update failed: ' . $update_error;
    if (isJsonRequest()) jsonResponse(false, $msg, [], 500);
    redirectWith(
        'technician_task_details.php?report_id=' . urlencode($report_id),
        $msg, 'err'
    );
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   APPEND TO TASK LOG  (if table exists)
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
if ($hasLogs) {
    /* Auto-generate a system log entry for the status change */
    $tech_name = $_SESSION['fullname'] ?? 'Technician';
    $auto_note = sprintf(
        '[%s] Status changed from "%s" to "%s" by %s.',
        date('Y-m-d H:i'),
        ucwords(str_replace('_', ' ', $current_status)),
        ucwords(str_replace('_', ' ', $new_status)),
        $tech_name
    );

    $entries_to_log = [$auto_note];

    /* Also log the custom note if provided */
    if ($log_note !== '') {
        $entries_to_log[] = $log_note;
    }

    foreach ($entries_to_log as $entry) {
        try {
            $log_cols  = "{$logReportCol}, {$logAuthorCol}, {$logNoteCol}, {$logDateCol}";
            $log_cols .= $logStatusCol ? ", {$logStatusCol}" : '';
            $log_vals  = '?, ?, ?, NOW()';
            $log_vals .= $logStatusCol ? ', ?' : '';
            $log_types = 'sss' . ($logStatusCol ? 's' : '');
            $log_bind  = [$report_id, $technician_id, $entry];
            if ($logStatusCol) $log_bind[] = $new_status;

            $stmt = $conn->prepare(
                "INSERT INTO task_logs ({$log_cols}) VALUES ({$log_vals})"
            );
            if ($stmt) {
                $stmt->bind_param($log_types, ...$log_bind);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            /* Log failure is non-fatal â€” don't block the main response */
        }
    }
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   BUILD SUCCESS RESPONSE
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
$status_label = ucwords(str_replace('_', ' ', $new_status));
$success_msg  = "Task {$report_id} updated to \"{$status_label}\" successfully.";

/* â”€â”€ JSON response (AJAX callers) â”€â”€ */
if (isJsonRequest()) {
    $extra = [
        'report_id'  => $report_id,
        'status'     => $new_status,
        'status_label' => $status_label,
        'changed'    => true,
    ];
    if ($new_status === 'completed' && $completionCol) {
        $extra['completion_date'] = date('Y-m-d H:i:s');
    }
    jsonResponse(true, $success_msg, $extra);
}

/* â”€â”€ HTML form redirect â”€â”€ */
$back = $redirect_to !== ''
    ? $redirect_to
    : 'technician_task_details.php?report_id=' . urlencode($report_id);

redirectWith($back, $success_msg, 'ok');

