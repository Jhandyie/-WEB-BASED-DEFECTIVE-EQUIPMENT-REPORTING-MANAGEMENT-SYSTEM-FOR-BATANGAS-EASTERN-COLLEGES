@echo off
echo MySQL Root Password Reset Script for XAMPP (Manual Method)
echo ========================================================
echo.
echo IMPORTANT: Make sure MySQL is STOPPED in XAMPP Control Panel before running this script.
echo.
echo Step 1: Starting MySQL in safe mode...
start /B "MySQL Safe Mode" "C:\xampp\mysql\bin\mysqld.exe" --skip-grant-tables --console

echo Waiting for MySQL to start...
ping 127.0.0.1 -n 5 > nul

echo.
echo Step 2: Resetting password to empty...
"C:\xampp\mysql\bin\mysql.exe" -u root -e "DROP TABLE IF EXISTS mysql.plugin; CREATE TABLE mysql.plugin (name varchar(64) NOT NULL DEFAULT '', dl varchar(128) NOT NULL DEFAULT '', PRIMARY KEY (name)) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;"

echo.
echo Step 3: Stopping safe mode MySQL...
taskkill /F /IM mysqld.exe /T

echo.
echo Password reset complete!
echo Now START MySQL from XAMPP Control Panel.
echo You can now login to phpMyAdmin or MySQL without a password.
echo.
pause
