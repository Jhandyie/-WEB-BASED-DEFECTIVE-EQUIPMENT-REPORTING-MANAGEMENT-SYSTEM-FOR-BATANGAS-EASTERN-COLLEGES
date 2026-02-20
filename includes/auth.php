<?php
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.html?error=' . urlencode('Please log in to continue'));
        exit();
    }
}

function requireRole($required_role) {
    requireLogin();
    if ($_SESSION['role'] !== $required_role) {
        header('Location: login.html?error=' . urlencode('Unauthorized access'));
        exit();
    }
}

function hasRole($role) {
    return isLoggedIn() && $_SESSION['role'] === $role;
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