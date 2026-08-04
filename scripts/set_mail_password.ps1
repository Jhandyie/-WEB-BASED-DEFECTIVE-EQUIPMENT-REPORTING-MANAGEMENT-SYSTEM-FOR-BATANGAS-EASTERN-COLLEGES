<#
    set_mail_password.ps1 - put a new Gmail app password into the mail settings.

    The sending account's app password appears in ten places in
    data\system_settings.json (one per role, plus the default). Editing them by
    hand means ten chances to typo one and have mail silently fail for, say,
    technicians only. This asks once and writes all of them.

        powershell -ExecutionPolicy Bypass -File scripts\set_mail_password.ps1

    The password is typed hidden and never appears on screen, in a file you
    have to clean up, or in this repository - data\system_settings.json is
    gitignored.

    A timestamped backup of the settings file is kept beside it.

    Get the app password from myaccount.google.com while signed in as the
    sending account: Security, then 2-Step Verification, then App passwords.
#>

param(
    [string]$SettingsFile = (Join-Path (Split-Path -Parent $PSScriptRoot) 'data\system_settings.json'),
    # Only for automated checks. Prefer the prompt: a command line is visible in
    # the process list and stays in shell history, a typed password is not.
    [string]$Password
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $SettingsFile)) {
    Write-Host "  No settings file at $SettingsFile" -ForegroundColor Red
    exit 1
}

$json = Get-Content $SettingsFile -Raw
$settings = $json | ConvertFrom-Json

# Which account are we setting the password for? Read it back so nobody
# updates the wrong mailbox.
$account = $null
foreach ($p in $settings.email.PSObject.Properties) {
    if ($p.Value -is [string] -and $p.Name -match 'smtp_user' -and $p.Value -match '@') { $account = $p.Value }
    elseif ($p.Value -isnot [string] -and $p.Value.smtp_username) { $account = $p.Value.smtp_username }
}
if (-not $account -and $settings.email.smtp_username) { $account = $settings.email.smtp_username }

Write-Host ""
Write-Host "  Sending account: $account" -ForegroundColor White
Write-Host "  This will set its app password everywhere it appears in the settings." -ForegroundColor Gray
Write-Host ""

if ($Password) {
    $pass = $Password
} else {
    $secure = Read-Host -Prompt "  New app password (hidden)" -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { $pass = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

# Google shows app passwords in four groups of four. It works either way, but
# strip the spaces so the stored value is what the SMTP server receives.
$pass = $pass -replace '\s', ''
if ($pass.Length -lt 12) {
    Write-Host "  That does not look like an app password (expected 16 characters)." -ForegroundColor Red
    exit 1
}

$backup = "$SettingsFile.backup_$(Get-Date -Format yyyyMMdd_HHmmss)"
Copy-Item $SettingsFile $backup

# Walk the whole structure so a role added later is covered too.
$count = 0
function Set-Passwords($node) {
    if ($null -eq $node) { return }
    foreach ($prop in $node.PSObject.Properties) {
        if ($prop.Name -match 'smtp_password') {
            $prop.Value = $script:pass
            $script:count++
        } elseif ($prop.Value -is [System.Management.Automation.PSCustomObject]) {
            Set-Passwords $prop.Value
        } elseif ($prop.Value -is [System.Object[]]) {
            foreach ($item in $prop.Value) {
                if ($item -is [System.Management.Automation.PSCustomObject]) { Set-Passwords $item }
            }
        }
    }
}
$script:pass = $pass
$script:count = 0
Set-Passwords $settings

# UTF-8 with NO byte-order mark. Windows PowerShell's -Encoding UTF8 writes a
# BOM, and PHP's json_decode() returns null on a file that starts with one - so
# the settings would look fine in an editor while every mail lookup silently
# failed. Written through .NET to keep the encoding explicit on both PowerShell
# 5.1 and 7.
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($SettingsFile, ($settings | ConvertTo-Json -Depth 20), $utf8NoBom)

Write-Host ""
Write-Host ("  Updated {0} password field(s)." -f $script:count) -ForegroundColor Green
Write-Host ("  Previous settings kept as {0}" -f (Split-Path -Leaf $backup)) -ForegroundColor Gray
Write-Host ""
Write-Host "  Now send yourself a test:" -ForegroundColor White
Write-Host "    php scripts\demo_preflight.php     (checks the mailbox answers)" -ForegroundColor Gray
Write-Host "    php scripts\e2e_smoke.php          (files a real report, which e-mails a ticket)" -ForegroundColor Gray
Write-Host ""
Write-Host "  Once mail works, re-pack the bundle so the other laptop gets the new password:" -ForegroundColor White
Write-Host "    powershell -File scripts\carry_secrets.ps1 -Pack" -ForegroundColor Gray
Write-Host ""
