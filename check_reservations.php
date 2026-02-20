<?php
require_once 'config/database.php';

$conn = getDBConnection();

echo "Reservations table structure:\n";
echo "=============================\n";

$result = $conn->query('DESCRIBE reservations');
if ($result) {
    while($row = $result->fetch_assoc()) {
        echo $row['Field'] . ' - ' . $row['Type'] . PHP_EOL;
    }
} else {
    echo "Error describing reservations table\n";
}

echo "\nEquipment table structure:\n";
echo "==========================\n";

$result = $conn->query('DESCRIBE equipment');
if ($result) {
    while($row = $result->fetch_assoc()) {
        echo $row['Field'] . ' - ' . $row['Type'] . PHP_EOL;
    }
} else {
    echo "Error describing equipment table\n";
}

echo "\nSample reservations data:\n";
echo "=========================\n";

$result = $conn->query('SELECT * FROM reservations LIMIT 5');
if ($result) {
    while($row = $result->fetch_assoc()) {
        echo json_encode($row) . PHP_EOL;
    }
} else {
    echo "No reservations data or error\n";
}
?>
