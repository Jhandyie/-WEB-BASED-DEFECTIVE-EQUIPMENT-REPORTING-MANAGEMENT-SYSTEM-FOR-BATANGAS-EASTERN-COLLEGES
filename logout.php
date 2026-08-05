<?php
require_once __DIR__ . '/includes/session_bootstrap.php';

// Prevent the browser from showing a cached authenticated page after logout (back button).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

$role = '';

// Destroy every role-scoped session cleanly; remember the role we logged out from.
foreach (['admin', 'technician', 'student', 'main'] as $ctx) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    @startRoleSession($ctx);

    if ($role === '' && !empty($_SESSION['role'])) {
        $role = $_SESSION['role'];
    }

    $name = session_name();
    $_SESSION = [];
    @session_unset();
    @session_destroy();

    // Expire the session cookie (match the path used when it was set).
    $params = session_get_cookie_params();
    setcookie($name, '', [
        'expires'  => time() - 3600,
        'path'     => $params['path'] ?: '/',
        'domain'   => $params['domain'] ?? '',
        'secure'   => $params['secure'] ?? false,
        'httponly' => $params['httponly'] ?? true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

$success = urlencode('Logged out successfully');
switch ($role) {
    case 'admin':
        header('Location: admin/admin_login_otp.html?success=' . $success);
        break;
        header('Location: handler/login.html?success=' . $success);
        break;
    case 'technician':
        header('Location: technician/login.html?success=' . $success);
        break;
    default:
        // Student/guest reporters use the universal reporter portal (no separate login page).
        header('Location: student_index.php?success=' . $success);
        break;
}
exit();
