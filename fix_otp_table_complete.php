<?php
require_once 'config/database.php';

$conn = getDBConnection();

// Drop and recreate the table with correct structure
$sql = "DROP TABLE IF EXISTS email_otp_temp";
$conn->query($sql);

$sql = "CREATE TABLE email_otp_temp (
  otp_id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  otp_code VARCHAR(6) NOT NULL,
  user_role ENUM('admin', 'handler', 'technician', 'faculty', 'student') NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_used TINYINT(1) DEFAULT 0,
  attempts INT DEFAULT 0,
  INDEX idx_email (email),
  INDEX idx_otp_code (otp_code),
  INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql) === TRUE) {
    echo "Temp table created successfully\n";

    // Copy data from old table
    $copy_sql = "INSERT INTO email_otp_temp (otp_id, email, otp_code, user_role, created_at, expires_at, is_used, attempts)
                 SELECT otp_id, email, otp_code,
                        CASE
                            WHEN user_role = '' OR user_role IS NULL THEN 'admin'
                            ELSE user_role
                        END as user_role,
                        created_at, expires_at, is_used, attempts
                 FROM email_otp";

    if ($conn->query($copy_sql) === TRUE) {
        echo "Data copied successfully\n";

        // Drop old table and rename temp table
        $conn->query("DROP TABLE email_otp");
        $conn->query("RENAME TABLE email_otp_temp TO email_otp");

        echo "Table recreated with correct structure\n";
    } else {
        echo "Error copying data: " . $conn->error . "\n";
    }
} else {
    echo "Error creating temp table: " . $conn->error . "\n";
}

$conn->close();
?>
