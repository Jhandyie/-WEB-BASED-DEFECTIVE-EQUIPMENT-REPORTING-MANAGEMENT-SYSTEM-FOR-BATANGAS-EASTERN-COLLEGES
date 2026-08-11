@echo off
setlocal
title BEC PMO - Demo Tunnel

set "APP_DIR=C:\xampp\htdocs\bec-pmo"
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

REM ---- 3. Keep the laptop awake for the whole demo -------------
REM Windows suspends this machine after 15 min idle on AC / 10 on battery, which
REM would take Apache and the tunnel down mid-presentation. Held only while this
REM window is open; nothing in the power plan is modified.
set "KEEPAWAKE_PID=%APP_DIR%\data\keep_awake.pid"
if exist "%KEEPAWAKE_PID%" del /q "%KEEPAWAKE_PID%" >nul 2>&1
start "" /B powershell -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "%APP_DIR%\scripts\keep_awake.ps1" >nul 2>&1
ping -n 3 127.0.0.1 >nul
if exist "%KEEPAWAKE_PID%" (
    echo  Sleep prevention: ON ^(laptop will stay awake until this window closes^)
) else (
    echo  NOTE: could not enable sleep prevention - set Windows sleep to "Never" manually.
)
echo  Reminder: keep the laptop LID OPEN. Closing it still suspends Windows.

REM ---- 3b. Watch Apache for the whole demo ---------------------
REM The check in step 1 happens once. Apache on this machine crashes on its own
REM (opcache/VirtualProtect), and a crash twenty minutes in leaves the tunnel up
REM while every visitor gets a connection error. This polls the site like a
REM visitor and restarts Apache within seconds if it stops answering.
set "WATCHDOG_PID=%APP_DIR%\data\apache_watchdog.pid"
if exist "%WATCHDOG_PID%" del /q "%WATCHDOG_PID%" >nul 2>&1
start "" /B powershell -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "%APP_DIR%\scripts\apache_watchdog.ps1" >nul 2>&1
ping -n 3 127.0.0.1 >nul
if exist "%WATCHDOG_PID%" (
    echo  Apache watchdog: ON ^(restarts the server if it crashes; see logs\watchdog.log^)
) else (
    echo  NOTE: could not start the Apache watchdog.
)
echo.

REM ---- 4. Open the public tunnel -------------------------------
if not exist "%NGROK%" (
    echo  ERROR: ngrok not found at %NGROK%
    call :stopawake
    goto :abort
)

echo.
echo ============================================================
echo   Public URL ^(same every time^):
echo     https://%DEMO_URL%/bec-pmo/
echo.
echo   Printable QR sheet:
echo     Desktop\BEC-demo-QR.html
echo     ^(rebuild it with: php scripts\make_demo_qr.php^)
echo.
echo   To INSTALL the technician app on a phone, open this on the
echo   phone ^(the address above is https, which app install needs^):
echo     https://%DEMO_URL%/bec-pmo/technician/login.html
echo     Android Chrome:  menu  then  Install app
echo     iPhone Safari:   Share  then  Add to Home Screen
echo.
echo   Keep this window OPEN during the demo. Close it to stop.
echo ============================================================
echo.

"%NGROK%" http --url=%DEMO_URL% 80

call :stopawake
echo.
echo  Tunnel closed. Sleep prevention released - normal power settings are back.
echo  The system is still running locally at
echo    http://localhost/bec-pmo/
pause
exit /b 0

REM Stop the keep-awake helper. Windows reverts to the normal sleep timer the
REM moment that process exits - nothing needs restoring by hand.
:stopawake
if exist "%WATCHDOG_PID%" (
    for /f "usebackq delims=" %%P in ("%WATCHDOG_PID%") do taskkill /PID %%P /F >nul 2>&1
    del /q "%WATCHDOG_PID%" >nul 2>&1
)
if exist "%KEEPAWAKE_PID%" (
    for /f "usebackq delims=" %%P in ("%KEEPAWAKE_PID%") do taskkill /PID %%P /F >nul 2>&1
    del /q "%KEEPAWAKE_PID%" >nul 2>&1
)
exit /b 0

:abort
call :stopawake
echo.
pause
exit /b 1
