<?php
require_once __DIR__ . '/session_bootstrap.php';
startRoleSession('auto');

function loginPageForCurrentRequest() {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = basename($script);

    if (strpos($base, 'pmo_') === 0) {
        return 'admin/admin_login_otp.html';
    }
    if (strpos($base, 'dean_') === 0) {
        return 'admin/admin_login_otp.html';
    }
    if (strpos($base, 'finance_') === 0) {
        return 'admin/admin_login_otp.html';
    }
    if (strpos($script, '/admin/') !== false) {
        return 'admin_login_otp.html';
    }
    if (strpos($base, 'admin_') === 0) {
        return 'admin/admin_login_otp.html';
    }
    if (strpos($script, '/technician/') !== false) {
        return 'login.html';
    }
    if (strpos($base, 'technician_') === 0) {
        return 'technician/login.html';
    }

    return 'login.html';
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

function roleAliases() {
    return [
        // Student dashboard is shared with faculty/staff in this project.
        // Reporter covers everyone who files: student, teacher, office staff.
        // Dean, Finance, PMO and Handler are gone — see the note in
        // scripts/2026_08_drop_dead_roles.sql.
        'reporter' => ['reporter', 'student', 'faculty', 'staff'],
    ];
}

function allowedRoles($required_role) {
    $required = is_array($required_role) ? $required_role : [$required_role];
    $aliases = roleAliases();
    $allowed = [];

    foreach ($required as $role) {
        if (isset($aliases[$role])) {
            $allowed = array_merge($allowed, $aliases[$role]);
        } else {
            $allowed[] = $role;
        }
    }

    return array_values(array_unique($allowed));
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . loginPageForCurrentRequest() . '?error=' . urlencode('Please log in to continue'));
        exit();
    }
}

function requireRole($required_role) {
    requireLogin();
    // Admin can access all protected dashboards/pages.
    if (($_SESSION['role'] ?? '') === 'admin') {
        return;
    }
    if (!in_array($_SESSION['role'], allowedRoles($required_role), true)) {
        header('Location: ' . loginPageForCurrentRequest() . '?error=' . urlencode('Unauthorized access'));
        exit();
    }
}

function hasRole($role) {
    return isLoggedIn() && in_array($_SESSION['role'], allowedRoles($role), true);
}

function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getUserRole() {
    return $_SESSION['role'] ?? null;
}

function getUserName() {
    return $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'User';
}

function isGuest() {
    return isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true;
}
?>




