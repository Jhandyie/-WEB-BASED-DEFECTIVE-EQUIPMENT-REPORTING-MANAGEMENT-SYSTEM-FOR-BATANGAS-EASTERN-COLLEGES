<?php
// add_test_admin.php - Add test admin user

require_once 'config/database.php';

$conn = getDBConnection();

// Delete existing user if exists
$stmt = $conn->prepare("DELETE FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$email = 'thesterads@gmail.com';
$stmt->execute();
$stmt->close();

// Insert new admin user
$stmt = $conn->prepare("INSERT INTO users (username, password, fullname, email, role, status) VALUES (?, ?, ?, ?, ?, ?)");
$username = 'testadmin';
$password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // password: password
$fullname = 'Test Administrator';
$role = 'admin';
$status = 'active';

$stmt->bind_param("ssssss", $username, $password, $fullname, $email, $role, $status);

if ($stmt->execute()) {
    echo "Test admin user added successfully. Email: thesterads@gmail.com, Password: password\n";
} else {
    echo "Failed to add user: " . $stmt->error . "\n";
}

$stmt->close();
$conn->close();
?>
