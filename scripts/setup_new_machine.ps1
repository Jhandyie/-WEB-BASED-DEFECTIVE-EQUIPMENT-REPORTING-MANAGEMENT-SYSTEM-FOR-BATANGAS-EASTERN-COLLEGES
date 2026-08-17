<#
    setup_new_machine.ps1 - prepare a fresh Windows laptop to serve this system.

    Run it after copying the project folder into C:\xampp\htdocs\. It checks
    everything the app needs, fixes what it safely can, and prints a numbered
    list of anything left for a human. Safe to run more than once - it changes
    only what is not already correct, and backs up php.ini before touching it.

        powershell -ExecutionPolicy Bypass -File scripts\setup_new_machine.ps1

    Add -Apply to let it write changes; without it, nothing is modified and you
    get a report of what it would do.

    What it cannot do for you, because these are secrets and accounts:
      * install XAMPP (download it yourself - see docs\SETUP_NEW_LAPTOP.md)
      * supply .env, config\chat_secrets.php, data\system_settings.json
      * sign in to ngrok
#>

param(
    [switch]$Apply,
    [string]$AppDir = (Split-Path -Parent $PSScriptRoot),
    [string]$XamppDir = 'C:\xampp'
)

$ErrorActionPreference = 'Continue'
$script:todo = @()
$script:fixed = @()

function Say([string]$state, [string]$text) {
    $colour = switch ($state) { 'OK' { 'Green' } 'FIX' { 'Cyan' } 'TODO' { 'Yellow' } 'FAIL' { 'Red' } default { 'Gray' } }
    Write-Host ("  [{0,-4}] {1}" -f $state, $text) -ForegroundColor $colour
}
function NeedsYou([string]$text) { $script:todo += $text; Say 'TODO' $text }
function Fixed([string]$text)    { $script:fixed += $text; Say 'FIX' $text }

Write-Host ""
Write-Host "  BEC PMO - new machine setup" -ForegroundColor White
Write-Host "  app folder : $AppDir"
Write-Host "  xampp      : $XamppDir"
if (-not $Apply) { Write-Host "  MODE       : report only (add -Apply to make changes)" -ForegroundColor Yellow }
Write-Host ""

# ---- 1. XAMPP present -------------------------------------------------------
Write-Host "  1. XAMPP" -ForegroundColor White
$php   = Join-Path $XamppDir 'php\php.exe'
$httpd = Join-Path $XamppDir 'apache\bin\httpd.exe'
$ini   = Join-Path $XamppDir 'php\php.ini'
$xamppOk = (Test-Path $php) -and (Test-Path $httpd)
if (-not $xamppOk) {
    Say 'FAIL' "XAMPP not found at $XamppDir"
    NeedsYou "Install XAMPP with PHP 8.2 from https://www.apachefriends.org into $XamppDir, then run this again."
    Write-Host ""
    Write-Host "  Stopping here - nothing else can be checked without PHP." -ForegroundColor Red
    exit 1
}
$phpVersion = (& $php -r "echo PHP_VERSION;")
Say 'OK' "PHP $phpVersion at $php"
if ($phpVersion -notmatch '^8\.') { NeedsYou "This app is built for PHP 8.x; found $phpVersion. Install a XAMPP bundle with PHP 8.2." }

# ---- 2. App folder in the right place ---------------------------------------
Write-Host ""
Write-Host "  2. Application files" -ForegroundColor White
$expected = Join-Path $XamppDir 'htdocs'
if ((Split-Path -Parent $AppDir) -ne $expected) {
    NeedsYou "Move the project folder into $expected (it is currently in $(Split-Path -Parent $AppDir)). The URL path depends on it."
} else {
    Say 'OK' "Project is under htdocs as '$(Split-Path -Leaf $AppDir)'"
}
foreach ($d in @('uploads','logs','data','backups')) {
    $p = Join-Path $AppDir $d
    if (-not (Test-Path $p)) {
        if ($Apply) { New-Item -ItemType Directory -Force -Path $p | Out-Null; Fixed "created missing folder $d\" }
        else { Say 'FIX' "would create missing folder $d\" }
    }
}

# ---- 3. PHP extensions -------------------------------------------------------
Write-Host ""
Write-Host "  3. PHP extensions" -ForegroundColor White
# pdo_pgsql is the one that matters most: the database is Supabase (Postgres).
# Without it every page dies on the first query.
$required = @('pdo_pgsql','pgsql','curl','mbstring','openssl','fileinfo','zip')
$loaded = (& $php -r "echo implode(',', get_loaded_extensions());") -split ','
$missing = $required | Where-Object { $loaded -notcontains $_ }
if ($missing.Count -eq 0) {
    Say 'OK' "all required extensions present ($($required -join ', '))"
} else {
    $iniText = Get-Content $ini -Raw
    foreach ($ext in $missing) {
        if ($iniText -match "(?m)^;extension=$ext\s*$") {
            if ($Apply) {
                $iniText = $iniText -replace "(?m)^;extension=$ext\s*$", "extension=$ext"
                Fixed "enabled extension=$ext in php.ini"
            } else { Say 'FIX' "would enable extension=$ext in php.ini" }
        } else {
            NeedsYou "Extension '$ext' is missing and not just commented out in php.ini - install a XAMPP build that ships it."
        }
    }
    if ($Apply) { Set-Content -Path $ini -Value $iniText -NoNewline }
}

# ---- 4. php.ini values the app depends on ------------------------------------
Write-Host ""
Write-Host "  4. php.ini settings" -ForegroundColor White
# Photos are resized in the browser before upload, but a phone can still send
# several at once; opcache is off because it crashes Apache on Windows here.
$wanted = [ordered]@{
    'upload_max_filesize' = '40M'
    'post_max_size'       = '40M'
    'memory_limit'        = '512M'
    'max_execution_time'  = '120'
    'max_file_uploads'    = '20'
    'opcache.enable'      = '0'
}
$iniText = Get-Content $ini -Raw
$iniChanged = $false
$backupMade = $false
foreach ($k in $wanted.Keys) {
    $want = $wanted[$k]
    $current = (& $php -r "echo ini_get('$k');")
    if ($current -eq $want) { Say 'OK' "$k = $want"; continue }
    $pattern = "(?m)^\s*" + [regex]::Escape($k) + "\s*=.*$"
    if ($Apply) {
        if (-not $backupMade) {
            Copy-Item $ini "$ini.backup_$(Get-Date -Format yyyyMMdd_HHmmss)"
            $backupMade = $true
        }
        if ($iniText -match $pattern) { $iniText = $iniText -replace $pattern, "$k=$want" }
        else { $iniText += "`r`n$k=$want`r`n" }
        $iniChanged = $true
        Fixed "$k : '$current' -> '$want'"
    } else {
        Say 'FIX' "would set $k : '$current' -> '$want'"
    }
}
if ($Apply -and $iniChanged) { Set-Content -Path $ini -Value $iniText -NoNewline }

# ---- 5. Secrets that cannot be generated -------------------------------------
Write-Host ""
Write-Host "  5. Configuration carried from the old machine" -ForegroundColor White
$secrets = @(
    @{ path = '.env';                      what = 'Supabase database credentials'; sample = '.env.example' },
    @{ path = 'data\system_settings.json'; what = 'e-mail (Gmail SMTP) settings';   sample = $null },
    @{ path = 'config\chat_secrets.php';   what = 'Gemini API key for the assistant (optional - it falls back to a built-in brain)'; sample = $null }
)
foreach ($s in $secrets) {
    $p = Join-Path $AppDir $s.path
    if (Test-Path $p) { Say 'OK' "$($s.path) present" }
    elseif ($s.sample -and (Test-Path (Join-Path $AppDir $s.sample))) {
        NeedsYou "Copy $($s.path) from the old laptop ($($s.what)). A template exists at $($s.sample) - it is NOT in git, so it must be carried by hand."
    } else {
        NeedsYou "Copy $($s.path) from the old laptop ($($s.what)). It is not in git."
    }
}

# ---- 6. Never sleep ----------------------------------------------------------
Write-Host ""
Write-Host "  6. Power settings (a sleeping laptop takes the demo down)" -ForegroundColor White
if ($Apply) {
    powercfg /change standby-timeout-ac 0      2>&1 | Out-Null
    powercfg /change hibernate-timeout-ac 0    2>&1 | Out-Null
    powercfg /change monitor-timeout-ac 0      2>&1 | Out-Null
    powercfg /change disk-timeout-ac 0         2>&1 | Out-Null
    Fixed "sleep, hibernate, monitor and disk timeouts set to Never while on mains power"
    Say 'OK' "start-demo.bat also holds the machine awake for the duration of a demo"
} else {
    Say 'FIX' "would set sleep/hibernate/monitor/disk timeouts to Never (on AC)"
}
NeedsYou "In Windows Settings, set 'When I close the lid' to 'Do nothing' - no script can override the lid switch."

# ---- 7. ngrok ---------------------------------------------------------------
Write-Host ""
Write-Host "  7. Public tunnel (ngrok)" -ForegroundColor White
$ngrokGuess = @(
    "$env:USERPROFILE\tools\ngrok\ngrok.exe",
    "$env:LOCALAPPDATA\ngrok\ngrok.exe",
    "C:\ngrok\ngrok.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if ($ngrokGuess) {
    Say 'OK' "ngrok found at $ngrokGuess"
    $bat = Join-Path $AppDir 'scripts\start-demo.bat'
    if ((Get-Content $bat -Raw) -notmatch [regex]::Escape($ngrokGuess)) {
        NeedsYou "Edit scripts\start-demo.bat and set NGROK=$ngrokGuess (it still points at the old laptop's path)."
    }
} else {
    NeedsYou "Install ngrok from https://ngrok.com/download, run 'ngrok config add-authtoken <token>', then set the NGROK path in scripts\start-demo.bat."
}
NeedsYou "The reserved demo URL in scripts\start-demo.bat belongs to one ngrok account - sign in as that account on this laptop, or change DEMO_URL."

# ---- 8. Desktop shortcut -----------------------------------------------------
Write-Host ""
Write-Host "  8. Desktop shortcut" -ForegroundColor White
$desktop = [Environment]::GetFolderPath('Desktop')
$lnk = Join-Path $desktop 'Start BEC Demo.lnk'
$target = Join-Path $AppDir 'scripts\start-demo.bat'
if (Test-Path $lnk) { Say 'OK' "'Start BEC Demo' already on the desktop" }
elseif ($Apply) {
    $ws = New-Object -ComObject WScript.Shell
    $sc = $ws.CreateShortcut($lnk)
    $sc.TargetPath = $target
    $sc.WorkingDirectory = $AppDir
    $sc.Description = 'Start the BEC PMO demo server and public tunnel'
    $sc.IconLocation = "$XamppDir\apache\bin\httpd.exe,0"
    $sc.Save()
    Fixed "created 'Start BEC Demo' on the desktop"
} else { Say 'FIX' "would create a 'Start BEC Demo' desktop shortcut" }

# ---- 9. Does it actually run? ------------------------------------------------
Write-Host ""
Write-Host "  9. Live check" -ForegroundColor White
if (-not (Get-Process httpd -ErrorAction SilentlyContinue)) {
    if ($Apply) { Start-Process -FilePath $httpd -WindowStyle Hidden; Start-Sleep -Seconds 4; Fixed "started Apache" }
    else { Say 'FIX' "would start Apache to test" }
}
$folder = Split-Path -Leaf $AppDir
$url = "http://localhost/$folder/index.php"
try {
    $code = (Invoke-WebRequest $url -UseBasicParsing -TimeoutSec 15).StatusCode
    Say 'OK' "$url responds with HTTP $code"
} catch {
    Say 'FAIL' "$url did not respond: $($_.Exception.Message)"
    NeedsYou "Apache is not serving the app. Most common cause: port 80 is taken (Skype, IIS, VMware). Check with: netstat -ano | findstr :80"
}
$preflight = Join-Path $AppDir 'scripts\demo_preflight.php'
if (Test-Path $preflight) {
    Write-Host ""
    Write-Host "  Running the full pre-demo health check..." -ForegroundColor White
    & $php $preflight
}

# ---- Summary -----------------------------------------------------------------
Write-Host ""
Write-Host "  ============================================================" -ForegroundColor White
if ($script:fixed.Count) {
    Write-Host "  Changed on this machine:" -ForegroundColor Cyan
    $script:fixed | ForEach-Object { Write-Host "    - $_" }
}
if ($script:todo.Count) {
    Write-Host ""
    Write-Host "  Still needs a human ($($script:todo.Count)):" -ForegroundColor Yellow
    $i = 1
    $script:todo | ForEach-Object { Write-Host ("    {0}. {1}" -f $i, $_); $i++ }
} else {
    Write-Host "  Nothing left to do - this laptop is ready to serve." -ForegroundColor Green
}
if (-not $Apply) {
    Write-Host ""
    Write-Host "  This was a dry run. Re-run with -Apply to make the changes above." -ForegroundColor Yellow
}
Write-Host "  ============================================================" -ForegroundColor White
Write-Host ""
