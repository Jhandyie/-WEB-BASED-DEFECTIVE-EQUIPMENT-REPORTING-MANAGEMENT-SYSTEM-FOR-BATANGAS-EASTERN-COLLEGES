<?php
header('Content-Type: application/json');
require_once '../config/database.php';

// Load settings to get admin email
$settings_file = '../data/system_settings.json';
$settings = json_decode(file_get_contents($settings_file), true);
$admin_email = $settings['notifications']['admin_email'] ?? 'thesterads@gmail.com';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$role = trim($input['role'] ?? '');

if (empty($username) || empty($role)) {
    echo json_encode(['success' => false, 'message' => 'Username and role are required.']);
    exit();
}

if ($role !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Only admin password reset is supported.']);
    exit();
}

// For hardcoded admin, send actual email
if ($username === 'admin') {
    require_once '../includes/mail_helper.php';

    // Generate reset token for hardcoded admin
    $reset_token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Store reset token in database
    $conn = getDBConnection();
    $sql = "INSERT INTO password_resets (email, token, expires_at, created_at) VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = NOW()";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("sss", $admin_email, $reset_token, $expires);
        $stmt->execute();
        $stmt->close();
    }

    $reset_link = "http://localhost/bec_equipment/admin/reset_password.php?token=$reset_token";
    $subject = "Password Reset - BEC Equipment Management System";
    $message = "
<html>
<head>
    <title>Password Reset - BEC Equipment Management System</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #800000; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .button { display: inline-block; background-color: #C9A227; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>BEC Equipment Management System</h1>
        </div>
        <div class='content'>
            <h2>Password Reset Request</h2>
            <p>Hello Administrator,</p>
            <p>You have requested to reset your password for the BEC Equipment Management System.</p>
            <p>Click the button below to reset your password:</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='$reset_link' class='button'>Reset Password</a>
            </p>
            <p><strong>Important:</strong> This link will expire in 1 hour for security reasons.</p>
            <p>If you did not request this password reset, please ignore this email.</p>
        </div>
        <div class='footer'>
            <p>&copy; " . date('Y') . " Batangas Eastern Colleges. All rights reserved.</p>
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
";

    // Send the email
    $emailSent = sendEmail($admin_email, $subject, $message);

    if ($emailSent) {
        echo json_encode([
            'success' => true,
            'message' => 'Password reset link has been sent to your registered email address.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send password reset email. Please try again later.'
        ]);
    }
    exit();
}

// For database admins
$conn = getDBConnection();
$sql = "SELECT email FROM admins WHERE username = ? LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit();
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'No admin account found with that username.']);
    exit();
}

$email = $user['email'];

// Generate reset token
$reset_token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

// Store reset token in database (you might need to create a password_resets table)
$sql = "INSERT INTO password_resets (email, token, expires_at, created_at) VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = NOW()";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit();
}

$stmt->bind_param("sss", $email, $reset_token, $expires);
$success = $stmt->execute();
$stmt->close();

if (!$success) {
    echo json_encode(['success' => false, 'message' => 'Failed to generate reset token.']);
    exit();
}

// Send email (simulated - in production, use PHPMailer or similar)
$reset_link = "http://localhost/bec_equipment/admin/reset_password.php?token=$reset_token";
$subject = "Password Reset - BEC Equipment Management System";
$message = "
Hello,

You have requested to reset your password for the BEC Equipment Management System.

Click the following link to reset your password:
$reset_link

This link will expire in 1 hour.

If you did not request this reset, please ignore this email.

Best regards,
BEC Equipment Management System
";

// Simulate email sending
error_log("Password reset email would be sent to: $email");
error_log("Reset link: $reset_link");

echo json_encode([
    'success' => true,
    'message' => 'Password reset link has been sent to your registered email address.'
]);
?>
