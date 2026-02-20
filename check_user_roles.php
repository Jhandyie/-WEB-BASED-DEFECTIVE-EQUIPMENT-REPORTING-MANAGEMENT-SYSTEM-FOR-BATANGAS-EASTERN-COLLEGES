<?php
require_once 'config/database.php';
$conn = getDBConnection();
echo "=== All users with their roles ===\n";
$result = $conn->query("SELECT user_id, username, email, role FROM users");
while ($row = $result->fetch_assoc()) {
    echo $row['user_id'] . ' - ' . $row['username'] . ' - ' . $row['email'] . ' - Role: ' . $row['role'] . PHP_EOL;
}

echo "\n=== Student users (role = 'student') ===\n";
$result = $conn->query("SELECT user_id, username, email FROM users WHERE role = 'student' ORDER BY user_id DESC");
while ($row = $result->fetch_assoc()) {
    echo $row['user_id'] . ' - ' . $row['username'] . ' - ' . $row['email'] . PHP_EOL;
}
?>
