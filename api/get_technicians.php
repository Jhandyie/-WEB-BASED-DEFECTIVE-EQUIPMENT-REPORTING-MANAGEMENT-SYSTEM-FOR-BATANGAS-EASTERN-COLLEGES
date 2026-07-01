<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

requireRole('handler');

header('Content-Type: application/json');

try {
    $technicians = getAvailableTechnicians();
    echo json_encode([
        'success' => true,
        'technicians' => $technicians
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}