<?php
// Create a test user for testing

require_once 'config/database.php';

$conn = getDBConnection();

// Create a test user
$test_email = "testuser@example.com";
$test_password = "test123456";
$test_fullname = "Test User";
$username = "testuser";

// Generate user_id
$user_id_stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'student' AND user_id LIKE 'STU-%' ORDER BY CAST(SUBSTRING(user_id, 5) AS UNSIGNED) DESC LIMIT 1");
$user_id_stmt->execute();
$user_id_result = $user_id_stmt->get_result();

if ($user_id_result->num_rows > 0) {
    $last_user = $user_id_result->fetch_assoc();
    $last_id_num = intval(substr($last_user['user_id'], 4));
    $new_id_num = $last_id_num + 1;
    $new_user_id = 'STU-' . str_pad($new_id_num, 3, '0', STR_PAD_LEFT);
} else {
    $new_user_id = 'STU-001';
}
$user_id_stmt->close();

// Hash password
$hashed_password = password_hash($test_password, PASSWORD_DEFAULT);

// Check if user already exists
$check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
$check_stmt->bind_param("s", $test_email);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    echo "User already exists, updating password...\n";
    $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
    $update_stmt->bind_param("ss", $hashed_password, $test_email);
    $update_stmt->execute();
    echo "Password updated!\n";
} else {
    // Insert new user
    $insert_stmt = $conn->prepare("INSERT INTO users (user_id, username, password, fullname, email, role, status) VALUES (?, ?, ?, ?, ?, 'student', 'active')");
    $insert_stmt->bind_param("sssss", $new_user_id, $username, $hashed_password, $test_fullname, $test_email);
    
    if ($insert_stmt->execute()) {
        echo "Test user created!\n";
    } else {
        echo "Error: " . $insert_stmt->error . "\n";
    }
}

echo "\n=== TEST USER CREDENTIALS ===\n";
echo "Email: $test_email\n";
echo "Password: $test_password\n";
echo "Full Name: $test_fullname\n";
echo "User ID: $new_user_id\n";
echo "==============================\n";

$conn->close();
?>
