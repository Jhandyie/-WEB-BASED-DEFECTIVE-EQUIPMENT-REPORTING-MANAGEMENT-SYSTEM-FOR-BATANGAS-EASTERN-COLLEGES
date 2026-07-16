@echo off
REM ============================================================================
REM  BEC PMO — Install the Technician app on a phone (demo helper)
REM
REM  Double-click this file. It opens a temporary, secure HTTPS address for your
REM  local system so a phone can INSTALL the technician app (PWA install needs
REM  https://). The first run downloads the tunnel tool automatically.
REM
REM  Leave the window open during your demo. Close it (or press Ctrl+C) to stop.
REM ============================================================================
title BEC PMO - Phone install tunnel
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\phone-tunnel.ps1"
echo.
echo Tunnel stopped. You can close this window.
pause
