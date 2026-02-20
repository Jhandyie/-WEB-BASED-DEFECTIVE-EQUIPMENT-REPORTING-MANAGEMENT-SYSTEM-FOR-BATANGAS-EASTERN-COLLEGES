<?php
// remove_email_unique_constraint.php
// Remove UNIQUE constraint from email column in users table

require_once 'config/database.php';

$conn = getDBConnection();

echo "=== Removing UNIQUE constraint from email column ===\n\n";

// First, let's check the current index on email
$result = $conn->query("SHOW INDEX FROM users WHERE Column_name = 'email'");
if ($result && $result->num_rows > 0) {
    echo "Current indexes on email column:\n";
    while ($row = $result->fetch_assoc()) {
        print_r($row);
    }
    echo "\n";
}

// Drop the unique index
$drop_index_sql = "ALTER TABLE users DROP INDEX email";
if ($conn->query($drop_index_sql)) {
    echo "SUCCESS: Dropped UNIQUE constraint from email column\n";
} else {
    echo "Error dropping index: " . $conn->error . "\n";
}

// Add a non-unique index for faster lookups (optional)
$add_index_sql = "ALTER TABLE users ADD INDEX idx_email (email)";
if ($conn->query($add_index_sql)) {
    echo "SUCCESS: Added non-unique index on email column\n";
} else {
    echo "Error adding index: " . $conn->error . "\n";
}

// Verify the change
echo "\n=== Verifying changes ===\n";
$result = $conn->query("DESCRIBE users");
echo "Current table structure:\n";
while ($row = $result->fetch_assoc()) {
    if ($row['Field'] === 'email') {
        echo "Email field: " . $row['Type'] . " - " . $row['Key'] . "\n";
    }
}

$conn->close();
echo "\nDone!\n";
?>
