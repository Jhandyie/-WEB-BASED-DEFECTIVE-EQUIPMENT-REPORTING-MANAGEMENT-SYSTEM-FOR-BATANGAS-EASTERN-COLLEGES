<?php
require_once 'config/database.php';

$conn = getDBConnection();

// Drop the existing table
$drop_sql = "DROP TABLE IF EXISTS email_otp";
if ($conn->query($drop_sql) === TRUE) {
    echo "Old table dropped successfully\n";
} else {
    echo "Error dropping table: " . $conn->error . "\n";
    $conn->close();
    exit();
}

// Create the table with correct structure
$create_sql = "CREATE TABLE `email_otp` (
  `otp_id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL,
  `otp_code` VARCHAR(6) NOT NULL,
  `user_role` ENUM('admin', 'handler', 'technician', 'faculty', 'student') NOT NULL DEFAULT 'admin',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_used` TINYINT(1) DEFAULT 0,
  `attempts` INT DEFAULT 0,
  INDEX `idx_email` (`email`),
  INDEX `idx_otp_code` (`otp_code`),
  INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_sql) === TRUE) {
    echo "New table created successfully with correct enum values\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

$conn->close();
?>
