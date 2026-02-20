<?php
// add_last_login_column.php - Add last_login column to users table

require_once 'config/database.php';

try {
    $conn = getDBConnection();

    // Check if column exists
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login'");
    if ($result->num_rows == 0) {
        // Add the column
        $sql = "ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL";
        if ($conn->query($sql)) {
            echo "Successfully added last_login column to users table.\n";
        } else {
            echo "Error adding column: " . $conn->error . "\n";
        }
    } else {
        echo "last_login column already exists.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
