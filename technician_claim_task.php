<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['role'] !== 'technician') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$report_id = $_POST['report_id'] ?? '';
$technician_id = $_SESSION['user_id'];

if (empty($report_id)) {
    echo json_encode(['success' => false, 'message' => 'Report ID is required']);
    exit();
}

try {
    $conn = getDBConnection();
    $conn->begin_transaction();

    // Check if the report exists and is unassigned
    $stmt = $conn->prepare("SELECT status, assigned_to FROM defect_reports WHERE report_id = ?");
    $stmt->bind_param("s", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $report = $result->fetch_assoc();

    if (!$report) {
        throw new Exception('Report not found');
    }

    if ($report['status'] !== 'reported' || $report['assigned_to'] !== null) {
        throw new Exception('Task is already assigned or not available for claiming');
    }

    // Update the report to assign it to the technician
    $stmt = $conn->prepare("UPDATE defect_reports SET assigned_to = ?, status = 'assigned', assigned_date = NOW() WHERE report_id = ?");
    $stmt->bind_param("ss", $technician_id, $report_id);
    $stmt->execute();

    // Add notification for the technician
    $message = "You have successfully claimed maintenance task: $report_id";
    addNotification($technician_id, $message, 'task_claimed', $report_id);

    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Task claimed successfully']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
