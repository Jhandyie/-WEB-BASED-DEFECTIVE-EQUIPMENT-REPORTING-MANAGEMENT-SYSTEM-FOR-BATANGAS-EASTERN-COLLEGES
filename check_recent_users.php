<?php
// check_recent_users.php - Check recent user registrations

require_once 'config/database.php';

$conn = getDBConnection();

echo "=== Recent User Registrations ===\n\n";

// Get the 10 most recent users
$result = $conn->query("SELECT id, user_id, username, email, role, status, created_at FROM users ORDER BY id DESC LIMIT 10");

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}\n";
        echo "  user_id: {$row['user_id']}\n";
        echo "  username: {$row['username']}\n";
        echo "  email: {$row['email']}\n";
        echo "  role: {$row['role']}\n";
        echo "  status: {$row['status']}\n";
        echo "  created_at: {$row['created_at']}\n";
        echo "\n";
    }
} else {
    echo "No users found in database.\n";
}

$conn->close();
?>
