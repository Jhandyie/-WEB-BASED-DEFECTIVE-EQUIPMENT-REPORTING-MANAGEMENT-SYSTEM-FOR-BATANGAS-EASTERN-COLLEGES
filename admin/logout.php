<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
startRoleSession('admin');
$_SESSION = [];
session_unset();
session_destroy();
setcookie(session_name(), '', time() - 3600, '/');
header('Location: login.html?success=' . urlencode('Logged out successfully'));
exit();
?>
