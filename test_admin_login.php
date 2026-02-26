<?php
// Test admin login - simulate login without session issues
header('Content-Type: application/json');

require_once 'config/database.php';

$email = $_POST['email'] ?? 'thesterads@gmail.com';
$password = $_POST['password'] ?? 'admin123';

$conn = getDBConnection();

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Check if user exists
$stmt = $conn->prepare("SELECT id, user_id, email, fullname, password, status, role FROM users WHERE email = ? AND role = 'admin' ORDER BY id DESC LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Admin user not found with email: ' . $email]);
    $stmt->close();
    $conn->close();
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

echo "User found:\n";
print_r($user);

echo "\n\nPassword verification:\n";
$verify = password_verify($password, $user['password']);
echo "Result: " . ($verify ? "TRUE" : "FALSE") . "\n";

echo "\nExpected password hash for 'admin123':\n";
echo password_hash('admin123', PASSWORD_DEFAULT) . "\n";

$conn->close();
?>
