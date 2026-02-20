<?php
require_once 'config/database.php';

$conn = getDBConnection();

echo "Categories table check:\n";
echo "======================\n";

$result = $conn->query('SELECT COUNT(*) as count FROM categories');
if ($result) {
    $row = $result->fetch_assoc();
    echo "Categories count: " . $row['count'] . "\n\n";
} else {
    echo "Error querying categories table\n";
}

echo "Sample categories:\n";
$result = $conn->query('SELECT * FROM categories LIMIT 5');
if ($result) {
    while($row = $result->fetch_assoc()) {
        echo $row['category_id'] . ' - ' . $row['category_name'] . "\n";
    }
} else {
    echo "No categories data or error\n";
}
?>
