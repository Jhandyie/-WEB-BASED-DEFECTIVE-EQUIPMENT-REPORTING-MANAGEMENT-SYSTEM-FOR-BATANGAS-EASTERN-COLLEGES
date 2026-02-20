<?php
require_once 'config/database.php';
$conn = getDBConnection();

// Clean up test users
$conn->query("DELETE FROM users WHERE email LIKE 'simpletest%'");
$conn->query("DELETE FROM users WHERE email LIKE 'test_%@fix.com'");
echo "Test users cleaned up!\n";

// Show remaining users
$result = $conn->query('SELECT user_id, username, email, role FROM users ORDER BY id');
echo "\nRemaining users:\n";
while ($row = $result->fetch_assoc()) {
    echo $row['user_id'] . ' | ' . $row['username'] . ' | ' . $row['email'] . ' | ' . $row['role'] . "\n";
}
$conn->close();
?>
