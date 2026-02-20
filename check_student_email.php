<?php
require_once 'config/database.php';

$conn = getDBConnection();

$email = 'thesterads@gmail.com';

$stmt = $conn->prepare("SELECT student_id, fullname, email, status FROM students WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "Student found with email: $email\n";
    $student = $result->fetch_assoc();
    echo "ID: " . $student['student_id'] . "\n";
    echo "Name: " . $student['fullname'] . "\n";
    echo "Email: " . $student['email'] . "\n";
    echo "Status: " . $student['status'] . "\n";
} else {
    echo "No student found with email: $email\n";
    
    // Show all students
    $result = $conn->query("SELECT email, fullname FROM students");
    echo "All students in database:\n";
    while($row = $result->fetch_assoc()) {
        echo "- " . $row['email'] . " (" . $row['fullname'] . ")\n";
    }
}

$stmt->close();
$conn->close();
?>
