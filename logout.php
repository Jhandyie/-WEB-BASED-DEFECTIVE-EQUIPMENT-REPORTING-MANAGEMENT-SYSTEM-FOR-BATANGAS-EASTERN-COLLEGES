<?php
require_once __DIR__ . '/includes/session_bootstrap.php';

$role = 'student';

// Try to infer role from existing role-scoped sessions.
foreach (['admin', 'technician', 'student', 'main'] as $ctx) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    startRoleSession($ctx);
    if (!empty($_SESSION['role'])) {
        $role = $_SESSION['role'];
    }
    $_SESSION = [];
    session_unset();
    session_destroy();

    $name = session_name();
    setcookie($name, '', time() - 3600, '/');
}

switch ($role) {
    case 'admin':
        header('Location: admin/login.html?success=' . urlencode('Logged out successfully'));
        break;
    case 'handler':
        header('Location: handler/login.html?success=' . urlencode('Logged out successfully'));
        break;
    case 'technician':
        header('Location: technician/login.html?success=' . urlencode('Logged out successfully'));
        break;
    default:
        header('Location: student/login.html?success=' . urlencode('Logged out successfully'));
        break;
}
exit();
?>
