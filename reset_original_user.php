<?php
// Reset the original user's password

require_once 'config/database.php';

$conn = getDBConnection();

// Reset password for original user
$email = "thesterads@gmail.com";
$new_password = "newpassword123";
$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
$stmt->bind_param("ss", $hashed_password, $email);

if ($stmt->execute()) {
    echo "Password reset successful!\n\n";
    echo "=== ORIGINAL USER CREDENTIALS ===\n";
    echo "Email: $email\n";
    echo "New Password: $new_password\n";
    echo "================================\n";
} else {
    echo "Error: " . $stmt->error . "\n";
}

$stmt->close();
$conn->close();
?>
