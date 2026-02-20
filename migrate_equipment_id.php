<?php
/**
 * Migration script to change defect_reports.equipment_id from int to varchar(255)
 * This allows users to enter equipment names directly instead of selecting from dropdown
 */

require_once 'config/database.php';

try {
    $conn = getDBConnection();

    echo "Starting migration of defect_reports.equipment_id from int to varchar(255)...\n";

    // Check current table structure
    $result = $conn->query("DESCRIBE defect_reports");
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[$row['Field']] = $row;
    }

    if (!isset($columns['equipment_id'])) {
        echo "Error: equipment_id column not found in defect_reports table\n";
        exit(1);
    }

    $currentType = $columns['equipment_id']['Type'];
    echo "Current equipment_id type: $currentType\n";

    if (strpos($currentType, 'varchar') !== false) {
        echo "Migration already completed - equipment_id is already varchar\n";
        exit(0);
    }

    // Step 1: Drop foreign key constraint if it exists
    $result = $conn->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_NAME = 'defect_reports'
        AND TABLE_SCHEMA = DATABASE()
        AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        AND CONSTRAINT_NAME LIKE '%equipment%'
    ");

    if ($result->num_rows > 0) {
        $constraint = $result->fetch_assoc()['CONSTRAINT_NAME'];
        echo "Dropping foreign key constraint: $constraint\n";
        $conn->query("ALTER TABLE defect_reports DROP FOREIGN KEY `$constraint`");
    }

    // Step 2: Change column type
    echo "Changing equipment_id column type to varchar(255)...\n";
    $conn->query("ALTER TABLE defect_reports MODIFY COLUMN equipment_id varchar(255) NOT NULL COMMENT 'User-entered equipment name/text'");

    if ($conn->error) {
        echo "Error changing column type: " . $conn->error . "\n";
        exit(1);
    }

    // Step 3: Update column comment
    $conn->query("ALTER TABLE defect_reports MODIFY COLUMN equipment_id varchar(255) NOT NULL COMMENT 'User-entered equipment name/text'");

    // Step 4: Update table indexes
    echo "Updating indexes...\n";
    $conn->query("DROP INDEX idx_equipment_id ON defect_reports");
    $conn->query("CREATE INDEX idx_equipment_id ON defect_reports(equipment_id(100))");

    echo "Migration completed successfully!\n";
    echo "defect_reports.equipment_id is now varchar(255) for user-entered equipment names.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
