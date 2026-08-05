<?php
require __DIR__.'/includes/session_bootstrap.php';
startRoleSession('technician');
$_SESSION['user_id']='TECH-SMOKE1';$_SESSION['fullname']='Smoke Test Technician';$_SESSION['user_email']='jhanmarkdecastro128@gmail.com';$_SESSION['email']='jhanmarkdecastro128@gmail.com';$_SESSION['role']='technician';$_SESSION['logged_in']=true;
echo 'ok';