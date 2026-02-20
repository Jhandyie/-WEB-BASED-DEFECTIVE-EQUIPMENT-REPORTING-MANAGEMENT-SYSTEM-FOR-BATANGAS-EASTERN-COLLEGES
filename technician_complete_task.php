<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
    header('Location: login.html');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete') {
    $report_id = $_POST['report_id'];
    $work_performed = $_POST['work_performed'];
    $parts_replaced = $_POST['parts_replaced'];
    $repair_cost = floatval($_POST['repair_cost']);
    $completion_notes = $_POST['completion_notes'];
    $technician_id = $_SESSION['user_id'];

    // Handle photo uploads
    $work_photos = [];
    if (isset($_FILES['work_photos'])) {
        $upload_dir = 'uploads/completed_work/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        foreach ($_FILES['work_photos']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['work_photos']['error'][$key] === UPLOAD_ERR_OK) {
                $filename = uniqid() . '_' . basename($_FILES['work_photos']['name'][$key]);
                $filepath = $upload_dir . $filename;

                if (move_uploaded_file($tmp_name, $filepath)) {
                    $work_photos[] = $filepath;
                }
            }
        }
    }
    
    try {
        $conn->begin_transaction();

        // Mark task as completed
        $stmt = $conn->prepare("
            UPDATE defect_reports 
            SET status = 'completed',
                completion_date = NOW(),
                work_performed = ?,
                parts_replaced = ?,
                repair_cost = ?,
                completion_notes = ?,
                work_photos = ?
            WHERE report_id = ? AND assigned_to = ?
        ");
        $photos_json = json_encode($work_photos);
        $stmt->bind_param("ssdsssi", $work_performed, $parts_replaced, $repair_cost, 
                         $completion_notes, $photos_json, $report_id, $technician_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception("Unable to complete task. Task not found or not assigned to you.");
        }

        // Update equipment maintenance history
        $stmt = $conn->prepare("
            INSERT INTO maintenance_history (equipment_id, maintenance_type, performed_by, 
                                            work_description, parts_used, cost, maintenance_date)
            SELECT equipment_id, 'repair', ?, ?, ?, ?, NOW()
            FROM defect_reports
            WHERE report_id = ?
        ");
        $stmt->bind_param("issds", $technician_id, $work_performed, $parts_replaced, $repair_cost, $report_id);
        $stmt->execute();

        // Notify equipment handler
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, message, type, related_id, created_date)
            SELECT assigned_by,
                   CONCAT('Task completed for report ', ?),
                   'task_completed',
                   ?,
                   NOW()
            FROM defect_reports
            WHERE report_id = ?
        ");
        $stmt->bind_param("sss", $report_id, $report_id, $report_id);
        $stmt->execute();

        // Notify reporter
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, message, type, related_id, created_date)
            SELECT reported_by,
                   CONCAT('Repair work completed on your report ', ?),
                   'repair_completed',
                   ?,
                   NOW()
            FROM defect_reports
            WHERE report_id = ?
        ");
        $stmt->bind_param("sss", $report_id, $report_id, $report_id);
        $stmt->execute();

        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Task marked as completed successfully'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'Error completing task: ' . $e->getMessage()
        ]);
    }
    exit();
}
?>