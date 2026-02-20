<?php
require_once 'config/database.php';

$conn = getDBConnection();

if ($conn) {
    // Check if email_otp table exists
    $result = $conn->query("SHOW TABLES LIKE 'email_otp'");
    if ($result->num_rows > 0) {
        echo "email_otp table exists.\n";

        // Check table structure
        $result = $conn->query("DESCRIBE email_otp");
        echo "Table structure:\n";
        while ($row = $result->fetch_assoc()) {
            echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }

        // Check for any OTP records
        $result = $conn->query("SELECT COUNT(*) as count FROM email_otp");
        $row = $result->fetch_assoc();
        echo "Total OTP records: " . $row['count'] . "\n";

        // Show recent OTPs
        $result = $conn->query("SELECT * FROM email_otp ORDER BY created_at DESC LIMIT 5");
        if ($result->num_rows > 0) {
            echo "Recent OTP records:\n";
            while ($row = $result->fetch_assoc()) {
                echo "- ID: " . $row['otp_id'] . ", Email: " . $row['email'] . ", OTP: " . $row['otp_code'] . ", Role: " . $row['user_role'] . ", Used: " . $row['is_used'] . ", Expires: " . $row['expires_at'] . "\n";
            }
        } else {
            echo "No OTP records found.\n";
        }
    } else {
        echo "email_otp table does not exist.\n";
    }

    $conn->close();
} else {
    echo "Database connection failed.\n";
}
?>
