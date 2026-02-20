<?php
require_once 'config/database.php';

$conn = getDBConnection();

echo "=== Checking ALL users in the database ===\n\n";

// Check users table
$stmt = $conn->prepare("SELECT user_id, email, username, role, status FROM users");
$stmt->execute();
$result = $stmt->get_result();

echo "1. Users table:\n";
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  - ID: {$row['user_id']}, Username: {$row['username']}, Email: {$row['email']}, Role: {$row['role']}, Status: {$row['status']}\n";
    }
} else {
    echo "  No users found\n";
}
$stmt->close();

// Check students table
$stmt = $conn->prepare("SELECT student_id, email, fullname, status FROM students");
$stmt->execute();
$result = $stmt->get_result();

echo "\n2. Students table:\n";
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  - ID: {$row['student_id']}, Email: {$row['email']}, Name: {$row['fullname']}, Status: {$row['status']}\n";
    }
} else {
    echo "  No students found\n";
}
$stmt->close();

