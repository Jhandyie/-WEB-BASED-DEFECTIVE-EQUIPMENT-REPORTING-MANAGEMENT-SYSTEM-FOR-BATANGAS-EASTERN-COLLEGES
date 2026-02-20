<?php
// Check if email_otp table exists
require_once 'config/database.php';

$conn = getDBConnection();

echo "=== Checking email_otp table ===\n\n";

// Check all tables
$result = $conn->query("SHOW TABLES");
echo "All tables in database:\n";
while ($row = $result->fetch_row()) {
    echo "- " . $row[0] . "\n";
}

echo "\n";

// Check if email_otp table exists
$result = $conn->query("SHOW TABLES LIKE 'email_otp'");
if ($result && $result->num_rows > 0) {
    echo "email_otp table: EXISTS\n";
    
    // Show structure
    echo "\nemail_otp table structure:\n";
    $result = $conn->query("DESCRIBE email_otp");
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "email_otp table: NOT FOUND\n";
    echo "This is the issue! The email_otp table doesn't exist.\n";
    echo "Creating the table...\n";
    
    $conn->query("
        CREATE TABLE IF NOT EXISTS `email_otp` (
            `otp_id` int(11) NOT NULL AUTO_INCREMENT,
            `email` varchar(255) NOT NULL,
            `otp_code` varchar(10) NOT NULL,
            `user_role` varchar(50) NOT NULL,
            `is_used` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` datetime NOT NULL,
            PRIMARY KEY (`otp_id`),
            KEY `idx_email` (`email`),
            KEY `idx_otp_code` (`otp_code`),
            KEY `idx_expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "email_otp table created successfully!\n";
}

$conn->close();
echo "\n=== Done ===\n";
?>
