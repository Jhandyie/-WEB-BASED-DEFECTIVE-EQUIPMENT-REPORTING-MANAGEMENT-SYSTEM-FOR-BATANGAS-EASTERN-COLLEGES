<?php
// Test SMTP email directly
require_once 'includes/mail_helper.php';

$settings = getEmailSettingsByRole('admin');
echo "Settings: " . json_encode($settings, JSON_PRETTY_PRINT) . "\n\n";

$result = sendEmail(
    'thesterads@gmail.com', 
    'Test Email from BEC', 
    '<h1>Test</h1><p>This is a test email.</p>',
    $settings,
    'admin'
);

echo "\nEmail result: " . ($result ? "SUCCESS" : "FAILED");
?>
