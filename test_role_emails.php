<?php
// Test role-based email settings
require_once 'includes/mail_helper.php';

// Test all three roles
$roles = ['admin', 'student', 'technician'];

echo "=== Testing Role-Based Email Settings ===\n\n";

foreach ($roles as $role) {
    echo "Testing role: $role\n";
    $settings = getEmailSettingsByRole($role);
    echo "  SMTP Username: " . ($_username'] ?? 'settings['smtpNOT SET') . "\n";
    echo "  From Email: " . ($settings['from_email'] ?? 'NOT SET') . "\n";
    echo "  From Name: " . ($settings['from_name'] ?? 'NOT SET') . "\n";
    echo "\n";
}

// Send test emails to verify all roles work
echo "=== Sending Test Emails ===\n\n";

$test_emails = [
    'admin' => 'thesterads@gmail.com',
    'student' => 'jhanmark_decastro@bec.edu.ph',
    'technician' => 'technician9123@gmail.com'
];

foreach ($roles as $role) {
    echo "Sending test email to {$test_emails[$role]} using $role email...\n";
    $settings = getEmailSettingsByRole($role);
    
    $result = sendEmail(
        $test_emails[$role],
        "Test $role Email from BEC System",
        "<h1>Test Email</h1><p>This is a test email sent using the $role SMTP settings.</p>",
        $settings,
        $role
    );
    
    echo "  Result: " . ($result ? "SUCCESS" : "FAILED") . "\n\n";
}

echo "=== Test Complete ===\n";
?>
