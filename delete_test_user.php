<?php
// Delete user by email to allow re-registration

require_once 'config/database.php';

$email_to_delete = "thesterads@gmail.com";

$conn = getDBConnection();

// Delete the user
$stmt = $conn->prepare("DELETE FROM users WHERE email = ?");
$stmt->bind_param("s", $email_to_delete);

if ($stmt->execute()) {
    echo "User '$email_to_delete' has been deleted.\n";
    echo "You can now register a new account with this email.\n";
} else {
    echo "Error: " . $stmt->error . "\n";
}

$stmt->close();
$conn->close();
?>
