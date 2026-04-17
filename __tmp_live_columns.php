<?php
require 'config/database.php';
$c = getDBConnection();
foreach (['work_orders','defect_reports','users','maintenance_technicians'] as $t) {
  echo "=== {$t} ===\n";
  $res = $c->query("SHOW COLUMNS FROM {$t}");
  if (!$res) { echo "MISSING\n\n"; continue; }
  while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\t" . $row['Type'] . "\t" . $row['Null'] . "\t" . ($row['Default'] ?? 'NULL') . "\n";
  }
  echo "\n";
}
?>
