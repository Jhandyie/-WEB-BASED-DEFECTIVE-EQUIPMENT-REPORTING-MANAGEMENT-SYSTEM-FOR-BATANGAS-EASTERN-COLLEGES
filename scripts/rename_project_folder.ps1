# Rename the project folder from "-WEB-BASED" to a cleaner name.
#
# The folder name is visible in every URL (http://localhost/bec-pmo/), and a
# leading hyphen reads like a typo to anyone shown the system.
#
# RUN THIS FROM c:\xampp\htdocs, NOT FROM INSIDE THE PROJECT. A directory cannot
# be renamed while the shell's working directory is inside it.
#
#   cd c:\xampp\htdocs
#   powershell -ExecutionPolicy Bypass -File ".\bec-pmo\scripts\rename_project_folder.ps1"          # dry run
#   powershell -ExecutionPolicy Bypass -File ".\bec-pmo\scripts\rename_project_folder.ps1" -Apply   # do it
#
# ASCII only, deliberately: Windows PowerShell reads .ps1 as ANSI, and a dash
# that is not a plain hyphen terminates the string literal it sits in.

param(
    [string]$OldName = "-WEB-BASED",
    [string]$NewName = "bec-pmo",
    [switch]$Apply
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot          # ...\htdocs\bec-pmo
$htdocs = Split-Path -Parent $root                # ...\htdocs
$oldPath = Join-Path $htdocs $OldName
$newPath = Join-Path $htdocs $NewName

Write-Output ""
Write-Output "  from : $oldPath"
Write-Output "  to   : $newPath"
Write-Output ""

if (-not (Test-Path $oldPath)) { Write-Output "  The source folder does not exist. Nothing to do."; exit 1 }
if (Test-Path $newPath)        { Write-Output "  '$NewName' already exists. Move or delete it first."; exit 1 }

# Refuse to run from inside the folder being renamed.
$cwd = (Get-Location).Path
if ($cwd.ToLower().StartsWith($oldPath.ToLower())) {
    Write-Output "  Refusing: your current directory is inside the folder being renamed."
    Write-Output "  Run 'cd c:\xampp\htdocs' first."
    exit 1
}

# Only a path or URL segment is rewritten. Two things must survive untouched:
#   - the GitHub repo slug, which begins -WEB-BASED-DEFECTIVE-EQUIPMENT-...
#   - the project's title in prose, "Web-Based Defective Equipment Reporting..."
# Both contain the string but neither is a path. Requiring a slash before, and a
# slash/quote/space/end after, separates them cleanly.
$pattern = '(?<=[/\\])' + [regex]::Escape($OldName) + '(?=[/\\''"\s)]|$)'

$exts = @("*.php","*.md","*.bat","*.ps1","*.js","*.html","*.css","*.json","*.txt")
$files = Get-ChildItem -Path $oldPath -Recurse -File -Include $exts |
         Where-Object { $_.FullName -notmatch '\\(\.git|backups|node_modules|vendor)\\' }

$hits = @()
foreach ($f in $files) {
    $text = Get-Content -Raw -LiteralPath $f.FullName -ErrorAction SilentlyContinue
    if ($null -eq $text) { continue }
    $n = ([regex]::Matches($text, $pattern)).Count
    if ($n -gt 0) { $hits += [pscustomobject]@{ File = $f.FullName.Substring($oldPath.Length + 1); Count = $n } }
}

$total = ($hits | Measure-Object -Property Count -Sum).Sum
Write-Output "  $($hits.Count) file(s), $total path reference(s) to rewrite:"
foreach ($h in $hits) { Write-Output ("    {0,3}  {1}" -f $h.Count, $h.File) }

if (-not $Apply) {
    Write-Output ""
    Write-Output "  DRY RUN. Nothing changed. Re-run with -Apply to perform the rename."
    exit 0
}

Write-Output ""
Write-Output "  Renaming the folder..."
# Windows will not rename a directory that any process holds open - including an
# editor with it open as a workspace, and any shell whose working directory is
# inside it. The bare error for this is "used by another process", which names
# neither the folder nor the culprit, so check first and say something useful.
$holders = Get-Process -ErrorAction SilentlyContinue |
           Where-Object { $_.ProcessName -match '^(Code|devenv|idea64|sublime_text|notepad\+\+)$' }
if ($holders) {
    $names = ($holders | Select-Object -ExpandProperty ProcessName -Unique) -join ", "
    Write-Output ""
    Write-Output "  STOPPED. An editor is running: $names"
    Write-Output "  If it has this folder open as a workspace it holds a handle on it and"
    Write-Output "  the rename will fail. Close it, then run this again."
    Write-Output ""
    Write-Output "  Also close any terminal sitting inside the folder (cd somewhere else),"
    Write-Output "  and stop Apache from the XAMPP Control Panel."
    exit 1
}
try {
    Rename-Item -LiteralPath $oldPath -NewName $NewName -ErrorAction Stop
} catch {
    Write-Output ""
    Write-Output "  FAILED: $($_.Exception.Message)"
    Write-Output ""
    Write-Output "  Something still holds the folder open. In order of likelihood:"
    Write-Output "    - an editor with the folder open as a workspace"
    Write-Output "    - a terminal whose current directory is inside it"
    Write-Output "    - Apache (stop it in the XAMPP Control Panel)"
    Write-Output "    - File Explorer with the folder selected"
    Write-Output ""
    Write-Output "  Nothing was changed. Close them and run this again."
    exit 1
}
Write-Output "  Rewriting references..."

$changed = 0
foreach ($h in $hits) {
    $p = Join-Path $newPath $h.File
    if (-not (Test-Path -LiteralPath $p)) { continue }
    $text = Get-Content -Raw -LiteralPath $p
    $new = [regex]::Replace($text, $pattern, $NewName)
    if ($new -ne $text) { Set-Content -LiteralPath $p -Value $new -NoNewline; $changed++ }
}

Write-Output "  Rewrote $changed file(s)."
Write-Output ""
Write-Output "  DONE. The app is now at http://localhost/$NewName/"
Write-Output ""
Write-Output "  Four things this script cannot reach - fix them by hand:"
Write-Output "    1. The scheduled backup job still points at the old path. Re-create it:"
Write-Output "       schtasks /Delete /TN ""BEC PMO DB Backup"" /F"
Write-Output "       schtasks /Create /SC DAILY /ST 01:30 /TN ""BEC PMO DB Backup"" ``"
Write-Output "         /TR ""\""C:\xampp\php\php.exe\"" \""C:\xampp\htdocs\$NewName\scripts\backup_db.php\"""""
Write-Output "    2. The ngrok / tunnel URL now serves /$NewName/ - regenerate the demo QR code:"
Write-Output "       c:\xampp\php\php.exe scripts\make_demo_qr.php"
# Built from the variable, not written literally: the rewrite pass runs over this
# file too, and a literal old name here would be turned into the new one - the
# advice would then name the folder you just moved away from.
Write-Output ("    3. Any browser bookmarks or a printed QR code pointing at /" + $OldName + "/.")
Write-Output "    4. The GitHub repository name is unchanged and does not need to change;"
Write-Output "       the git remote is unaffected by renaming a local folder."
Write-Output ""
Write-Output "  Then verify:"
Write-Output "    cd c:\xampp\htdocs\$NewName"
Write-Output "    c:\xampp\php\php.exe scripts\demo_preflight.php"
