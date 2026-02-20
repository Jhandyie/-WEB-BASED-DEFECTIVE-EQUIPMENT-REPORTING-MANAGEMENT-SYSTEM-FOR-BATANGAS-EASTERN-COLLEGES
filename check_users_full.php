<?php
// check_users_full.php - Check full user data including user_id column

require_once 'config/database.php';

$conn = getDBConnection();

echo "=== Full Users Table Data ===\n\n";

$result = $conn->query("SELECT * FROM users LIMIT 10");

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . "\n";
        echo "User ID: " . ($row['user_id'] ?? 'NULL') . "\n";
        echo "Username: " . $row['username'] . "\n";
        echo "Email: " . $row['email'] . "\n";
        echo "Role: " . $row['role'] . "\n";
        echo "Status: " . $row['status'] . "\n";
        echo "---\n";
    }
} else {
    echo "No users found\n";
}

$conn->close();
?>
