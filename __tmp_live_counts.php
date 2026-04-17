<?php
require 'config/database.php';
$c = getDBConnection();
$tables = ['defect_reports','work_orders'];
foreach ($tables as $t) {
  $res = $c->query("SELECT COUNT(*) AS c FROM {$t}");
  echo $t . ':' . (($res && ($row = $res->fetch_assoc())) ? $row['c'] : 'ERR') . "\n";
}
?>
