# Deployment Guide — BEC PMO Equipment Reporting System

How to take the system from your local XAMPP to a real, HTTPS-served deployment that
students, the PMO, and technicians can actually use.

The good news: **the database is already in the cloud** (Supabase PostgreSQL) and the code
builds every URL dynamically from the request host — nothing is hardcoded to `localhost`.
Deployment is mostly: put the PHP files on a host, recreate the secret files, and switch on HTTPS.

---

## Option A — Instant public demo (no hosting, 10 minutes)

Perfect for a defense/panel demo from your own laptop. Cloudflare Tunnel gives your local
XAMPP a real public **HTTPS** URL:

```powershell
winget install Cloudflare.cloudflared
cloudflared tunnel --url http://localhost:80
```

It prints a URL like `https://random-words.trycloudflare.com` — share
`https://…trycloudflare.com/bec-pmo/` and everything works over HTTPS
(including PWA installs and OTP emails), as long as your laptop stays on.

> Limits: the URL changes each run and dies when the tunnel closes. Demo tool, not production.

## Option B — Shared hosting (recommended for real use)

A small PHP shared-hosting plan (e.g., Hostinger / Namecheap-class, ~₱100–200/mo) is enough.
**Before paying, confirm the plan has:**

| Requirement | Why |
|---|---|
| PHP **8.x** | the codebase targets PHP 8 |
| **`pdo_pgsql`** extension | the Supabase adapter connects over PostgreSQL — this is the #1 thing cheap hosts lack |
| `curl` + `openssl` | Becca AI proxies + TLS SMTP |
| **Outbound SMTP (port 587)** allowed | Gmail sending — many *free* hosts block this (why free hosts are not recommended) |
| Apache with `.htaccess` (`AllowOverride All`) | the security rules live in `.htaccess` |
| Cron jobs | daily database backup |
| Free SSL (Let's Encrypt) | HTTPS |

No Composer, no Node, no database server needed on the host — Supabase stays as-is.

### Steps

1. **Upload the code** — `git clone` your repo in the host's file manager/SSH, or upload a ZIP
   of the project (minus the gitignored files) into `public_html/` (or a subfolder).

2. **Recreate the secret files** (they are gitignored on purpose — copy them from your PC):
   - `.env` — Supabase connection (same values as local)
   - `config/chat_secrets.php` — Gemini API key
   - `data/system_settings.json` — SMTP accounts (`from_name: "BEC PMO"`, Gmail app password)
   - `config/sla.php` — SLA hours (already committed; adjust if needed)

3. **Make these writable** by PHP (usually 755/775 on shared hosting):
   ```
   uploads/          (defect + completion photos)
   api/data/         (inventory.json)
   data/             (rate limiter state)
   logs/             (chat + app logs)
   backups/          (database archives)
   ```

4. **Schedule the backup** (replaces the Windows task) — hosting panel → Cron Jobs:
   ```
   0 18 * * *  php /home/USER/public_html/scripts/backup_db.php
   ```

5. **Force HTTPS** — add at the *top* of `.htaccess`:
   ```apache
   RewriteEngine On
   RewriteCond %{HTTPS} !=on
   RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
   ```

6. **Production PHP settings** (hosting panel → PHP options):
   - `display_errors = Off` (errors to log only — protects the JSON endpoints and looks professional)
   - `upload_max_filesize = 40M`, `post_max_size = 40M` (matches the photo limits the UI enforces)
   - `session.cookie_secure = On` once HTTPS is live

## Option C — VPS (full control)

A ₱250–350/mo VPS (DigitalOcean, Lightsail, Vultr) running Ubuntu + Apache + PHP 8:

```bash
sudo apt install apache2 php php-pgsql php-curl php-mbstring
sudo a2enmod rewrite && sudo certbot --apache   # Let's Encrypt HTTPS
```

Then follow the same steps as Option B (clone, secrets, permissions, cron). Only pick this if
someone is comfortable maintaining a server — shared hosting is less work for the same result.

---

## What changes automatically (no edits needed)

- **All links and emails** — the landing page, OTP mails, and assignment deep links are all
  built from the live request host.
- **The PWA** — manifest + service worker use relative paths; once HTTPS is on, phones will
  offer "Add to Home Screen" for the technician portal for real.
- **Supabase** — same database from anywhere; nothing migrates.

## What to double-check after going live

1. Landing page loads (`/`), portals route correctly.
2. **Admin OTP email arrives** (proves SMTP works from the host).
3. Submit a test defect report → confirmation email arrives.
4. Technician login + open a task; install the PWA on a phone.
5. Branded PDF/Excel exports download.
7. Next morning: a new archive exists in `backups/`.
8. `https://your-site/data/system_settings.json` returns **403** (secrets blocked)
   — same for `/config/`, `/logs/`, `/backups/`, `/.env`.

## Security notes for production

- Never commit the secret files; recreate them on the server only.
- Rotate the Gmail app password and Gemini key if they were ever shared.
- Consider institutional mailboxes (e.g., `pmo@bec.edu.ph` via Google Workspace SMTP) instead
  of a personal Gmail — see `docs/EMAIL_DELIVERABILITY.md` for the SPF/DKIM/DMARC setup.
- Keep the GitHub repository **private**; access tokens should be short-lived and revoked after use.

---

*Batangas Eastern Colleges · Property Management Office*
