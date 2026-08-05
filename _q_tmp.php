<?php
require_once __DIR__.'/config/database.php';
$pdo = getPgsqlPdoConnection();
foreach ($pdo->query("SELECT user_id, fullname, role, department, COALESCE(position,'') AS pos, COALESCE(user_type,'') AS ut, COALESCE(year_level,'') AS yl FROM public.users WHERE COALESCE(status,'')<>'deleted' ORDER BY role, fullname", PDO::FETCH_ASSOC) as $r) echo implode(' | ', $r)."\n";
