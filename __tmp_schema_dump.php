<?php
require 'config/database.php';
$c = getDBConnection();
foreach (['work_orders','defect_reports'] as $t) {
  echo "=== {$t} ===\n";
  $res = $c->query("SHOW CREATE TABLE {$t}");
  if (!$res) { echo "MISSING\n\n"; continue; }
  $row = $res->fetch_assoc();
  echo $row['Create Table'] . "\n\n";
}
?>
