<?php
// Test registration
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['role'] = 'admin';
$_POST['fullname'] = 'Test Admin User';
$_POST['email'] = 'thesterads@gmail.com';
$_POST['password'] = 'test123';

echo "=== TESTING ADMIN REGISTRATION ===\n";
echo "Role: " . $_POST['role'] . "\n";
echo "Email: " . $_POST['email'] . "\n";
echo "Fullname: " . $_POST['fullname'] . "\n\n";

// Include the registration
ob_start();
include 'register_process.php';
$output = ob_get_clean();

echo "Result: $output\n";
?>
