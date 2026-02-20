<?php
// Remove UNIQUE constraint from email column to allow duplicate emails

require_once 'config/database.php';

$conn = getDBConnection();

echo "=== Removing UNIQUE constraint from email column ===\n\n";

// Check current indexes on email column
echo "Current indexes on email column:\n";
$result = $conn->query("SHOW INDEX FROM users WHERE Column_name = 'email'");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  - Key name: {$row['Key_name']}, Column: {$row['Column_name']}, Non_unique: {$row['Non_unique']}\n";
    }
} else {
    echo "  No indexes found\n";
}

// Drop the unique constraint (if it exists)
echo "\nDropping unique constraint...\n";
$conn->query("ALTER TABLE users DROP INDEX idx_email");

// Verify
echo "\nVerifying indexes after removal:\n";
$result = $conn->query("SHOW INDEX FROM users WHERE Column_name = 'email'");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  - Key name: {$row['Key_name']}, Column: {$row['Column_name']}, Non_unique: {$row['Non_unique']}\n";
    }
} else {
    echo "  No unique constraints remaining on email!\n";
}

echo "\n=== DONE ===\n";
echo "You can now register multiple accounts with the same email.\n";

$conn->close();
?>
