<?php
require_once 'config/database.php';

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM users WHERE email = 'thesterads@gmail.com'");
if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "User found: " . $user['fullname'] . " (" . $user['role'] . ")\n";
} else {
    echo "User not found\n";
}
$conn->close();
?>
