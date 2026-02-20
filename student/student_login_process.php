<?php
// student/student_login_process.php
// Main PHP processor for student login, OTP, and authentication

session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include required files - only database and OTP helper
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/otp_helper.php';

// Get the action from POST or GET
$action = $_POST['action'] ?? $_GET['action'] ?? '';

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
    $role = 'student';

    // DEBUG: Log the incoming request
    error_log("DEBUG: verifyLogin called with email: $email");

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

    $conn = getDBConnection();

    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit();
    }

    try {
        // Check if user exists with this email and is a student
        // Order by id DESC to get the most recently created user first (for duplicate emails)
        $stmt = $conn->prepare("SELECT id, user_id, email, fullname, password, status FROM users WHERE email = ? AND role = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("ss", $email, $role);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            error_log("DEBUG: User not found for email: $email");
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
            $stmt->close();
            $conn->close();
            exit();
        }

        $user = $result->fetch_assoc();
        $stmt->close();
        error_log("DEBUG: User found: " . $user['fullname'] . " status: " . $user['status']);

        // Check if account is active
        if (isset($user['status']) && $user['status'] !== 'active') {
            error_log("DEBUG: Account not active for email: $email, status: " . $user['status']);
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Your account is inactive. Please contact support.']);
            $conn->close();
            exit();
        }

        // Verify password
        $passwordVerifyResult = password_verify($password, $user['password']);
        error_log("DEBUG: Password verify result: " . ($passwordVerifyResult ? "true" : "false"));
        
        if (!$passwordVerifyResult) {
            error_log("DEBUG: Password incorrect for email: $email");
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
            $conn->close();
            exit();
        }

        // Password is correct - now request OTP
        $_SESSION['temp_user_id'] = $user['user_id'];
        $_SESSION['temp_user_email'] = $user['email'];
        $_SESSION['temp_user_name'] = $user['fullname'];

        // Generate and send OTP
        $otp_result = requestLoginOTP($email, $role);
        error_log("DEBUG: OTP result: " . json_encode($otp_result));

        if (!$otp_result['success']) {
            http_response_code(400);
            echo json_encode([
                'success' => false, 
                'message' => $otp_result['message'] ?? 'Failed to send OTP. Please try again.'
            ]);
            $conn->close();
            exit();
        }

        $conn->close();
        error_log("DEBUG: Login successful for email: $email, returning success");
        echo json_encode([
            'success' => true,
            'message' => 'OTP sent successfully. Please check your email.',
            'data' => [
                'email' => $email,
                'require_otp' => true
            ]
        ]);
        exit();

    } catch (Exception $e) {
        error_log("Login verification error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
        exit();
    }
}

/**
 * Verify OTP and create session
 */
function verifyOTPHandler() {
    // Debug: Log what we're receiving
    error_log("verifyOTPHandler called with: email=" . ($_POST['email'] ?? 'NOT SET') . ", otp_code=" . ($_POST['otp_code'] ?? 'NOT SET'));
    
    $email = trim($_POST['email'] ?? '');
    $otp_code = trim($_POST['otp_code'] ?? '');
    $role = 'student';

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

    // Debug: Log the email being used for verification
    error_log("Verifying OTP for email: " . $email . " with code: " . $otp_code);
    
    // Verify OTP
    $result = verifyOTP($email, $otp_code, $role);
    
    // Debug: Log the result
    error_log("verifyOTP result: " . json_encode($result));

    if (!$result['success']) {
        http_response_code(401);
        echo json_encode([
            'success' => false, 
            'message' => $result['message'] ?? 'Invalid or expired OTP'
        ]);
        exit();
    }

    // OTP verified - create session
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
    $conn = getDBConnection();
    if ($conn) {
        $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
        $stmt->bind_param("s", $user['user_id']);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Login successful!',
        'data' => [
            'user_id' => $user['user_id'],
            'fullname' => $user['fullname'],
            'email' => $user['email'],
            'redirect' => '../student_dashboard.php'
        ]
    ]);
    exit();
}

/**
 * Resend OTP
 */
function resendOTP() {
    $email = trim($_POST['email'] ?? '');
    $role = 'student';

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

    $conn = getDBConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit();
    }

    $stmt = $conn->prepare("SELECT user_id, email, fullname FROM users WHERE email = ? AND role = ? LIMIT 1");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => true, 'message' => 'If this email is registered, an OTP has been sent.']);
        $stmt->close();
        $conn->close();
        exit();
    }

    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

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
        'message' => 'New OTP sent successfully. Please check your email.'
    ]);
    exit();
}

/**
 * Handle forgot password
 */
function forgotPassword() {
    $email = trim($_POST['email'] ?? '');
    $role = 'student';

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

    $conn = getDBConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit();
    }

    $stmt = $conn->prepare("SELECT user_id, email, fullname FROM users WHERE email = ? AND role = ? LIMIT 1");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => true, 
            'message' => 'If this email is registered, a password reset link has been sent.'
        ]);
        $stmt->close();
        $conn->close();
        exit();
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // Generate password reset token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Store token in database
    $stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $email, $token, $expires);
    $stmt->execute();
    $stmt->close();

    // Log the reset link
    $reset_link = "http://localhost/bec_equipment/student/reset_password.php?token=" . $token;
    error_log("Password reset link for {$email}: " . $reset_link);

    $conn->close();

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

    if (strlen($new_password) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
        exit();
    }

    $conn = getDBConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit();
    }

    // Find valid token
    $stmt = $conn->prepare("
        SELECT pr.user_id, u.email 
        FROM password_resets pr
        JOIN users u ON pr.email = u.email
        WHERE pr.token = ? AND pr.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset token']);
        $stmt->close();
        $conn->close();
        exit();
    }

    $row = $result->fetch_assoc();
    $user_id = $row['user_id'];
    $email = $row['email'];
    $stmt->close();

    // Update password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
    $stmt->bind_param("ss", $hashed_password, $user_id);
    $stmt->execute();
    $stmt->close();

    // Delete token
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->close();

    $conn->close();

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


