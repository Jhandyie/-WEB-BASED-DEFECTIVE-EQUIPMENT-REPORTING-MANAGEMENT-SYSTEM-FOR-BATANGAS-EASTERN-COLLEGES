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

/*
 * Role — nobody self-registers into a staff account any more.
 *
 * This used to accept role=admin, and the admin sign-in page shipped a Sign Up
 * form that posted exactly that. There was no domain check here or at login, no
 * approval step and no rate limit, and the account was created 'active' — so
 * anyone who could reach the page could make themselves a PMO administrator
 * with any address they liked, and the login code was then mailed to them. An
 * admin account reaches every report, the whole BEC directory, user management
 * and the database backups.
 *
 * Staff accounts (admin and technician alike) are created by an existing
 * administrator in admin_users.php, which has done so all along.
 *
 * Reporters (students, teachers, staff, janitors) do not register at all: they
 * use the reporter portal with their BEC identity and an emailed code.
 *
 * Nothing self-registers now, so this endpoint refuses every role and creates
 * nothing. It is kept, rather than deleted, so a stale bookmark or a cached
 * page gets a clear answer instead of a 404, and so this note stays with the
 * history. The code below it is left intact for the same reason.
 */
require_once __DIR__ . '/includes/rate_limiter.php';
try {
    RateLimiter::enforce('register:' . RateLimiter::clientIp(), 12, 900);
} catch (\Throwable $e) {
    // Answer the same way either way; a different reply here is a probe oracle.
}
echo json_encode([
    'success' => false,
    'message' => 'Accounts are not self-registered. Property Management Office staff and technicians are set up by an administrator; students, teachers and staff report equipment through the reporter portal with their BEC email.'
]);
exit();

/*
 * Unreachable from here down — see the note above.
 */
$role = 'student';

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
