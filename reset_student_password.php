<?php
// Simple password reset script for student login issue
// This script will reset the password for the student account

require_once 'config/database.php';

echo "=== PASSWORD RESET FOR STUDENT ===\n\n";

$email = 'thesterads@gmail.com';
$new_password = 'password123'; // Set a default password

// Hash the new password
$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

$conn = getDBConnection();

// Update the password
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ? AND role = 'student'");
$stmt->bind_param("ss", $hashed_password, $email);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo "SUCCESS: Password has been reset!\n";
        echo "Email: $email\n";
        echo "New Password: $new_password\n\n";
        echo "You can now login with these credentials.\n";
    } else {
        echo "ERROR: No user found with email $email and role 'student'\n";
    }
} else {
    echo "ERROR: Failed to reset password\n";
}

$stmt->close();
$conn->close();

echo "\n=== DONE ===\n";
?>
