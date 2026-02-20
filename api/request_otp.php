<?php
// api/request_otp.php
// API endpoint to request OTP for login

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/otp_helper.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!$data || !isset($data['email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit();
}

$email = trim($data['email']);
$role = isset($data['role']) ? trim($data['role']) : 'admin';

// Validate role - Include all roles including student
$allowed_roles = ['admin', 'handler', 'technician', 'student', 'faculty', 'guest'];
if (!in_array($role, $allowed_roles)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid role']);
    exit();
}

// Request OTP
$result = requestLoginOTP($email, $role);

// Set appropriate HTTP status code
if (!$result['success']) {
    http_response_code(400);
}

echo json_encode($result);
exit();
?>