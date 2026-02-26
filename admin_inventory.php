<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure auth redirect happens before any HTML output from included files
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: login.html?error=' . urlencode('Please log in to continue'));
    exit();
}

require_once __DIR__ . '/inventory_functions.php';
exit();
