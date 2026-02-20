<?php
require_once 'config/database.php';
$conn = getDBConnection();
echo "=== Checking user thesterads@gmail.com ===\n";
$result = $conn->query("SELECT user_id, username, email, fullname, created_at, status FROM users WHERE email = 'thesterads@gmail.com'");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "User found:\n";
        print_r($row);
    }
} else {
    echo "No user found with email thesterads@gmail.com\n";
}

echo "\n=== All users ===\n";
$result = $conn->query("SELECT user_id, username, email, fullname, created_at FROM users ORDER BY created_at DESC LIMIT 10");
while ($row = $result->fetch_assoc()) {
    echo $row['user_id'] . ' - ' . $row['username'] . ' - ' . $row['email'] . ' - ' . $row['fullname'] . ' - ' . $row['created_at'] . "\n";
}
?>
