<#
    carry_secrets.ps1 - move the credential files to another laptop safely.

    Three files this app needs are deliberately not in git, because the
    repository is public:

        .env                        Supabase database credentials
        config\chat_secrets.php     Gemini API key
        data\system_settings.json   Gmail SMTP settings

    A clone gives you the code and none of these, so the new laptop cannot
    reach the database until they are carried across. This packs them into one
    AES-encrypted file you can put on a USB stick, e-mail, or upload anywhere,
    and unpacks them on the other side.

    On the laptop that works:

        powershell -ExecutionPolicy Bypass -File scripts\carry_secrets.ps1 -Pack

    Copy the resulting bec-secrets.enc across, then on the new laptop:

        powershell -ExecutionPolicy Bypass -File scripts\carry_secrets.ps1 -Unpack

    You are asked for a passphrase both times. Send that passphrase by a
    different route than the file itself - a text message, or spoken. A file
    and its password travelling together in the same e-mail is the same as
    sending the files in the clear.

    The .enc file is gitignored. Do not commit it even so.
#>

param(
    [switch]$Pack,
    [switch]$Unpack,
    [string]$Bundle = (Join-Path (Split-Path -Parent $PSScriptRoot) 'bec-secrets.enc'),
    [string]$AppDir = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'

$FILES = @('.env', 'config\chat_secrets.php', 'data\system_settings.json')
$MAGIC = 'BECSEC1'

function Get-Key([string]$pass, [byte[]]$salt) {
    # 200k PBKDF2 rounds: slow enough that guessing a weak passphrase is costly.
    $kdf = New-Object System.Security.Cryptography.Rfc2898DeriveBytes($pass, $salt, 200000, [System.Security.Cryptography.HashAlgorithmName]::SHA256)
    return $kdf.GetBytes(32)
}

function Read-Passphrase([string]$prompt) {
    $secure = Read-Host -Prompt $prompt -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

if (-not $Pack -and -not $Unpack) {
    Write-Host ""
    Write-Host "  Use -Pack on the laptop that works, then -Unpack on the new one." -ForegroundColor Yellow
    Write-Host "  See the notes at the top of this file." -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

# ---------------------------------------------------------------- pack -------
if ($Pack) {
    $payload = @{}
    $missing = @()
    foreach ($rel in $FILES) {
        $p = Join-Path $AppDir $rel
        if (Test-Path $p) {
            $payload[$rel] = [Convert]::ToBase64String([System.IO.File]::ReadAllBytes($p))
            "  packing {0,-30} {1,6:N0} bytes" -f $rel, (Get-Item $p).Length
        } else {
            $missing += $rel
            Write-Host ("  missing  {0}" -f $rel) -ForegroundColor Yellow
        }
    }
    if ($payload.Count -eq 0) { Write-Host "  Nothing to pack." -ForegroundColor Red; exit 1 }

    $pass = Read-Passphrase "  Passphrase for the bundle"
    if ($pass.Length -lt 8) { Write-Host "  Use at least 8 characters." -ForegroundColor Red; exit 1 }
    $again = Read-Passphrase "  Type it again"
    if ($pass -ne $again) { Write-Host "  They do not match." -ForegroundColor Red; exit 1 }

    $json = $payload | ConvertTo-Json -Compress
    $plain = [Text.Encoding]::UTF8.GetBytes($json)

    $salt = New-Object byte[] 16
    [System.Security.Cryptography.RandomNumberGenerator]::Fill($salt)
    $aes = [System.Security.Cryptography.Aes]::Create()
    $aes.KeySize = 256; $aes.Mode = [System.Security.Cryptography.CipherMode]::CBC
    $aes.Key = Get-Key $pass $salt
    $aes.GenerateIV()
    $cipher = $aes.CreateEncryptor().TransformFinalBlock($plain, 0, $plain.Length)

    # A tag over salt+iv+ciphertext, so a wrong passphrase or a damaged file is
    # reported as such instead of producing garbage files.
    $hmac = New-Object System.Security.Cryptography.HMACSHA256(, (Get-Key $pass $salt))
    $body = $salt + $aes.IV + $cipher
    $tag = $hmac.ComputeHash($body)

    $out = [Text.Encoding]::ASCII.GetBytes($MAGIC) + $tag + $body
    [System.IO.File]::WriteAllBytes($Bundle, $out)
    $aes.Dispose(); $hmac.Dispose()

    Write-Host ""
    Write-Host ("  Written: {0} ({1:N0} bytes)" -f $Bundle, (Get-Item $Bundle).Length) -ForegroundColor Green
    if ($missing.Count) { Write-Host ("  Not included (absent here): " + ($missing -join ', ')) -ForegroundColor Yellow }
    Write-Host "  Carry that file across, and send the passphrase by a different route." -ForegroundColor Green
    Write-Host ""
    exit 0
}

# -------------------------------------------------------------- unpack -------
if (-not (Test-Path $Bundle)) { Write-Host "  No bundle at $Bundle" -ForegroundColor Red; exit 1 }
$raw = [System.IO.File]::ReadAllBytes($Bundle)
if ($raw.Length -lt 64 -or [Text.Encoding]::ASCII.GetString($raw[0..6]) -ne $MAGIC) {
    Write-Host "  That file is not a secrets bundle." -ForegroundColor Red; exit 1
}
$tag  = $raw[7..38]
$body = $raw[39..($raw.Length - 1)]
$salt = $body[0..15]
$iv   = $body[16..31]
$cipher = $body[32..($body.Length - 1)]

$pass = Read-Passphrase "  Passphrase"
$key = Get-Key $pass $salt
$hmac = New-Object System.Security.Cryptography.HMACSHA256(, $key)
$expect = $hmac.ComputeHash($body)
$hmac.Dispose()
if (-not [System.Linq.Enumerable]::SequenceEqual([byte[]]$expect, [byte[]]$tag)) {
    Write-Host "  Wrong passphrase, or the file was altered in transit." -ForegroundColor Red
    exit 1
}

$aes = [System.Security.Cryptography.Aes]::Create()
$aes.KeySize = 256; $aes.Mode = [System.Security.Cryptography.CipherMode]::CBC
$aes.Key = $key; $aes.IV = $iv
$plain = $aes.CreateDecryptor().TransformFinalBlock($cipher, 0, $cipher.Length)
$aes.Dispose()

$payload = [Text.Encoding]::UTF8.GetString($plain) | ConvertFrom-Json
foreach ($prop in $payload.PSObject.Properties) {
    $dest = Join-Path $AppDir $prop.Name
    $dir = Split-Path -Parent $dest
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    if (Test-Path $dest) {
        Copy-Item $dest "$dest.replaced_$(Get-Date -Format yyyyMMdd_HHmmss)" -Force
        Write-Host ("  existing {0} kept as a .replaced_ copy" -f $prop.Name) -ForegroundColor Yellow
    }
    [System.IO.File]::WriteAllBytes($dest, [Convert]::FromBase64String($prop.Value))
    "  restored {0,-30} {1,6:N0} bytes" -f $prop.Name, (Get-Item $dest).Length
}

Write-Host ""
Write-Host "  Done. Delete the .enc file and the passphrase once this laptop works." -ForegroundColor Green
Write-Host "  Check it with: php scripts\demo_preflight.php" -ForegroundColor Green
Write-Host ""
