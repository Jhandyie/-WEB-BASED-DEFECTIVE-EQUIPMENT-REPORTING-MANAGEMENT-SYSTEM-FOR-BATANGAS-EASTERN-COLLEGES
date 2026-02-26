<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require __DIR__ . '/config/database.php';
$conn = getDBConnection();
if (!$conn) { echo "NO_CONN\n"; exit(1); }
$res = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
if (!$res) { echo "SHOW_ERR: " . $conn->error . "\n"; exit(1); }
$row = $res->fetch_assoc();
echo "COLUMN:" . json_encode($row) . "\n";
$res2 = $conn->query("SELECT status, COUNT(*) c FROM users GROUP BY status ORDER BY status");
if (!$res2) { echo "GROUP_ERR: " . $conn->error . "\n"; exit(1); }
while ($r = $res2->fetch_assoc()) {
    echo "STATUS=" . $r['status'] . " COUNT=" . $r['c'] . "\n";
}
