<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['report_id'])) {
    echo json_encode(['success' => false, 'message' => 'Report ID required']);
    exit();
}

try {
    $conn = getDBConnection();
    $stmt = $conn->prepare("
        SELECT dr.*, e.equipment_name, e.asset_tag
        FROM defect_reports dr
        JOIN equipment e ON dr.equipment_id = e.equipment_id
        WHERE dr.report_id = ? AND dr.status = 'completed'
    ");
    $stmt->bind_param("s", $_GET['report_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $report = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$report) {
        echo json_encode(['success' => false, 'message' => 'Work details not found']);
        exit();
    }

    // Parse technician_notes as JSON for work details
    $work_details = json_decode($report['technician_notes'], true) ?? [];

    $work = [
        'report_id' => $report['report_id'],
        'equipment_name' => $report['equipment_name'],
        'asset_tag' => $report['asset_tag'],
        'work_performed' => $work_details['work_performed'] ?? '',
        'parts_replaced' => $work_details['parts_replaced'] ?? '',
        'repair_cost' => $work_details['repair_cost'] ?? 0,
        'completion_notes' => $work_details['completion_notes'] ?? '',
        'work_photos' => $work_details['work_photos'] ?? null
    ];

    echo json_encode([
        'success' => true,
        'work' => $work
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
