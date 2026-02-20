<?php
// check_table_structure.php - Check the actual users table structure

require_once 'config/database.php';

$conn = getDBConnection();

echo "=== USERS TABLE STRUCTURE ===\n\n";

$result = $conn->query("DESCRIBE users");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "Field: {$row['Field']}\n";
        echo "  Type: {$row['Type']}\n";
        echo "  Null: {$row['Null']}\n";
        echo "  Key: {$row['Key']}\n";
        echo "  Default: {$row['Default']}\n";
        echo "  Extra: {$row['Extra']}\n";
        echo "\n";
    }
} else {
    echo "Error: " . $conn->error . "\n";
}

echo "\n=== CURRENT USERS IN DATABASE ===\n\n";
$result = $conn->query("SELECT * FROM users LIMIT 5");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "No users found or error: " . $conn->error . "\n";
}

$conn->close();
?>
