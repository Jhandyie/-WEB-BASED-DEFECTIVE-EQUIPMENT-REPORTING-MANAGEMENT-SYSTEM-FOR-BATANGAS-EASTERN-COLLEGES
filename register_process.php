<?php
// register_process.php
// User registration handler - Returns JSON responses

session_start();
header('Content-Type: application/json');
require_once 'config/database.php';
require_once 'includes/notification_helper.php';

$conn = getDBConnection();

if (!$conn) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please contact support.'
    ]);
    exit();
}

// Only handle POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit();
}

// Get inputs safely
$role = 'student'; // Only students can register through this form
$fullname = trim($_POST['fullname'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');

// Validate inputs
$errors = [];

if (empty($fullname)) {
    $errors[] = "Full name is required.";
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Valid email address is required.";
}

if (empty($password) || strlen($password) < 6) {
    $errors[] = "Password must be at least 6 characters long.";
}

// If there are validation errors, return JSON error
if (!empty($errors)) {
    echo json_encode([
        'success' => false,
        'message' => implode(' ', $errors)
    ]);
    exit();
}

// Email duplicate check removed - now allowing duplicate emails

try {
    // Generate username from email (before @ symbol)
    $username = explode('@', $email)[0];

    // Ensure username is unique
    $username_check_sql = "SELECT user_id FROM users WHERE username = ?";
    $username_check_stmt = $conn->prepare($username_check_sql);
    $username_check_stmt->bind_param("s", $username);
    $username_check_stmt->execute();
    if ($username_check_stmt->get_result()->num_rows > 0) {
        // If username exists, append a number
        $counter = 1;
        $original_username = $username;
        do {
            $username = $original_username . $counter;
            $username_check_stmt->bind_param("s", $username);
            $username_check_stmt->execute();
            $counter++;
        } while ($username_check_stmt->get_result()->num_rows > 0);
    }
    $username_check_stmt->close();

    // Generate user_id (STU-001, STU-002, etc.)
    // Only look for existing STU- prefixed user_ids to avoid issues with other ID formats like USR-1
    $user_id_stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'student' AND user_id LIKE 'STU-%' ORDER BY CAST(SUBSTRING(user_id, 5) AS UNSIGNED) DESC LIMIT 1");
    $user_id_stmt->execute();
    $user_id_result = $user_id_stmt->get_result();
    
    if ($user_id_result->num_rows > 0) {
        $last_user = $user_id_result->fetch_assoc();
        $last_id_num = intval(substr($last_user['user_id'], 4)); // Extract number from "STU-XXX"
        $new_id_num = $last_id_num + 1;
        $new_user_id = 'STU-' . str_pad($new_id_num, 3, '0', STR_PAD_LEFT);
    } else {
        $new_user_id = 'STU-001';
    }
    $user_id_stmt->close();

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert into users table
    $sql = "INSERT INTO users (user_id, username, password, fullname, email, role, status) VALUES (?, ?, ?, ?, ?, 'student', 'active')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $new_user_id, $username, $hashed_password, $fullname, $email);

    if ($stmt->execute()) {
        $user_id = $new_user_id;

        // Log the registration
        error_log("New student registered: {$email} (username: {$username}, user_id: {$user_id})");

        // Create notification
        $notification_message = "New student account created: {$fullname} ({$email})";
        createNotification($user_id, $notification_message, 'registration');

        $stmt->close();
        $conn->close();

        echo json_encode([
            'success' => true,
            'message' => 'Registration successful! Please login with your credentials.'
        ]);
        exit();
    } else {
        throw new Exception("Registration failed. Please try again.");
    }
    
} catch (Exception $e) {
    error_log("Registration error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Registration failed. Please try again.'
    ]);
    exit();
}
?>
