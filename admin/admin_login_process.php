<?php
// admin/admin_login_process.php
// Main PHP processor for admin login, OTP, and authentication

require_once __DIR__ . '/../includes/session_bootstrap.php';
startRoleSession('admin');

// Keep API responses as valid JSON even when warnings occur.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
// Reflect the caller's origin (same-origin app) instead of an invalid
// wildcard-with-credentials combination.
$__origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($__origin !== '') {
    header('Access-Control-Allow-Origin: ' . $__origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include required files - only database and OTP helper
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/otp_helper.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/rate_limiter.php';
require_once __DIR__ . '/../includes/csrf.php';

// Get the action from POST or GET
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Hand the login page a CSRF token (safe GET, no state change).
if ($action === 'get_csrf') {
    echo json_encode(['success' => true, 'token' => csrf_token()]);
    exit();
}

// Enforce CSRF on every state-changing POST action below.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf(true);
}

// Route the request
switch ($action) {
    case 'verify_login':
        verifyLogin();
        break;
    case 'verify_otp':
        verifyOTPHandler();
        break;
    case 'resend_otp':
        resendOTP();
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
 * Verify login credentials and send OTP
 */
function verifyLogin() {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = 'admin';

    // Validate inputs
    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email and password are required']);
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit();
    }

    // Throttle brute-force: max 6 password attempts per IP+email per 15 minutes.
    try {
        RateLimiter::enforce('admin_login:' . RateLimiter::clientIp() . ':' . strtolower($email), 6, 900);
    } catch (Exception $__rl) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => $__rl->getMessage()]);
        exit();
    }

    try {
        $user = findUserByEmailAndRole($email, $role, ['user_id', 'email', 'fullname', 'password', 'status']);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
            exit();
        }

        // Check if account is active
        if (isset($user['status']) && $user['status'] !== 'active') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Your account is inactive. Please contact support.']);
            exit();
        }

        // Verify password
        if (!password_verify($password, $user['password'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
            exit();
        }

        // Password is correct — clear the brute-force counter and request OTP
        RateLimiter::clear('admin_login:' . RateLimiter::clientIp() . ':' . strtolower($email));
        $_SESSION['temp_user_id'] = $user['user_id'];
        $_SESSION['temp_user_email'] = $user['email'];
        $_SESSION['temp_user_name'] = $user['fullname'];

        // Generate and send OTP
        $otp_result = requestLoginOTP($email, $role);

        if (!$otp_result['success']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $otp_result['message'] ?? 'Failed to send OTP. Please try again.'
            ]);
            exit();
        }

        echo json_encode([
            'success' => true,
            'message' => ($otp_result['message'] ?? 'OTP sent successfully. Please check your email.'),
            'data' => [
                'email' => $email,
                'require_otp' => true
            ]
        ]);
        exit();

    } catch (Exception $e) {
        error_log("Admin login verification error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
        exit();
    }
}

/**
 * Verify OTP and create session
 */
function verifyOTPHandler() {
    $email = trim($_POST['email'] ?? '');
    $otp_code = trim($_POST['otp_code'] ?? '');
    $role = 'admin';

    if (empty($email) || empty($otp_code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email and OTP code are required']);
        exit();
    }

    if (strlen($otp_code) !== 6 || !is_numeric($otp_code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid OTP format']);
        exit();
    }

    // Throttle OTP guessing: max 8 attempts per IP+email per 15 minutes.
    try {
        RateLimiter::enforce('admin_otp:' . RateLimiter::clientIp() . ':' . strtolower($email), 8, 900);
    } catch (Exception $__rl) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => $__rl->getMessage()]);
        exit();
    }

    // Verify OTP
    $result = verifyOTP($email, $otp_code, $role);

    if (!$result['success']) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Invalid or expired OTP'
        ]);
        exit();
    }

    // OTP verified — clear throttle counters and create session
    RateLimiter::clear('admin_otp:' . RateLimiter::clientIp() . ':' . strtolower($email));
    $user = $result['user'];

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['fullname'] = $user['fullname'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['username'] = $user['username'] ?? '';
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();

    // Clear temporary session data
    unset($_SESSION['temp_user_id']);
    unset($_SESSION['temp_user_email']);
    unset($_SESSION['temp_user_name']);

    // Update last login
    updateUserLastLogin((string)$user['user_id']);
    logActivity((string)$user['user_id'], 'auth.login', 'Admin login (2FA) for ' . ($user['email'] ?? ''));

    echo json_encode([
        'success' => true,
        'message' => 'Login successful!',
        'data' => [
            'user_id' => $user['user_id'],
            'fullname' => $user['fullname'],
            'email' => $user['email'],
            'redirect' => '../admin_dashboard.php'
        ]
    ]);
    exit();
}

/**
 * Resend OTP
 */
function resendOTP() {
    $email = trim($_POST['email'] ?? '');
    $role = 'admin';

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit();
    }

    // Anti-abuse: resend needs no password, so cap it hard — both per requester and per target
    // inbox — to stop OTP email bombing of the admin.
    try {
        RateLimiter::enforce('admin_resend:' . RateLimiter::clientIp(), 4, 900);
        RateLimiter::enforce('admin_resend_mail:' . strtolower($email), 5, 900);
    } catch (Exception $e) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }

    // Only resend when a login attempt for this email is actually pending OTP in this session —
    // a stranger poking the endpoint directly never triggers an email.
    if (strtolower((string)($_SESSION['temp_user_email'] ?? '')) !== strtolower($email)) {
        echo json_encode(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);
        exit();
    }

    $user = findUserByEmailAndRole($email, $role, ['user_id', 'email', 'fullname']);
    if (!$user) {
        echo json_encode(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);
        exit();
    }

    // Request new OTP
    $otp_result = requestLoginOTP($email, $role);

    if (!$otp_result['success']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $otp_result['message'] ?? 'Failed to send OTP. Please try again.'
        ]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => ($otp_result['message'] ?? 'New OTP sent successfully. Please check your email.')
    ]);
    exit();
}

/**
 * Handle forgot password
 */
function forgotPassword() {
    $email = trim($_POST['email'] ?? '');
    $role = 'admin';

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit();
    }

    // Anti-abuse: cap reset requests so a stranger can't bombard the admin's inbox.
    try {
        RateLimiter::enforce('admin_forgot:' . RateLimiter::clientIp(), 3, 900);
        RateLimiter::enforce('admin_forgot_mail:' . strtolower($email), 3, 900); // per-target cap, any IP
    } catch (Exception $e) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }

    $user = findUserByEmailAndRole($email, $role, ['user_id', 'email', 'fullname']);
    if (!$user) {
        echo json_encode([
            'success' => true,
            'message' => 'If this email is registered, a password reset link has been sent.'
        ]);
        exit();
    }

    // Generate password reset token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Store token in database
    createPasswordResetRecord($email, $token, $expires);

    // Build an absolute reset link from the actual request (works on any host/path).
    $__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__appBase = $__scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
    $reset_link = $__appBase . "/admin/reset_password.php?token=" . $token;
    error_log("Admin password reset link for {$email}: " . $reset_link);

    // Actually deliver the link (previously it was only written to the error log).
    require_once __DIR__ . '/../includes/mail_helper.php';
    try { sendPasswordResetEmail($email, $reset_link); }
    catch (\Throwable $e) { error_log('admin reset email failed: ' . $e->getMessage()); }

    echo json_encode([
        'success' => true,
        'message' => 'If this email is registered, a password reset link has been sent.'
    ]);
    exit();
}

/**
 * Reset password with token
 */
function resetPassword() {
    $token = trim($_POST['token'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');

    if (empty($token) || empty($new_password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Token and new password are required']);
        exit();
    }

    if (strlen($new_password) < 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters']);
        exit();
    }

    // Defence-in-depth: cap reset submissions per IP (tokens are already 256-bit random).
    try { RateLimiter::enforce('admin_reset:' . RateLimiter::clientIp(), 10, 900); }
    catch (Exception $e) { http_response_code(429); echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit(); }

    $row = findActivePasswordResetUserByToken($token, 'admin');
    if (!$row) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset token']);
        exit();
    }

    $user_id = $row['user_id'];

    // Update password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    updateUserPasswordById((string)$user_id, $hashed_password);

    // Delete token
    deletePasswordResetToken($token);

    echo json_encode([
        'success' => true,
        'message' => 'Password reset successful. You can now login with your new password.'
    ]);
    exit();
}

/**
 * Check if session is valid
 */
function checkSession() {
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => true,
            'data' => [
                'user_id' => $_SESSION['user_id'],
                'fullname' => $_SESSION['fullname'] ?? '',
                'email' => $_SESSION['user_email'] ?? '',
                'role' => $_SESSION['role'] ?? ''
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active session']);
    }
    exit();
}

?>
