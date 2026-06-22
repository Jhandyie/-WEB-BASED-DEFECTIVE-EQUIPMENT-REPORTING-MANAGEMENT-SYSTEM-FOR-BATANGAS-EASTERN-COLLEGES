<?php
// register_process.php
// User registration handler - Returns JSON responses

session_start();
header('Content-Type: application/json');
require_once 'config/database.php';
require_once 'includes/notification_helper.php';

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
    if (userExistsByUsername($username)) {
        // If username exists, append a number
        $counter = 1;
        $original_username = $username;
        do {
            $username = $original_username . $counter;
            $counter++;
        } while (userExistsByUsername($username));
    }

    // Generate user_id based on role (STU-001 for student, TECH-001 for technician, ADMIN-001 for admin)
    $new_user_id = generateNextRoleUserId($role);

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert into users table with the appropriate role
    if (createUserAccount([
        'user_id' => $new_user_id,
        'username' => $username,
        'password' => $hashed_password,
        'fullname' => $fullname,
        'email' => $email,
        'role' => $role,
        'status' => 'active',
    ])) {
        $user_id = $new_user_id;

        // Log the registration
        error_log("New {$role} registered: {$email} (username: {$username}, user_id: {$user_id})");

        // Create notification
        $notification_message = "New {$role} account created: {$fullname} ({$email})";
        createNotification($user_id, $notification_message, 'registration');

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
