<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

header('Content-Type: application/json');
// JSON endpoint: warnings/notices must never leak into the response body
// (a printed warning corrupts the JSON and the client sees "Unexpected server response").
ini_set('display_errors', '0');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

// Oversized upload: PHP drops the whole POST body when it exceeds post_max_size,
// leaving $_POST/$_FILES empty. Detect it and answer with a helpful message.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && empty($_POST) && empty($_FILES)) {
    echo json_encode(['success' => false, 'message' => 'Your photos are too large — the total upload exceeds the 40 MB server limit. Please use fewer or smaller photos and try again.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'complete') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

requireCsrf(true);

/**
 * Move an uploaded multi-file field into $dir and return the saved paths.
 */
function tcSavePhotoStage(string $field, string $dir): array {
    $saved = [];
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['tmp_name'])) {
        return $saved;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // Validate by ACTUAL image content; derive a safe extension. Never trust the
    // client MIME type or the original filename (prevents saving executable files).
    $allowedImg = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    $maxSize = 10 * 1024 * 1024; // 10MB
    foreach ($_FILES[$field]['tmp_name'] as $i => $tmp) {
        if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        if (!is_uploaded_file($tmp)) {
            continue;
        }
        $size = (int)($_FILES[$field]['size'][$i] ?? 0);
        if ($size <= 0 || $size > $maxSize) {
            continue;
        }
        $info = @getimagesize($tmp);
        if ($info === false || !isset($allowedImg[$info[2]])) {
            continue;
        }
        $name = uniqid('', true) . '.' . $allowedImg[$info[2]];
        $path = rtrim($dir, '/') . '/' . $name;
        if (move_uploaded_file($tmp, $path)) {
            @chmod($path, 0644);
            // Store a web-relative path (e.g. uploads/completed_work/before/xxx.jpg)
            $saved[] = ltrim(str_replace('\\', '/', str_replace(__DIR__, '', $path)), '/');
        }
    }
    return $saved;
}

$report_id      = trim((string)($_POST['report_id'] ?? ''));
$technician_id  = (string)$_SESSION['user_id'];

if ($report_id === '') {
    echo json_encode(['success' => false, 'message' => 'Missing report reference.']);
    exit();
}

// --- Repair action log + completion report fields (spec-aligned) ---
$diagnosis         = trim((string)($_POST['diagnosis'] ?? ''));
$actions_performed = trim((string)($_POST['actions_performed'] ?? ''));
$repair_procedures = trim((string)($_POST['repair_procedures'] ?? ''));
$work_performed    = trim((string)($_POST['work_performed'] ?? $_POST['repair_summary'] ?? ''));
$parts_replaced    = trim((string)($_POST['parts_replaced'] ?? ''));
$tools_used        = trim((string)($_POST['tools_used'] ?? ''));
$materials_used    = trim((string)($_POST['materials_used'] ?? ''));
$repair_duration   = trim((string)($_POST['repair_duration'] ?? ''));
$repair_cost       = (float)($_POST['repair_cost'] ?? 0);
$estimated_cost    = (float)($_POST['estimated_cost'] ?? 0);
$date_started_raw  = trim((string)($_POST['date_started'] ?? ''));
$date_started      = $date_started_raw !== '' ? date('Y-m-d H:i:s', strtotime($date_started_raw)) : null;

// Duration left blank → compute it from the actual start time (form pre-fills
// date_started from the moment the technician pressed Start).
if ($repair_duration === '' && $date_started !== null) {
    $mins = (int) max(1, round((time() - strtotime($date_started)) / 60));
    $repair_duration = $mins >= 60 ? intdiv($mins, 60) . 'h ' . ($mins % 60) . 'm' : $mins . 'm';
}

// --- Photo documentation by stage ---
$base_dir       = __DIR__ . '/uploads/completed_work';
$before_photos  = tcSavePhotoStage('before_photos', $base_dir . '/before');
$during_photos  = tcSavePhotoStage('during_photos', $base_dir . '/during');
$after_photos   = tcSavePhotoStage('after_photos',  $base_dir . '/after');
// Backward-compatible single field.
$work_photos    = tcSavePhotoStage('work_photos', $base_dir);

$conn = getDBConnection();

try {
    $conn->begin_transaction();

    // Mark task completed with the full repair log.
    $stmt = $conn->prepare("
        UPDATE defect_reports
        SET status = 'completed',
            completion_date = NOW(),
            date_started = COALESCE(?, date_started, started_at),
            diagnosis = ?,
            actions_performed = ?,
            repair_procedures = ?,
            work_performed = ?,
            parts_replaced = ?,
            tools_used = ?,
            materials_used = ?,
            repair_duration = ?,
            repair_cost = ?,
            estimated_cost = ?,
            before_photos = ?,
            during_photos = ?,
            after_photos = ?,
            work_photos = ?
        WHERE report_id = ? AND assigned_to = ?
    ");
    if (!$stmt) {
        throw new Exception('Unable to prepare completion update.');
    }

    $before_json = json_encode($before_photos);
    $during_json = json_encode($during_photos);
    $after_json  = json_encode($after_photos);
    $work_json   = json_encode($work_photos);
    $cost_str    = number_format($repair_cost, 2, '.', '');
    $est_str     = number_format($estimated_cost, 2, '.', '');

    $stmt->bind_param(
        'sssssssssssssssss',
        $date_started, $diagnosis, $actions_performed, $repair_procedures, $work_performed,
        $parts_replaced, $tools_used, $materials_used,
        $repair_duration, $cost_str, $est_str,
        $before_json, $during_json, $after_json, $work_json,
        $report_id, $technician_id
    );
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        throw new Exception('Task not found or not assigned to you.');
    }
    $stmt->close();

    // Record maintenance history.
    $hist = $conn->prepare("
        INSERT INTO maintenance_history (equipment_id, report_id, maintenance_type, performed_by, work_description, parts_used, cost, maintenance_date)
        SELECT equipment_id, report_id, 'repair', ?, ?, ?, ?, NOW()
        FROM defect_reports WHERE report_id = ?
    ");
    if ($hist) {
        $workDesc = $work_performed !== '' ? $work_performed : $actions_performed;
        $hist->bind_param('sssss', $technician_id, $workDesc, $parts_replaced, $cost_str, $report_id);
        $hist->execute();
        $hist->close();
    }

    // Notify the assigning admin/handler.
    $n1 = $conn->prepare("
        INSERT INTO notifications (notification_id, user_id, message, type, related_id, created_date)
        SELECT CONCAT('NTF-', md5(random()::text)), assigned_by,
               CONCAT('Repair completed for report ', ?), 'task_completed', ?, NOW()
        FROM defect_reports WHERE report_id = ?
    ");
    if ($n1) { $n1->bind_param('sss', $report_id, $report_id, $report_id); $n1->execute(); $n1->close(); }

    $conn->commit();

    // Notify the reporter (in-app + email), consistent with the rest of the lifecycle.
    if (function_exists('notifyReporter')) {
        $fresh = getDefectReportById($report_id);
        if ($fresh) {
            notifyReporter(
                $fresh,
                'Repair work completed on your report ' . $report_id . '. Awaiting PMO verification.',
                'Repair Completed',
                'The technician has completed the repair work on your reported equipment. It is now pending final verification by the Property Management Office.'
            );
        }
    }

    logActivity($technician_id, 'task.complete', 'Completed repair for report ' . $report_id);

    echo json_encode(['success' => true, 'message' => 'Task marked as completed successfully.']);
} catch (Exception $e) {
    $conn->rollback();
    error_log('technician_complete_task error: ' . $e->getMessage());
    // Already logged above — the driver's text stays out of the response.
    echo json_encode(['success' => false, 'message' => 'The completion report could not be saved. Please try again, or contact the PMO if it keeps failing.']);
}
exit();
