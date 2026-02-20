<?php
require_once 'config/database.php';

$conn = getDBConnection();

echo "Equipment status values:\n";
echo "========================\n";

$result = $conn->query('SELECT DISTINCT status FROM equipment');
if ($result) {
    while($row = $result->fetch_assoc()) {
        echo $row['status'] . "\n";
    }
} else {
    echo "Error querying equipment table\n";
}
?>
