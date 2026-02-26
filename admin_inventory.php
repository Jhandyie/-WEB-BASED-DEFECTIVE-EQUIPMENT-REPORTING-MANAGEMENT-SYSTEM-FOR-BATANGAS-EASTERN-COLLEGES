<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
}

require_once __DIR__ . '/includes/auth.php';
requireRole('admin');

require_once __DIR__ . '/inventory_functions.php';
exit();

