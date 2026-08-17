# Setting up the demo server on another laptop

This is the whole job of moving the BEC PMO system onto a different Windows
laptop so it can serve the demo without sleeping.

Most of it is automated. Run the script, then work through whatever it lists as
still needing a human.

```powershell
powershell -ExecutionPolicy Bypass -File scripts\setup_new_machine.ps1          # report only
powershell -ExecutionPolicy Bypass -File scripts\setup_new_machine.ps1 -Apply   # make the changes
```

It is safe to run repeatedly. It changes only what is not already correct, and
backs up `php.ini` before touching it.

---

## What you need before you start

| Thing | Where from | Notes |
|---|---|---|
| XAMPP with **PHP 8.2** | <https://www.apachefriends.org> | The script cannot install this. |
| The project folder | USB stick, or `git clone` | Must end up inside `C:\xampp\htdocs\`. |
| `.env` | **the old laptop** | Supabase credentials. Not in git. |
| `data\system_settings.json` | **the old laptop** | Gmail SMTP settings. Not in git. |
| `config\chat_secrets.php` | **the old laptop** | Gemini key for the assistant. Optional — without it the assistant falls back to its built-in answers. |
| ngrok | <https://ngrok.com/download> | Plus the account that owns the reserved demo URL. |

> **The three files above are deliberately not in git.** They hold passwords and
> API keys. Copy them across by hand — a `git clone` alone gives you an app that
> cannot reach the database.

---

## Step by step

### 1. Install XAMPP

Install to `C:\xampp` (the default). Any other location means editing the paths
in `scripts\start-demo.bat`.

When the installer offers components, Apache and PHP are required. MySQL is
**not** — this system's data lives in Supabase (Postgres), not in a local MySQL.

### 2. Copy the project in

Put the folder at:

```
C:\xampp\htdocs\bec-pmo
```

The folder name becomes part of every URL, so keep it exactly as it is unless
you intend to change the addresses everyone has.

### 3. Copy the three secret files

From the same paths on the old laptop:

```
.env
data\system_settings.json
config\chat_secrets.php
```

**These must never be pushed to GitHub.** The repository is public, so a
Supabase service-role key, a Gmail password and a Gemini key committed there
are readable by anyone — and automated scrapers find new secrets in public
repositories within minutes. It would mean rotating all three, and until then
anyone could read or delete the student data.

Either copy the three files by hand on a USB stick, or use the helper, which
packs them into one AES-encrypted file you can send by any route:

```powershell
# on the laptop that works
powershell -ExecutionPolicy Bypass -File scripts\carry_secrets.ps1 -Pack

# copy bec-secrets.enc across, then on the new laptop
powershell -ExecutionPolicy Bypass -File scripts\carry_secrets.ps1 -Unpack
```

Send the passphrase by a different route than the file — a text message, or
spoken. A file and its password in the same e-mail is the same as sending the
files in the clear. Delete the `.enc` file once the new laptop works.

### 4. Run the setup script

```powershell
cd C:\xampp\htdocs\bec-pmo
powershell -ExecutionPolicy Bypass -File scripts\setup_new_machine.ps1 -Apply
```

It will:

* confirm PHP 8.x and that XAMPP is where it expects
* enable the PHP extensions the app needs — **`pdo_pgsql` above all**, because
  the database is Postgres and without it every page fails on its first query
* set the `php.ini` values this app depends on:

  | setting | value | why |
  |---|---|---|
  | `upload_max_filesize` | 40M | report photos |
  | `post_max_size` | 40M | several photos in one submission |
  | `memory_limit` | 512M | exports and backups |
  | `max_execution_time` | 120 | Supabase round trips are slow |
  | `max_file_uploads` | 20 | before/during/after photo sets |
  | `opcache.enable` | **0** | see the warning below |

* create `uploads\`, `logs\`, `data\`, `backups\` if missing
* set sleep, hibernate, monitor and disk timeouts to **Never** on mains power
* put a **Start BEC Demo** shortcut on the desktop
* start Apache and run the full pre-demo health check

### 5. Finish the items it lists

Typically:

1. **Set the lid behaviour.** Windows Settings → Power → *When I close the lid*
   → **Do nothing**. No script can override the lid switch; closing the lid
   suspends the machine and takes the demo with it.
2. **Install and sign in to ngrok**, then set the `NGROK` path in
   `scripts\start-demo.bat`.
3. **The reserved demo URL** in `start-demo.bat` belongs to one ngrok account.
   Either sign in as that account on this laptop, or change `DEMO_URL` and
   reprint the QR sheet with `php scripts\make_demo_qr.php <new-url>`.

### 6. Prove it works before demo day

```powershell
php scripts\e2e_smoke.php
```

This walks one report through the entire workflow over real HTTP — reporter
submits, PMO receives and approves, technician accepts, starts and completes
with a photo, PMO verifies, reporter confirms — then deletes everything it
created. It should end with **13/13 ALL GREEN**. If it does, the laptop is
ready.

---

## Running a demo

Double-click **Start BEC Demo** on the desktop. It:

1. starts Apache if it is not running
2. runs the health check and refuses to go public if something is broken
3. holds the laptop awake for as long as the window is open
4. **watches Apache and restarts it within seconds if it crashes** (see below)
5. opens the public tunnel and prints the URL and phone-install instructions

Keep that window open. Closing it stops the tunnel and releases the sleep hold.

---

## Two things that will bite you

### Apache crashes on its own

On the old laptop, Apache died roughly once a day with no clean shutdown, filling
the error log with `VirtualProtect() failed [87]` from PHP's opcache. That is why
`opcache.enable=0` is in the settings table above.

It costs almost nothing here: opcache saved 14–25 ms per page, while a single
Supabase round trip takes about 429 ms and every page makes several. The
measurement is in the commit history if you want it.

`scripts\apache_watchdog.ps1` runs during every demo as a second line of
defence. It requests a real page every 15 seconds — a wedged `httpd.exe` still
appears in the task list, so checking the process is not enough — and restarts
Apache after two consecutive failures, logging to `logs\watchdog.log`.

If Apache still dies on the new laptop, check whether the count is rising:

```powershell
(Select-String C:\xampp\apache\logs\error.log -Pattern "VirtualProtect" -AllMatches).Count
```

### Port 80 is often already taken

Skype, IIS and VMware all like port 80. If Apache will not start:

```powershell
netstat -ano | findstr :80
```

Stop whatever owns it, or change Apache's port in `httpd.conf` (and then in the
demo URL).

---

## If you edit the PowerShell scripts

**Keep them ASCII.** Windows PowerShell reads `.ps1` files as ANSI, and an
em-dash inside a *string literal* ends with byte `0x94`, which CP1252 reads as a
closing smart quote. It terminates the string and the script fails to parse.
Em-dashes inside `#` comments are harmless. This is exactly how the watchdog
failed the first time it ran.

---

## Quick reference

| Task | Command |
|---|---|
| Set up this laptop | `powershell -ExecutionPolicy Bypass -File scripts\setup_new_machine.ps1 -Apply` |
| Check before a demo | `php scripts\demo_preflight.php` |
| Full lifecycle test | `php scripts\e2e_smoke.php` |
| Start the demo | Desktop → **Start BEC Demo** |
| Local address | `http://localhost/bec-pmo/` |
| Watchdog log | `logs\watchdog.log` |
| Apache log | `C:\xampp\apache\logs\error.log` |
