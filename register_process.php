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

// Get inputs safely - allow role from POST (admin, technician or student)
$role = trim($_POST['role'] ?? 'student');
// Validate role - allow admin, student or technician
if (!in_array($role, ['admin', 'student', 'technician'])) {
    $role = 'student';
}
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

// Email duplicate check removed - allowing duplicate emails for all roles

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

    // Generate user_id based on role (STU-001 for student, TECH-001 for technician, ADMIN-001 for admin)
    if ($role === 'admin') {
        $user_id_stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'admin' AND user_id LIKE 'ADMIN-%' ORDER BY CAST(SUBSTRING(user_id, 7) AS UNSIGNED) DESC LIMIT 1");
    } elseif ($role === 'technician') {
        $user_id_stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'technician' AND user_id LIKE 'TECH-%' ORDER BY CAST(SUBSTRING(user_id, 6) AS UNSIGNED) DESC LIMIT 1");
    } else {
        $user_id_stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'student' AND user_id LIKE 'STU-%' ORDER BY CAST(SUBSTRING(user_id, 5) AS UNSIGNED) DESC LIMIT 1");
    }
    $user_id_stmt->execute();
    $user_id_result = $user_id_stmt->get_result();
    
    if ($user_id_result->num_rows > 0) {
        $last_user = $user_id_result->fetch_assoc();
        if ($role === 'admin') {
            $last_id_num = intval(substr($last_user['user_id'], 6)); // Extract number from "ADMIN-XXX"
            $new_id_num = $last_id_num + 1;
            $new_user_id = 'ADMIN-' . str_pad($new_id_num, 3, '0', STR_PAD_LEFT);
        } elseif ($role === 'technician') {
            $last_id_num = intval(substr($last_user['user_id'], 5)); // Extract number from "TECH-XXX"
            $new_id_num = $last_id_num + 1;
            $new_user_id = 'TECH-' . str_pad($new_id_num, 3, '0', STR_PAD_LEFT);
        } else {
            $last_id_num = intval(substr($last_user['user_id'], 4)); // Extract number from "STU-XXX"
            $new_id_num = $last_id_num + 1;
            $new_user_id = 'STU-' . str_pad($new_id_num, 3, '0', STR_PAD_LEFT);
        }
    } else {
        if ($role === 'admin') {
            $new_user_id = 'ADMIN-001';
        } elseif ($role === 'technician') {
            $new_user_id = 'TECH-001';
        } else {
            $new_user_id = 'STU-001';
        }
    }
    $user_id_stmt->close();

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert into users table with the appropriate role
    $sql = "INSERT INTO users (user_id, username, password, fullname, email, role, status) VALUES (?, ?, ?, ?, ?, ?, 'active')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssss", $new_user_id, $username, $hashed_password, $fullname, $email, $role);

    if ($stmt->execute()) {
        $user_id = $new_user_id;

        // Log the registration
        error_log("New {$role} registered: {$email} (username: {$username}, user_id: {$user_id})");

        // Create notification
        $notification_message = "New {$role} account created: {$fullname} ({$email})";
        createNotification($user_id, $notification_message, 'registration');

        $stmt->close();

        echo json_encode([
            'success' => true,
            'message' => 'Registration successful! Please login with your credentials.'
        ]);
        exit();
    } else {
        throw new Exception("Registration failed. Please try again.");
    }
    
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    error_log("Registration error: " . $errorMsg);
    
    // Check for duplicate email error
    if (strpos($errorMsg, 'Duplicate entry') !== false || strpos($errorMsg, 'email') !== false) {
        echo json_encode([
            'success' => false,
            'message' => 'This email is already registered. Please use a different email or try logging in.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Registration failed. Please try again. Error: ' . $errorMsg
        ]);
    }
    exit();
}
?>
