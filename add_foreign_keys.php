<?php
require_once 'config/database.php';

try {
    $conn = getDBConnection();

    // Add foreign key constraints
    $sqls = [
        "ALTER TABLE `defect_report_status_history`
         ADD CONSTRAINT `fk_history_report` FOREIGN KEY (`report_id`) REFERENCES `defect_reports` (`id`) ON DELETE CASCADE",
        "ALTER TABLE `defect_report_status_history`
         ADD CONSTRAINT `fk_history_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL"
    ];

    foreach ($sqls as $sql) {
        $result = $conn->query($sql);
        if ($result) {
            echo "Foreign key added successfully\n";
        } else {
            echo "Error adding foreign key: " . $conn->error . "\n";
        }
    }

    echo "Foreign key constraints addition completed\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
