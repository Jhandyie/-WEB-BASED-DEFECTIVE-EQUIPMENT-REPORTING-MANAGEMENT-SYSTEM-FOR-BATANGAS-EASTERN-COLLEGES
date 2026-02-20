<?php
// fix_user_id_column.php
// Add user_id column to users table

require_once 'config/database.php';

$conn = getDBConnection();

// Check if user_id column already exists
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'user_id'");

if ($result->num_rows > 0) {
    echo "user_id column already exists!\n";
} else {
    // Add user_id column
    $sql = "ALTER TABLE users ADD COLUMN user_id VARCHAR(20) AFTER id";
    
    if ($conn->query($sql)) {
        echo "user_id column added successfully!\n";
        
        // Add index for faster lookups
        $conn->query("ALTER TABLE users ADD INDEX idx_user_id (user_id)");
        echo "Index added on user_id column!\n";
        
        // Update existing records to have user_id based on their id
        // Format: STU-001, STU-002, etc. or use a simple numbering
        $result = $conn->query("SELECT id FROM users WHERE user_id IS NULL OR user_id = ''");
        
        if ($result->num_rows > 0) {
            $counter = 1;
            while ($row = $result->fetch_assoc()) {
                $new_user_id = 'STU-' . str_pad($counter, 3, '0', STR_PAD_LEFT);
                $conn->query("UPDATE users SET user_id = '$new_user_id' WHERE id = " . $row['id']);
                $counter++;
            }
            echo "Updated $counter existing records with user_id!\n";
        }
    } else {
        echo "Error adding user_id column: " . $conn->error . "\n";
    }
}

$conn->close();
?>
