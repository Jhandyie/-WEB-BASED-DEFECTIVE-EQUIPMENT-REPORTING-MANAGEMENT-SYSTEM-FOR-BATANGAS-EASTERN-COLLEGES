@echo off
echo MySQL Root Password Reset Script for XAMPP
echo ===========================================
echo.
echo Note: This script requires administrator privileges.
echo Make sure MySQL is stopped in XAMPP Control Panel before running this.
echo.
echo Starting MySQL in safe mode...
start /B "MySQL Safe Mode" "C:\xampp\mysql\bin\mysqld.exe" --skip-grant-tables --console

echo Waiting for MySQL to start...
ping 127.0.0.1 -n 5 > nul

echo.
echo Resetting password to empty...
"C:\xampp\mysql\bin\mysql.exe" -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY ''; FLUSH PRIVILEGES;"

echo.
echo Stopping safe mode MySQL...
taskkill /F /IM mysqld.exe /T

echo.
echo Password reset complete! Your MySQL root password is now empty (no password).
echo Now start MySQL from XAMPP Control Panel.
echo You can now login to phpMyAdmin or MySQL without a password.
echo.
pause
