<?php
// register_process.php
// User registration handler - Returns JSON responses

require_once __DIR__ . '/includes/session_bootstrap.php';
startPublicSession();
header('Content-Type: application/json');
require_once 'config/database.php';
require_once 'includes/notification_helper.php';
require_once 'includes/audit.php';
require_once __DIR__ . '/includes/csrf.php';

// Hand the registration form a CSRF token (safe GET, no state change).
if ((($_GET['action'] ?? $_POST['action'] ?? '')) === 'get_csrf') {
    echo json_encode(['success' => true, 'token' => csrf_token()]);
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

// Enforce CSRF on registration submissions.
requireCsrf(true);

// Role — only Administrator (PMO) self-registers here.
// Reporters (students, teachers, staff, janitors, etc.) use the universal
// reporter portal (student_index.php) with their BEC identity — no separate
// faculty login. Technician accounts are created by the Administrator.
$role = trim($_POST['role'] ?? 'student');
if (!in_array($role, ['student', 'admin'], true)) {
    // Reject roles that must be provisioned internally or have no self-signup.
    if (in_array($role, ['technician'], true)) {
        echo json_encode([
            'success' => false,
            'message' => 'This account type cannot be self-registered. Please contact the Property Management Office.'
        ]);
        exit();
    }
    $role = 'student';
}

// Inputs (spec fields)
$fullname        = trim($_POST['fullname'] ?? '');
$email           = strtolower(trim($_POST['email'] ?? ''));
$password        = (string)($_POST['password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? $_POST['password_confirm'] ?? '');
$schoolId        = trim($_POST['school_id'] ?? '');
$department      = trim($_POST['department'] ?? '');
$course          = trim($_POST['course'] ?? '');
$contactNumber   = trim($_POST['contact_number'] ?? $_POST['phone'] ?? '');

// Validate inputs
$errors = [];

if ($fullname === '' || strlen($fullname) < 2) {
    $errors[] = "Full name is required.";
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "A valid email address is required.";
}

// Password policy: min 8 chars, at least one letter and one number.
if (strlen($password) < 8) {
    $errors[] = "Password must be at least 8 characters long.";
} elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
    $errors[] = "Password must contain at least one letter and one number.";
}

// Confirm password must match.
if ($confirmPassword === '' || $password !== $confirmPassword) {
    $errors[] = "Passwords do not match.";
}

// Contact number — optional but, if given, must look like a phone number.
if ($contactNumber !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $contactNumber)) {
    $errors[] = "Please enter a valid contact number.";
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit();
}

// Enforce unique email (re-enabled per spec).
try {
    if (userExistsByEmail($email)) {
        echo json_encode([
            'success' => false,
            'message' => 'This email is already registered. Please log in or use a different email.'
        ]);
        exit();
    }
} catch (Throwable $e) {
    error_log('Email uniqueness check failed: ' . $e->getMessage());
}

try {
    // Generate a unique username from the email local-part.
    $username = explode('@', $email)[0];
    if (userExistsByUsername($username)) {
        $counter = 1;
        $base = $username;
        do {
            $username = $base . $counter;
            $counter++;
        } while (userExistsByUsername($username));
    }

    $new_user_id = generateNextRoleUserId($role);
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $accountData = [
        'user_id'  => $new_user_id,
        'username' => $username,
        'password' => $hashed_password,
        'fullname' => $fullname,
        'email'    => $email,
        'role'     => $role,
        'status'   => 'active',
    ];
    if ($contactNumber !== '') { $accountData['phone'] = $contactNumber; }
    if ($department !== '')    { $accountData['department'] = $department; }
    if ($schoolId !== '')      { $accountData['school_id'] = $schoolId; }
    if ($course !== '')        { $accountData['course'] = $course; }

    if (createUserAccount($accountData)) {
        error_log("New {$role} registered: {$email} (username: {$username}, user_id: {$new_user_id})");
        logActivity($new_user_id, 'user.register', "New {$role} account created: {$fullname} <{$email}>");

        $notification_message = "New {$role} account created: {$fullname} ({$email})";
        createNotification($new_user_id, $notification_message, 'registration');

        echo json_encode([
            'success' => true,
            'message' => 'Registration successful! Please login with your credentials.'
        ]);
        exit();
    }

    throw new Exception("Registration failed. Please try again.");

} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    error_log("Registration error: " . $errorMsg);

    if ((stripos($errorMsg, 'duplicate') !== false || stripos($errorMsg, 'unique') !== false)
        && stripos($errorMsg, 'email') !== false) {
        echo json_encode([
            'success' => false,
            'message' => 'This email is already registered. Please use a different email or try logging in.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Registration failed. Please try again.'
        ]);
    }
    exit();
}
