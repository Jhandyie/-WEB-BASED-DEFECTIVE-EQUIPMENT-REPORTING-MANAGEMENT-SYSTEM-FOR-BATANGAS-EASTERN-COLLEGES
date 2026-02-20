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
    $report = getReportByIdPublic($_GET['report_id']);
    echo json_encode([
        'success' => true,
        'report' => $report
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}