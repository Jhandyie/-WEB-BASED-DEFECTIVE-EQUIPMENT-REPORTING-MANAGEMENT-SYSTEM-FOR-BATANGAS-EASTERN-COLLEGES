<?php
require_once 'config/database.php';
$conn = getDBConnection();
$result = $conn->query('SELECT user_id, username, email, role FROM users ORDER BY id DESC LIMIT 10');
while ($row = $result->fetch_assoc()) {
    echo $row['user_id'] . ' | ' . $row['username'] . ' | ' . $row['email'] . ' | ' . $row['role'] . "\n";
}
$conn->close();
?>
