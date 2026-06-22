<?php
// technician/technician_login_process.php
// Handles technician authentication — admin-issued accounts only, no OTP.

require_once __DIR__ . '/../includes/session_bootstrap.php';
startRoleSession('technician');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'verify_login':
        verifyLogin();
        break;
    case 'forgot_password':
        forgotPassword();
        break;
    case 'reset_password':
        resetPassword();
        break;
    case 'check_session':
        checkSession();
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit();
}

/**
 * Verify credentials and create session directly (no OTP).
 */
function verifyLogin() {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = 'technician';

    error_log("technician verifyLogin: $email");

    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        exit();
    }

    try {
        $user = findUserByEmailAndRole($email, $role, ['user_id', 'email', 'fullname', 'username', 'password', 'status', 'role']);
        if (!$user) {
            error_log("technician not found: $email");
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            exit();
        }

        // Account status check
        if (!empty($user['status']) && $user['status'] !== 'active') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Your account is inactive. Please contact your administrator.']);
            exit();
        }

        // Password check
        if (!password_verify($password, $user['password'])) {
            error_log("technician wrong password: $email");
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            exit();
        }

        // Credentials OK — create session immediately (no OTP)
        $_SESSION['user_id']    = $user['user_id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['fullname']   = $user['fullname'];
        $_SESSION['role']       = $user['role'] ?? $role;
        $_SESSION['username']   = $user['username'] ?? '';
        $_SESSION['logged_in']  = true;
        $_SESSION['login_time'] = time();

        // Update last login timestamp
        updateUserLastLogin((string)$user['user_id']);

        error_log("technician login success: $email");
        echo json_encode([
            'success' => true,
            'message' => 'Login successful!',
            'data' => [
                'user_id'  => $user['user_id'],
                'fullname' => $user['fullname'],
                'email'    => $user['email'],
                'redirect' => '../technician_dashboard.php'
            ]
        ]);
        exit();

    } catch (Exception $e) {
        error_log("verifyLogin error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
        exit();
    }
}

/**
 * Send a password-reset link to the given email.
 * Always returns a generic success message to prevent email enumeration.
 */
function forgotPassword() {
    $email = trim($_POST['email'] ?? '');
    $role  = 'technician';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid email address is required.']);
        exit();
    }

    $user = findUserByEmailAndRole($email, $role, ['user_id', 'email']);
    if ($user) {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        createPasswordResetRecord($email, $token, $expires);

        $reset_link = "http://localhost/bec_equipment/technician/reset_password.php?token=" . $token;
        error_log("Password reset link for {$email}: {$reset_link}");
        // TODO: send $reset_link via email (PHPMailer / your mail helper)
    }

    // Generic response regardless of whether the email was found
    echo json_encode([
        'success' => true,
        'message' => 'If that email is registered, a password reset link has been sent.'
    ]);
    exit();
}

/**
 * Reset a password using a valid token.
 */
function resetPassword() {
    $token        = trim($_POST['token']        ?? '');
    $new_password = trim($_POST['new_password'] ?? '');

    if (empty($token) || empty($new_password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Token and new password are required.']);
        exit();
    }

    if (strlen($new_password) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        exit();
    }

    $row = findActivePasswordResetUserByToken($token, 'technician');
    if (!$row) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset link.']);
        exit();
    }

    $user_id = $row['user_id'];

    $hashed = password_hash($new_password, PASSWORD_DEFAULT);

    updateUserPasswordById((string)$user_id, $hashed);
    deletePasswordResetToken($token);

    echo json_encode([
        'success' => true,
        'message' => 'Password reset successful. You can now sign in with your new password.'
    ]);
    exit();
}

/**
 * Check whether the current session is valid.
 */
function checkSession() {
    if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && !empty($_SESSION['user_id'])) {
        echo json_encode([
            'success' => true,
            'data' => [
                'user_id'  => $_SESSION['user_id'],
                'fullname' => $_SESSION['fullname']   ?? '',
                'email'    => $_SESSION['user_email'] ?? '',
                'role'     => $_SESSION['role']       ?? ''
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active session.']);
    }
    exit();
}
