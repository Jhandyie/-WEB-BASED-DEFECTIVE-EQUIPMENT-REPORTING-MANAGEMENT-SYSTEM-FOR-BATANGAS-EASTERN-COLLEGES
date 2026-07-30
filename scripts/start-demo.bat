@echo off
setlocal
title BEC PMO - Demo Tunnel

set "APP_DIR=C:\xampp\htdocs\-WEB-BASED"
set "PHP=C:\xampp\php\php.exe"
set "HTTPD=C:\xampp\apache\bin\httpd.exe"
set "NGROK=C:\Users\Jhan\tools\ngrok\ngrok.exe"
set "DEMO_URL=defuse-grafted-hardcover.ngrok-free.dev"

echo ============================================================
echo   BEC PMO Equipment Reporting System - Demo Launcher
echo ============================================================
echo.

REM ---- 1. Make sure Apache is actually running -----------------
REM Without this the tunnel opens fine but every visitor gets a
REM connection error, which is how a demo silently dies.
tasklist /FI "IMAGENAME eq httpd.exe" 2>nul | find /I "httpd.exe" >nul
if errorlevel 1 (
    echo  Apache is not running - starting it...
    if not exist "%HTTPD%" (
        echo  ERROR: Apache not found at %HTTPD%
        goto :abort
    )
    start "" /B "%HTTPD%"
    REM give it a moment to bind port 80
    ping -n 5 127.0.0.1 >nul
    tasklist /FI "IMAGENAME eq httpd.exe" 2>nul | find /I "httpd.exe" >nul
    if errorlevel 1 (
        echo  ERROR: Apache failed to start.
        echo  Open the XAMPP Control Panel and start Apache manually.
        echo  ^(Most common cause: port 80 is taken by Skype/IIS/VMware.^)
        goto :abort
    )
    echo  Apache started.
) else (
    echo  Apache is already running.
)
echo.

REM ---- 2. Health check before going public ---------------------
echo  Running pre-demo health check...
echo.
"%PHP%" "%APP_DIR%\scripts\demo_preflight.php"
if errorlevel 1 (
    echo.
    echo  ------------------------------------------------------------
    echo   The health check FAILED. Fix the items marked [FAIL] above.
    echo  ------------------------------------------------------------
    echo.
    choice /C YN /M "  Open the tunnel anyway"
    if errorlevel 2 goto :abort
)

REM ---- 3. Open the public tunnel -------------------------------
if not exist "%NGROK%" (
    echo  ERROR: ngrok not found at %NGROK%
    goto :abort
)

echo.
echo ============================================================
echo   Public URL ^(same every time^):
echo     https://%DEMO_URL%/-WEB-BASED/
echo.
echo   Printable QR sheet:
echo     Desktop\BEC-demo-QR.html
echo     ^(rebuild it with: php scripts\make_demo_qr.php^)
echo.
echo   Keep this window OPEN during the demo. Close it to stop.
echo ============================================================
echo.

"%NGROK%" http --url=%DEMO_URL% 80

echo.
echo  Tunnel closed. The system is still running locally at
echo    http://localhost/-WEB-BASED/
pause
exit /b 0

:abort
echo.
pause
exit /b 1
