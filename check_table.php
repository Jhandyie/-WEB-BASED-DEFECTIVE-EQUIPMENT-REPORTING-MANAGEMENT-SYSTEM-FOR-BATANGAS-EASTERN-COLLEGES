<?php
require_once 'config/database.php';

try {
    $conn = getDBConnection();
    $result = $conn->query("SHOW TABLES LIKE 'maintenance_schedules'");
    if ($result->num_rows > 0) {
        echo "Table 'maintenance_schedules' exists\n";
    } else {
        echo "Table 'maintenance_schedules' does not exist\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
