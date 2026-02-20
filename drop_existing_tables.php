<?php
require_once 'config/database.php';

try {
    $conn = getDBConnection();

    // Disable foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    // Drop existing tables in reverse dependency order
    $tables = [
        'notifications',
        'reservations',
        'defect_reports',
        'equipment',
        'users'
    ];

    foreach ($tables as $table) {
        $sql = "DROP TABLE IF EXISTS `$table`";
        $result = $conn->query($sql);
        if ($result) {
            echo "Dropped table '$table'\n";
        } else {
            echo "Failed to drop table '$table'\n";
        }
    }

    // Re-enable foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    echo "All existing tables dropped successfully\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
