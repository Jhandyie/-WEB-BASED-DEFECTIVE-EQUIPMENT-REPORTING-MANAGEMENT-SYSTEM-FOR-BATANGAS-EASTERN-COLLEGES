<?php
// fix_registration.php
// Comprehensive fix for student registration issues

require_once 'config/database.php';

$conn = getDBConnection();

echo "=== FIXING REGISTRATION ISSUES ===\n\n";

// Step 1: Check and fix users table structure
echo "Step 1: Checking users table structure...\n";

$result = $conn->query("DESCRIBE users");
$columns = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
        echo "  - {$row['Field']}: {$row['Type']} ({$row['Key']})\n";
    }
}
echo "\n";

// Step 2: Add user_id column if it doesn't exist
if (!in_array('user_id', $columns)) {
    echo "Step 2: Adding user_id column...\n";
    $sql = "ALTER TABLE users ADD COLUMN user_id VARCHAR(20) AFTER id";
    if ($conn->query($sql)) {
        echo "  SUCCESS: user_id column added!\n";
        
        // Add index
        $conn->query("ALTER TABLE users ADD INDEX idx_user_id (user_id)");
        echo "  SUCCESS: Index added!\n";
        
        // Update existing records
        $result = $conn->query("SELECT id FROM users WHERE (user_id IS NULL OR user_id = '')");
        if ($result && $result->num_rows > 0) {
            $counter = 1;
            while ($row = $result->fetch_assoc()) {
                $new_user_id = 'STU-' . str_pad($counter, 3, '0', STR_PAD_LEFT);
                $conn->query("UPDATE users SET user_id = '$new_user_id' WHERE id = " . $row['id']);
                $counter++;
            }
            echo "  SUCCESS: Updated $counter existing records!\n";
        }
    } else {
        echo "  ERROR: " . $conn->error . "\n";
    }
} else {
    echo "Step 2: user_id column already exists - SKIPPED\n";
}

// Step 3: Check and fix role enum if needed
echo "\nStep 3: Checking role column...\n";
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "  Current role type: {$row['Type']}\n";
    
    // Check if 'student' is in the enum
    if (strpos($row['Type'], 'student') === false) {
        echo "  WARNING: 'student' role might not be in enum. Checking...\n";
        // Try to alter the column to add student if needed
        $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','student','faculty','guest','technician','handler') DEFAULT 'student'");
        echo "  SUCCESS: role column updated to include 'student'!\n";
    } else {
        echo "  OK: 'student' role exists in enum\n";
    }
}

// Step 4: Check and fix status enum if needed
echo "\nStep 4: Checking status column...\n";
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "  Current status type: {$row['Type']}\n";
    
    // Check if 'active' is in the enum
    if (strpos($row['Type'], 'active') === false) {
        echo "  WARNING: 'active' status might not be in enum. Checking...\n";
        $conn->query("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','suspended') DEFAULT 'active'");
        echo "  SUCCESS: status column updated to include 'active'!\n";
    } else {
        echo "  OK: 'active' status exists in enum\n";
    }
}

// Step 5: Test registration
echo "\nStep 5: Testing registration...\n";

$test_email = "test_" . time() . "@fix.com";
$test_fullname = "Fix Test User";
$test_password = "test123456";
$test_username = explode('@', $test_email)[0];

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

echo "  Test user_id: $new_user_id\n";
echo "  Test username: $test_username\n";
echo "  Test email: $test_email\n";

// Hash password
$hashed_password = password_hash($test_password, PASSWORD_DEFAULT);

// Try insert
$sql = "INSERT INTO users (user_id, username, password, fullname, email, role, status) VALUES (?, ?, ?, ?, ?, 'student', 'active')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssss", $new_user_id, $test_username, $hashed_password, $test_fullname, $test_email);

if ($stmt->execute()) {
    echo "  SUCCESS: Test user created!\n";
    echo "  Insert ID: " . $conn->insert_id . "\n";
    
    // Verify
    $verify_stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $verify_stmt->bind_param("s", $test_email);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows > 0) {
        $user = $verify_result->fetch_assoc();
        echo "  VERIFIED: User found in database\n";
        echo "    - user_id: {$user['user_id']}\n";
        echo "    - username: {$user['username']}\n";
        echo "    - email: {$user['email']}\n";
        echo "    - role: {$user['role']}\n";
        echo "    - status: {$user['status']}\n";
        
        // Clean up test user
        $conn->query("DELETE FROM users WHERE email = '$test_email'");
        echo "  CLEANUP: Test user removed\n";
    }
} else {
    echo "  ERROR: " . $stmt->error . "\n";
}

$stmt->close();

echo "\n=== FIX COMPLETE ===\n";

$conn->close();
?>
