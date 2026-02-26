<?php
require_once 'config/database.php';

$email = 'thesterads@gmail.com';
$password = 'admin123';

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT user_id, username, email, fullname, password, status FROM users WHERE email = ? AND role = 'admin' ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

echo "User: " . $user['fullname'] . " (" . $user['email'] . ")\n";
echo "Stored hash: " . $user['password'] . "\n\n";

$verify = password_verify($password, $user['password']);
echo "Password '$password' verification: " . ($verify ? "SUCCESS" : "FAILED") . "\n";

if (!$verify) {
    // Let's try to update with a new hash
    $new_hash = password_hash($password, PASSWORD_DEFAULT);
    echo "\nNew hash: $new_hash\n";
    
    // Update password
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ? AND role = 'admin'");
    $stmt->bind_param("ss", $new_hash, $email);
    $stmt->execute();
    $stmt->close();
    echo "Password updated!\n";
    
    // Verify again
    $stmt = $conn->prepare("SELECT password FROM users WHERE email = ? AND role = 'admin' LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    $verify2 = password_verify($password, $user['password']);
    echo "After update - Password verification: " . ($verify2 ? "SUCCESS" : "FAILED") . "\n";
}

$conn->close();
?>
