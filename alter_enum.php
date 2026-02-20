<?php
require_once 'config/database.php';

$conn = getDBConnection();

// Alter the enum to include all roles
$sql = "ALTER TABLE email_otp MODIFY COLUMN user_role ENUM('admin', 'handler', 'technician', 'faculty', 'student') NOT NULL DEFAULT 'admin'";

if ($conn->query($sql) === TRUE) {
    echo "Enum altered successfully\n";

    // Update any empty roles to admin
    $update_sql = "UPDATE email_otp SET user_role = 'admin' WHERE user_role = '' OR user_role IS NULL";
    if ($conn->query($update_sql) === TRUE) {
        echo "Updated " . $conn->affected_rows . " records\n";
    } else {
        echo "Error updating: " . $conn->error . "\n";
    }
} else {
    echo "Error altering enum: " . $conn->error . "\n";
}

$conn->close();
?>
