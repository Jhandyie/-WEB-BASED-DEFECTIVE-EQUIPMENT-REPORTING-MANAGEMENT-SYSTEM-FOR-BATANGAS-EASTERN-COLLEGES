<?php
session_start();

// Get the user's role before destroying the session
$role = $_SESSION['role'] ?? 'student';

session_unset();
session_destroy();

// Redirect based on role
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
        // Student or unknown role
        header('Location: student/login.html?success=' . urlencode('Logged out successfully'));
        break;
}
exit();
?>
