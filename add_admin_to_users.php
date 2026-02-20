<?php
// add_admin_to_users.php - Add admin user to users table

require_once 'config/database.php';

$conn = getDBConnection();

// Check if admin already exists in users table
$result = $conn->query("SELECT id FROM users WHERE email = 'thesterads@gmail.com' AND role = 'admin'");
if ($result && $result->num_rows > 0) {
    echo "Admin user already exists in users table.\n";
    exit();
}

// Get admin data from admins table
$result = $conn->query("SELECT * FROM admins WHERE email = 'thesterads@gmail.com' LIMIT 1");
if (!$result || $result->num_rows == 0) {
    echo "Admin user not found in admins table.\n";
    exit();
}

$admin = $result->fetch_assoc();

// Insert into users table
$sql = "INSERT INTO users (user_id, username, password, fullname, email, phone, role, status, created_at, last_login)
        VALUES (?, ?, ?, ?, ?, ?, 'admin', ?, NOW(), NULL)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssss",
    $admin['admin_id'],
    $admin['username'],
    $admin['password'],
    $admin['fullname'],
    $admin['email'],
    $admin['phone'],
    $admin['status']
);

if ($stmt->execute()) {
    echo "Admin user successfully added to users table.\n";
} else {
    echo "Failed to add admin user: " . $conn->error . "\n";
}

$stmt->close();
?>
