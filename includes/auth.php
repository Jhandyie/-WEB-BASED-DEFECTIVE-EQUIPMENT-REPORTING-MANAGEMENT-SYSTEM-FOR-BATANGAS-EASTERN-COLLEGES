<?php
require_once __DIR__ . '/session_bootstrap.php';
startRoleSession('auto');
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

function roleAliases() {
    return [
        // Student dashboard is shared with faculty/staff in this project.
        'student' => ['student', 'faculty', 'staff'],
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
        header('Location: login.html?error=' . urlencode('Please log in to continue'));
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
        header('Location: login.html?error=' . urlencode('Unauthorized access'));
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




