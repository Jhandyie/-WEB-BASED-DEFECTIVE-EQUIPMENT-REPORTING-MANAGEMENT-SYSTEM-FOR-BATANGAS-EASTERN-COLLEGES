<?php
require __DIR__ . '/../config/database.php';
$c = getDBConnection();
$q = "SELECT category, COUNT(*) AS c FROM equipment WHERE asset_tag LIKE '%-0825-%' GROUP BY category ORDER BY c DESC";
$r = $c->query($q);
while($row = $r->fetch_assoc()){
  echo $row['category'] . ':' . $row['c'] . PHP_EOL;
}
$tot = $c->query("SELECT COUNT(*) AS t FROM equipment WHERE asset_tag LIKE '%-0825-%'")->fetch_assoc();
echo 'TOTAL:' . (int)$tot['t'] . PHP_EOL;
?>
