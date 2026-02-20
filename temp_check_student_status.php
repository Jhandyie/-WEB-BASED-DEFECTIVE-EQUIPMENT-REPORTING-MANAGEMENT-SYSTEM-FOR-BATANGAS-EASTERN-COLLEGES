<?php
require_once 'config/database.php';

$conn = getDBConnection();

$result = $conn->query('SELECT email, fullname, status FROM students LIMIT 5');

if ($result->num_rows > 0) {
    echo "Students:\n";
    while($row = $result->fetch_assoc()) {
        echo 'Email: ' . $row['email'] . ', Name: ' . $row['fullname'] . ', Status: ' . $row['status'] . "\n";
    }
} else {
    echo "No students found.\n";
}

$conn->close();
?>
