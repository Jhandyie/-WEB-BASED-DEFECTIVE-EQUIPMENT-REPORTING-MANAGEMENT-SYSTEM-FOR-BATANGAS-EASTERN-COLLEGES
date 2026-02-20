<?php
require_once 'config/database.php';

$conn = getDBConnection();

$result = $conn->query('SELECT COUNT(*) as count FROM students');
$row = $result->fetch_assoc();
echo 'Student count: ' . $row['count'] . PHP_EOL;

if ($row['count'] > 0) {
    $result = $conn->query('SELECT email, fullname FROM students LIMIT 5');
    echo "Sample students:\n";
    while($row = $result->fetch_assoc()) {
        echo 'Email: ' . $row['email'] . ', Name: ' . $row['fullname'] . "\n";
    }
}

$conn->close();
?>
