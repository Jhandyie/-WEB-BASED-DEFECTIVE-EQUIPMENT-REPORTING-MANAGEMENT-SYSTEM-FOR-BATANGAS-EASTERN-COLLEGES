<?php
// debug_registration.php - Debug registration issues

session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

$conn = getDBConnection();

if (!$conn) {
    echo json_encode(array('success' => false, 'message' => 'Database connection failed'));
    exit();
}

// Only handle POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(array('success' => false, 'message' => 'Invalid request method'));
    exit();
}

// Get inputs safely
$role = 'student';
$fullname = trim($_POST['fullname'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');

// Log received data
error_log("DEBUG: Received registration request - Name: $fullname, Email: $email");

// Validate inputs
$errors = array();

if (empty($fullname)) {
    $errors[] = "Full name is required.";
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Valid email address is required.";
}

if (empty($password) || strlen($password) < 6) {
    $errors[] = "Password must be at least 6 characters long.";
}

if (count($errors) > 0) {
    echo json_encode(array('success' => false, 'errors' => $errors));
    exit();
}

// Check if email already exists
$email_check_sql = "SELECT user_id, email FROM users WHERE email = ?";
$email_check_stmt = $conn->prepare($email_check_sql);
$email_check_stmt->bind_param("s", $email);
$email_check_stmt->execute();
$email_result = $email_check_stmt->get_result();
if ($email_result->num_rows > 0) {
    $email_check_stmt->close();
    echo json_encode(array('success' => false, 'message' => 'Email already exists'));
    exit();
}
$email_check_stmt->close();

try {
    // Generate username from email
    $username = explode('@', $email)[0];

    // Ensure username is unique
    $username_check_sql = "SELECT user_id FROM users WHERE username = ?";
    $username_check_stmt = $conn->prepare($username_check_sql);
    $username_check_stmt->bind_param("s", $username);
    $username_check_stmt->execute();
    if ($username_check_stmt->get_result()->num_rows > 0) {
        $counter = 1;
        $original_username = $username;
        do {
            $username = $original_username . $counter;
            $username_check_stmt->bind_param("s", $username);
            $username_check_stmt->execute();
            $counter++;
        } while ($username_check_stmt->get_result()->num_rows > 0);
    }
    $username_check_stmt->close();

    // Generate user_id
    $user_id_stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'student' AND user_id LIKE 'STU-%' ORDER BY CAST(SUBSTRING(user_id, 5) AS UNSIGNED) DESC LIMIT 1");
    $user_id_stmt->execute();
    $user_id_result = $user_id_stmt->get_result();
    
    if ($user_id_result->num_rows > 0) {
        $last_user = $user_id_result->fetch_assoc();
        $last_id_num = intval(substr($last_user['user_id'], 4));
        $new_id_num = $last_id_num + 1;
        $new_user_id = 'STU-' . str_pad($new_id_num, 3, '0', STR_PAD_LEFT);
    } else {
        $new_user_id = 'STU-001';
    }
    $user_id_stmt->close();

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert into users table
    $sql = "INSERT INTO users (user_id, username, password, fullname, email, role, status) VALUES (?, ?, ?, ?, ?, 'student', 'active')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $new_user_id, $username, $hashed_password, $fullname, $email);

    if ($stmt->execute()) {
        $insert_id = $conn->insert_id;
        $user_id = $new_user_id;
        
        error_log("DEBUG: Registration successful - ID: $insert_id, UserID: $user_id, Email: $email");
        
        // Verify the insert
        $verify_sql = "SELECT * FROM users WHERE user_id = ?";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("s", $user_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            $user_data = $verify_result->fetch_assoc();
            error_log("DEBUG: Verified user in database - ID: " . $user_data['id'] . ", Email: " . $user_data['email']);
            echo json_encode(array(
                'success' => true, 
                'message' => 'Registration successful',
                'user_id' => $user_id,
                'email' => $email
            ));
        } else {
            error_log("ERROR: User not found after insert!");
            echo json_encode(array('success' => false, 'message' => 'Registration failed - user not found after insert'));
        }
        
        $verify_stmt->close();
    } else {
        $error = $stmt->error;
        error_log("DEBUG: Registration failed - Error: $error");
        echo json_encode(array('success' => false, 'message' => 'Registration failed: ' . $error));
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("DEBUG: Exception - " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage());
}

$conn->close();
?>
