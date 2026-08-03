<#
    apache_watchdog.ps1 - keep the site answering for the whole demo.

    Apache on this machine dies on its own roughly once a day. It is a crash,
    not a shutdown: the error log records no SIGTERM, just a stream of
    "VirtualProtect() failed [87]" from PHP's opcache and then nothing until it
    is started again. start-demo.bat already checks Apache once, at launch -
    but the tunnel stays open long after that, so a crash twenty minutes in
    leaves the public URL up while every visitor gets a connection error. That
    is the failure the launcher's own comment warns about.

    This polls the site the way a visitor does - an actual HTTP request, not
    just "is the process listed", because a wedged httpd.exe can still appear
    in the task list - and restarts Apache when it stops answering. Each event
    is written to logs\watchdog.log so there is a record afterwards.

    Started and stopped automatically by scripts\start-demo.bat. By hand:
        powershell -ExecutionPolicy Bypass -File scripts\apache_watchdog.ps1
#>

param(
    [string]$Url      = 'http://localhost/-WEB-BASED/index.php',
    [int]   $Every    = 15,     # seconds between checks
    [int]   $Timeout  = 10,     # seconds to wait for a reply
    [string]$Httpd    = 'C:\xampp\apache\bin\httpd.exe',
    [string]$PidFile  = "$PSScriptRoot\..\data\apache_watchdog.pid",
    [string]$LogFile  = "$PSScriptRoot\..\logs\watchdog.log"
)

$ErrorActionPreference = 'Continue'

function Write-Event([string]$message) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $message
    $dir = Split-Path -Parent $LogFile
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
    Write-Host "  $line"
}

$dir = Split-Path -Parent $PidFile
if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
$PID | Set-Content -Path $PidFile -Encoding ASCII

Write-Event "watchdog started - checking $Url every $Every s"

$consecutiveFailures = 0
$restarts = 0

try {
    while ($true) {
        Start-Sleep -Seconds $Every

        $ok = $false
        try {
            $resp = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec $Timeout
            $ok = ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 500)
        } catch {
            $ok = $false
        }

        if ($ok) {
            if ($consecutiveFailures -gt 0) { Write-Event "site answering again" }
            $consecutiveFailures = 0
            continue
        }

        # Two misses in a row before acting, so one slow response during a
        # heavy page does not trigger a needless restart mid-demo.
        $consecutiveFailures++
        if ($consecutiveFailures -lt 2) {
            Write-Event "no reply (1st miss) - rechecking"
            continue
        }

        Write-Event "site is down - restarting Apache"
        Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
        if (-not (Test-Path $Httpd)) {
            Write-Event "ERROR: httpd.exe not found at $Httpd - cannot restart"
            continue
        }
        Start-Process -FilePath $Httpd -WindowStyle Hidden
        Start-Sleep -Seconds 5

        try {
            $resp = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec $Timeout
            $restarts++
            Write-Event "Apache restarted and answering (HTTP $($resp.StatusCode)) - restart #$restarts"
            $consecutiveFailures = 0
        } catch {
            Write-Event "restart did not bring the site back: $($_.Exception.Message)"
        }
    }
} finally {
    Write-Event "watchdog stopped after $restarts restart(s)"
    if (Test-Path $PidFile) { Remove-Item $PidFile -Force -ErrorAction SilentlyContinue }
}
