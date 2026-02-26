<?php
// Test verifyLogin function
require_once 'config/database.php';
require_once 'includes/otp_helper.php';

$email = 'thesterads@gmail.com';
$password = 'admin123';

$conn = getDBConnection();

// Check user
$stmt = $conn->prepare("SELECT user_id, email, fullname, password, status FROM users WHERE email = ? AND role = 'admin' ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "User not found\n";
    exit();
}

echo "User: " . $user['fullname'] . "\n";
echo "Status: " . $user['status'] . "\n";

$verify = password_verify($password, $user['password']);
echo "Password verify: " . ($verify ? "SUCCESS" : "FAILED") . "\n";

if ($verify) {
    // Test OTP
    $otp_result = requestLoginOTP($email, 'admin');
    echo "OTP Result: " . json_encode($otp_result) . "\n";
}

$conn->close();
?>
