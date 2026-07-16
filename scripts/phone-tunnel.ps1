<#
  phone-tunnel.ps1 — expose the local BEC PMO app over a temporary public HTTPS URL so a
  technician's phone can INSTALL the app (PWA install requires a secure https:// origin).

  It uses Cloudflare's free "quick tunnel" (no account, no login). The tool is downloaded
  once into scripts\tools\ the first time you run this. Leave this window open during your
  demo; press Ctrl+C to stop the tunnel.
#>

$ErrorActionPreference = 'Stop'

# ── Config ────────────────────────────────────────────────────────────────
$Port    = 80                                        # Apache port (XAMPP default)
$AppPath = '/-WEB-BASED/technician/login.html'       # where a technician lands
$Root    = Split-Path -Parent $MyInvocation.MyCommand.Path
$Tools   = Join-Path $Root 'tools'
$Cf      = Join-Path $Tools 'cloudflared.exe'
$CfUrl   = 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe'

function Write-Banner([string]$msg, [string]$color = 'Cyan') {
  Write-Host ''
  Write-Host ('=' * 64) -ForegroundColor $color
  Write-Host $msg -ForegroundColor $color
  Write-Host ('=' * 64) -ForegroundColor $color
  Write-Host ''
}

# ── 1. Is Apache up on the port? ──────────────────────────────────────────
try {
  $test = Test-NetConnection -ComputerName 'localhost' -Port $Port -WarningAction SilentlyContinue
  if (-not $test.TcpTestSucceeded) {
    Write-Banner "Apache is not answering on http://localhost:$Port`nStart XAMPP (Apache) first, then re-run this." 'Yellow'
    Read-Host 'Press Enter to exit'; exit 1
  }
} catch { }

# ── 2. Ensure cloudflared is available ────────────────────────────────────
if (-not (Test-Path $Cf)) {
  if (-not (Test-Path $Tools)) { New-Item -ItemType Directory -Path $Tools | Out-Null }
  Write-Banner 'First run: downloading the tunnel tool (cloudflared, ~18 MB)…' 'Cyan'
  try {
    Invoke-WebRequest -Uri $CfUrl -OutFile $Cf -UseBasicParsing
  } catch {
    Write-Banner "Could not download cloudflared automatically.`nDownload it manually from:`n  $CfUrl`nSave it as:`n  $Cf`nthen re-run." 'Yellow'
    Read-Host 'Press Enter to exit'; exit 1
  }
  Write-Host 'Downloaded.' -ForegroundColor Green
}

# ── 3. Start the tunnel and surface the phone URL ─────────────────────────
Write-Banner "Starting a secure HTTPS tunnel to http://localhost:$Port …`nKeep this window OPEN during your demo. Press Ctrl+C to stop." 'Cyan'

$announced = $false
& $Cf tunnel --url "http://localhost:$Port" 2>&1 | ForEach-Object {
  $line = "$_"
  Write-Host $line -ForegroundColor DarkGray
  if (-not $announced -and $line -match 'https://[a-z0-9-]+\.trycloudflare\.com') {
    $phoneUrl = "$($Matches[0])$AppPath"
    $announced = $true
    Write-Banner "OPEN THIS ON THE TECHNICIAN'S PHONE (Chrome on Android / Safari on iPhone):`n`n    $phoneUrl`n`nThen: Android Chrome -> menu -> Install app  |  iPhone Safari -> Share -> Add to Home Screen." 'Green'
  }
}
