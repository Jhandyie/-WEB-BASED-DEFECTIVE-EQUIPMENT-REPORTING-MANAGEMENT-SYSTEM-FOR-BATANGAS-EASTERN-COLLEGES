<?php
// fix_timezone.php - Fix timezone mismatch between PHP and MySQL

require_once 'config/database.php';

echo "=== FIXING TIMEZONE MISMATCH ===\n\n";

$conn = getDBConnection();

// Get current PHP timezone
$phpTimezone = date_default_timezone_get();
echo "PHP Timezone: $phpTimezone\n";

// Set MySQL timezone to match PHP
$timezoneOffset = date('P'); // Get PHP timezone offset like +01:00
echo "Setting MySQL timezone to: $timezoneOffset\n";

$conn->query("SET time_zone = '$timezoneOffset'");

if ($conn->error) {
    echo "Error setting timezone: " . $conn->error . "\n";
    exit(1);
}

// Verify the timezone was set
$result = $conn->query("SELECT @@session.time_zone as db_timezone, NOW() as db_time");
$row = $result->fetch_assoc();

echo "MySQL Timezone: " . $row['db_timezone'] . "\n";
echo "MySQL Time: " . $row['db_time'] . "\n";
echo "PHP Time: " . date('Y-m-d H:i:s') . "\n\n";

// Test OTP creation and verification
echo "Testing OTP functionality...\n";

require_once 'includes/otp_helper.php';

$test_email = 'test@example.com';
$role = 'admin';

// Request OTP
$result = requestLoginOTP($test_email, $role);
echo "OTP Request: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";

if ($result['success']) {
    // Get the OTP from database
    $stmt = $conn->prepare("SELECT otp_code, expires_at FROM email_otp WHERE email = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("s", $test_email);
    $stmt->execute();
    $otp_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($otp_data) {
        $otp_code = $otp_data['otp_code'];
        $expires_at = $otp_data['expires_at'];

        echo "OTP Code: $otp_code\n";
        echo "Expires At: $expires_at\n";
        echo "Current Time: " . date('Y-m-d H:i:s') . "\n";

        // Test verification
        $verify_result = verifyOTP($test_email, $otp_code, $role);
        echo "Verification: " . ($verify_result['success'] ? 'SUCCESS' : 'FAILED - ' . $verify_result['message']) . "\n";
    }
}

echo "\n=== TIMEZONE FIX COMPLETED ===\n";
?>
