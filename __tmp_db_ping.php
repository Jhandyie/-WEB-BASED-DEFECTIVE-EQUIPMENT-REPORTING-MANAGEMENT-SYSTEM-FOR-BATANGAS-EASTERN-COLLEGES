<?php
require 'config/database.php';
$c = getDBConnection();
echo $c ? 'connected' : 'not';
?>
