<?php
require_once 'config/database.php';

$conn = getDBConnection();

echo "Equipment table data check:\n";
echo "==========================\n";

$result = $conn->query('SELECT e.equipment_id, e.equipment_name, e.category_id, c.category_name FROM equipment e LEFT JOIN categories c ON e.category_id = c.category_id LIMIT 10');

if ($result) {
    while($row = $result->fetch_assoc()) {
        echo $row['equipment_id'] . ' - ' . $row['equipment_name'] . ' - category_id: ' . $row['category_id'] . ' - category: ' . ($row['category_name'] ?? 'NULL') . "\n";
    }
} else {
    echo "Error querying equipment table\n";
}
?>
