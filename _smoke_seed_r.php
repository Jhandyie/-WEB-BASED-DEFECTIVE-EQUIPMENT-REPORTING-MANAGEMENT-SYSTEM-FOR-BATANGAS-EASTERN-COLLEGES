<?php
session_start();
$_SESSION['user_id']='SMOKE-REPORTER';$_SESSION['fullname']='Smoke Reporter';$_SESSION['email']='jhanmarkdecastro128@gmail.com';$_SESSION['user_email']=$_SESSION['email'];$_SESSION['role']='student';
$_SESSION['guest_email']='jhanmarkdecastro128@gmail.com';$_SESSION['guest_name']='Smoke Reporter';$_SESSION['guest_since']=time();
require __DIR__.'/includes/csrf.php';
$__t=csrf_token();
session_write_close();
require __DIR__.'/includes/session_bootstrap.php';
startRoleSession('student');
$_SESSION['user_id']='SMOKE-REPORTER';$_SESSION['fullname']='Smoke Reporter';$_SESSION['email']='jhanmarkdecastro128@gmail.com';$_SESSION['user_email']=$_SESSION['email'];$_SESSION['role']='student';
echo $__t;