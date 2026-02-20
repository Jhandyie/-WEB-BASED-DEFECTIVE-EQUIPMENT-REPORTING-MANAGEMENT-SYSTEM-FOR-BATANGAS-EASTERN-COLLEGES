<?php
require_once 'config/database.php';

$conn = getDBConnection();
$result = $conn->query('SELECT equipment_id, equipment_name, status, quantity FROM equipment');

echo "Equipment in database:\n";
echo "=====================\n";

while($row = $result->fetch_assoc()) {
    echo $row['equipment_id'] . ' - ' . $row['equipment_name'] . ' - ' . $row['status'] . ' - ' . $row['quantity'] . PHP_EOL;
}

echo "\nTesting controller getAvailableEquipment:\n";
echo "=========================================\n";

$controller = new StudentDashboardController();
$result = $controller->getAvailableEquipment();

echo "Controller result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
?>
