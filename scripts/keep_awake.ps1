<#
    keep_awake.ps1 — hold the laptop awake for the duration of a demo.

    Windows sleeps this machine after 15 minutes idle on AC (10 on battery).
    During a presentation nobody touches the keyboard for long stretches, so the
    machine can suspend mid-demo and take Apache, the database connection and the
    public tunnel down with it.

    This asks Windows for a "system and display required" execution state and
    holds it until the process is stopped. Nothing is written to the power plan,
    so there is no setting to remember to undo: when this process exits — or if
    it is killed, or the machine reboots — Windows reverts to normal behaviour
    on its own.

    Started and stopped automatically by scripts\start-demo.bat. To run it by
    hand:  powershell -ExecutionPolicy Bypass -File scripts\keep_awake.ps1

    NOTE: this cannot override closing the lid. Leave the lid open.
#>

param(
    # Where to record this process's PID so the launcher can stop it again.
    [string]$PidFile = "$PSScriptRoot\..\data\keep_awake.pid"
)

$ErrorActionPreference = 'Stop'

$signature = @'
[DllImport("kernel32.dll", SetLastError = true)]
public static extern uint SetThreadExecutionState(uint esFlags);
'@

try {
    $power = Add-Type -MemberDefinition $signature -Name 'BecPower' -Namespace 'Win32' -PassThru
} catch {
    Write-Host "  keep-awake unavailable: $($_.Exception.Message)"
    exit 1
}

$ES_CONTINUOUS       = [uint32]'0x80000000'
$ES_SYSTEM_REQUIRED  = [uint32]'0x00000001'
$ES_DISPLAY_REQUIRED = [uint32]'0x00000002'

$result = $power::SetThreadExecutionState($ES_CONTINUOUS -bor $ES_SYSTEM_REQUIRED -bor $ES_DISPLAY_REQUIRED)
if ($result -eq 0) {
    Write-Host "  keep-awake request was refused by Windows."
    exit 1
}

# Record the PID so start-demo.bat can stop exactly this process later.
$dir = Split-Path -Parent $PidFile
if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
$PID | Set-Content -Path $PidFile -Encoding ASCII

try {
    # Hold the state. Re-assert periodically so a power-policy change mid-demo
    # (e.g. the charger being unplugged) does not quietly drop it.
    while ($true) {
        Start-Sleep -Seconds 45
        [void]$power::SetThreadExecutionState($ES_CONTINUOUS -bor $ES_SYSTEM_REQUIRED -bor $ES_DISPLAY_REQUIRED)
    }
} finally {
    # Release the hold and clean up, whichever way we exit.
    [void]$power::SetThreadExecutionState($ES_CONTINUOUS)
    if (Test-Path $PidFile) { Remove-Item $PidFile -Force -ErrorAction SilentlyContinue }
}
