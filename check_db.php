<?php
$conn = new mysqli('localhost', 'root', '12345', 'bec_equipment_db');
if ($conn->connect_error) {
    echo 'Connection failed: ' . $conn->connect_error;
    exit(1);
}
$result = $conn->query('SHOW TABLES');
if ($result) {
    echo 'Database tables exist: ' . $result->num_rows . ' tables found' . PHP_EOL;
    while ($row = $result->fetch_array()) {
        echo '- ' . $row[0] . PHP_EOL;
    }
} else {
    echo 'No tables found or query failed';
}
$conn->close();
?>
