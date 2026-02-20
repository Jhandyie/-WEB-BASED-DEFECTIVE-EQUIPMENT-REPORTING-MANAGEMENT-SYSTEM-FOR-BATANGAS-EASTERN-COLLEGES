<?php
require_once 'config/database.php';
$conn = getDBConnection();
$result = $conn->query("SELECT user_id, username, email FROM users ORDER BY user_id");
while ($row = $result->fetch_assoc()) {
    echo $row['user_id'] . ' - ' . $row['username'] . ' - ' . $row['email'] . PHP_EOL;
}
?>
