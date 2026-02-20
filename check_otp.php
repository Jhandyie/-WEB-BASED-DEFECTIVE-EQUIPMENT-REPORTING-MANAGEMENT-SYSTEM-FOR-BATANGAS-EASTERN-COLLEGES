<?php
require_once 'config/database.php';

$conn = getDBConnection();

$result = $conn->query('SELECT email, otp_code, user_role, is_used, expires_at > NOW() as is_valid FROM email_otp ORDER BY created_at DESC LIMIT 5');

if ($result->num_rows > 0) {
    echo "Recent OTP records:\n";
    while($row = $result->fetch_assoc()) {
        echo 'Email: ' . $row['email'] . ', OTP: ' . $row['otp_code'] . ', Role: ' . $row['user_role'] . ', Used: ' . $row['is_used'] . ', Valid: ' . ($row['is_valid'] ? 'Yes' : 'No') . "\n";
    }
} else {
    echo "No OTP records found.\n";
}

$conn->close();
?>
