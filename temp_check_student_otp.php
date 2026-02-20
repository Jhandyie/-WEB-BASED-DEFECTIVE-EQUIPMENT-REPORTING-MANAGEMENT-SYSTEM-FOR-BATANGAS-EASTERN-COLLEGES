<?php
require_once 'config/database.php';

$conn = getDBConnection();

$result = $conn->query('SELECT email, otp_code, user_role, is_used, expires_at > NOW() as is_valid FROM email_otp WHERE user_role = "student" ORDER BY created_at DESC LIMIT 5');

if ($result->num_rows > 0) {
    echo "Student OTP records:\n";
    while($row = $result->fetch_assoc()) {
        echo 'Email: ' . $row['email'] . ', OTP: ' . $row['otp_code'] . ', Used: ' . $row['is_used'] . ', Valid: ' . ($row['is_valid'] ? 'Yes' : 'No') . "\n";
    }
} else {
    echo "No student OTP records found.\n";
}

$conn->close();
?>
