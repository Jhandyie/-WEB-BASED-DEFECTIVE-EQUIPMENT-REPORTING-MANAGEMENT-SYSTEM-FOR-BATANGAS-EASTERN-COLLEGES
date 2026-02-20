<?php
require_once 'config/database.php';

try {
    $conn = getDBConnection();

    // Check if tables exist
    $tables = ['users', 'equipment', 'defect_reports', 'defect_report_status_history', 'reservations', 'notifications'];

    foreach ($tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result && $result->num_rows > 0) {
            echo "Table '$table' exists\n";

            // Show table structure
            $structure = $conn->query("DESCRIBE `$table`");
            if ($structure) {
                echo "Structure of '$table':\n";
                while ($row = $structure->fetch_assoc()) {
                    echo "  " . $row['Field'] . " " . $row['Type'] . " " . ($row['Null'] == 'NO' ? 'NOT NULL' : 'NULL') . "\n";
                }
                echo "\n";
            }
        } else {
            echo "Table '$table' does not exist\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
