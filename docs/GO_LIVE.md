# Going live on your own domain

How to take this system from XAMPP on your laptop to `https://yourdomain.com`, served
publicly, with a real certificate.

This is not a generic recipe. Every command and every number here is what
**becpmo.com actually runs today** — the configuration in the appendix was read off the
live machine, not imagined. Where something is a trap, it is marked, because all of those
traps have already cost someone an afternoon.

**Time:** about 90 minutes the first time, most of it waiting for DNS.
**Cost:** a domain (~₱600/year) plus a small VPS (~₱250–350/month).

---

## What you are building

```
   your domain  --DNS A record-->  a VPS with a public IP
                                        |
                                   Apache 2.4 + PHP 8.3
                                   serving /var/www/bec-pmo
                                        |
                                   +----+-----+
                            Supabase          Gmail SMTP
                            (Postgres,        (the OTP codes
                             already live)     and notices)
```

Two things are **already done** and do not move:

- **The database.** It is Supabase Postgres in the cloud. It is the same database from your
  laptop, from the VPS, from anywhere. Nothing migrates, nothing is exported, nothing is
  imported. This is the single biggest reason deployment here is short.
- **The URLs.** Nothing is hardcoded to `localhost`. Every link in every page and every
  email is built from the host the request arrived on, so the moment the domain resolves,
  every link is a `yourdomain.com` link. You do not edit a config file for this.

So going live is really only: *rent a machine, point the name at it, copy three secret
files, ask for a certificate.*

---

## Before you start

You need four things. Get all four before touching the server — the order matters, because
DNS is slow and everything else waits on it.

| | What | Where from |
|---|---|---|
| 1 | **A domain** | Hostinger, Namecheap, GoDaddy. `becpmo.com` is at Hostinger. |
| 2 | **A VPS** running Ubuntu 22.04 or 24.04 | Hostinger VPS, DigitalOcean, Vultr, Lightsail. 1 GB RAM is enough; the provisioning script adds swap if it is that small. |
| 3 | **The three secret files** from your laptop | `.env`, `config/chat_secrets.php`, `data/system_settings.json`. They are gitignored on purpose and are **not** in the repository. |
| 4 | **Your `uploads/` folder** | ~73 MB of report photos. Not in git either. |

> **Do not use free hosting.** Most free PHP hosts lack the `pdo_pgsql` extension, without
> which the app cannot reach Supabase at all, and most block outbound port 587, without
> which nobody can sign in — the entire login is an emailed code. A free host looks like it
> works right up until the first person tries to log in.

---

## Step 1 — Point the domain at the machine

**Do this first.** DNS takes anywhere from two minutes to a few hours to spread, and the
certificate in Step 5 cannot be issued until it has. Starting here means the waiting
overlaps with the rest of the work.

In your registrar's DNS panel, create **two A records**:

| Type | Name | Value | TTL |
|---|---|---|---|
| A | `@` | your VPS's public IP | 300 (or the lowest offered) |
| A | `www` | the same IP | 300 |

`@` means the bare domain. Both records are needed: the certificate is requested for both
names, and `certbot` aborts if it cannot match one of them to a vhost — *after* having
already issued the certificate, which is the confusing part.

Set the TTL low **before** you go live, not after. TTL is how long the rest of the internet
is allowed to cache the old answer, so a 24-hour TTL set today is a 24-hour wait if you
have to change the IP tomorrow.

Check it has taken effect **from the VPS**, not from your laptop — your laptop's network
may resolve differently:

```bash
getent hosts yourdomain.com          # should print your VPS IP
curl -fsS https://api.ipify.org      # should print the same IP
```

When those two agree, move on. Until they agree, Step 5 will fail.

---

## Step 2 — Get onto the machine

```bash
ssh root@YOUR_SERVER_IP
```

If the provider gave you a non-root user, prefix the rest with `sudo`.

---

## Step 3 — Provision it

`scripts/provision_vm.sh` does the whole build. Read it before running it as root — it is
about 240 commented lines.

```bash
apt update && apt install -y git
git clone https://github.com/YOUR-ORG/YOUR-REPO.git /var/www/bec-pmo
bash /var/www/bec-pmo/scripts/provision_vm.sh --domain yourdomain.com
```

Safe to run twice; it changes only what is not already correct. What it does:

- installs Apache, PHP 8.3 and **`php-pgsql`** — the extension supplying `pdo_pgsql`,
  without which there is no database at all;
- writes the vhost with **`AllowOverride All`**. This is the setting people miss. Every
  `.htaccess` in this project is ignored without it — *including the ones keeping `data/`
  off the web, which is where the Gmail app password lives*;
- raises `upload_max_filesize` and `post_max_size` to **40M** and sets the timezone to
  **Asia/Manila**. The stock defaults are 2M and UTC; at 2M a phone photo fails to upload,
  and the reporter form requires a photo, so the form becomes unusable;
- adds 2 GB of swap if the machine has under 2 GB of RAM;
- opens ports 22, 80 and 443 in `ufw`;
- installs the two cron jobs below;
- requests the HTTPS certificate, **if** `--domain` was given and already resolves here;
- then checks the things that hurt to discover late: `pdo_pgsql` loaded, outbound SMTP 587
  open, Supabase reachable.

> **Your cloud provider's firewall is separate from `ufw`.** Open 80 and 443 in the
> provider's own web console too, or the site stays unreachable no matter what the machine
> reports.

### The two scheduled jobs

```
/etc/cron.d/bec-pmo-backup    0 18 * * *     backup_db.php     (02:00 Manila)
/etc/cron.d/bec-pmo-sweeps    */15 * * * *   run_sweeps.php
```

The sweeps job is not decoration. It escalates reports past their SLA window **and drains
`data/mail_outbox/`**, which is where every deferred notification sits. Admin pages defer
their mail so the operator is not made to wait on an SMTP handshake — so without this job
the pages stay fast and the mail is simply never sent, and nothing reports it, because from
the app's side the message was handed off successfully.

---

## Step 4 — Copy the secrets and the photos

Only you can do this. They are not in the repository, by design.

From your laptop:

```powershell
scp .env root@YOUR_IP:/var/www/bec-pmo/.env
scp config/chat_secrets.php root@YOUR_IP:/var/www/bec-pmo/config/
scp data/system_settings.json root@YOUR_IP:/var/www/bec-pmo/data/
scp -r uploads/* root@YOUR_IP:/var/www/bec-pmo/uploads/
```

`scripts/carry_secrets.ps1` will bundle the three files encrypted if you would rather move
them that way.

Then, on the server, hand the tree to Apache and lock the secrets back down:

```bash
cd /var/www/bec-pmo
chown -R www-data:www-data .
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 600 .env config/chat_secrets.php data/system_settings.json
```

> **The `chmod 600` is last on purpose.** The recursive `644` above it re-opens the secret
> files if you run these in any other order. This is also why the same two lines close
> every routine update in Step 7 — it is easy to widen them by accident and never notice.

---

## Step 5 — HTTPS

If Step 3 ran with `--domain` and DNS had already propagated, this is done. Check:

```bash
certbot certificates
```

You want your domain, both names, and an expiry roughly 90 days out. If it did not run, do
it by hand now that DNS resolves:

```bash
certbot --apache -d yourdomain.com -d www.yourdomain.com --agree-tos --redirect
```

`--redirect` is what adds the port-80 vhost that 301s everything to HTTPS.

**Renewal is automatic.** `certbot.timer` runs twice a day and renews inside the last 30
days. You do not need a cron job for it and you should not add one. Confirm the timer is
alive:

```bash
systemctl list-timers | grep certbot
```

---

## Step 6 — Prove it actually works

Do not give anyone the address until these pass. The first one matters most.

```bash
# 1. The secrets are NOT downloadable. Anything but 403 or 404 means your Gmail
#    app password and your Supabase credentials are on the public internet.
curl -sI https://yourdomain.com/.env                       | head -1
curl -sI https://yourdomain.com/data/system_settings.json  | head -1
curl -sI https://yourdomain.com/config/chat_secrets.php    | head -1

# 2. The app's own health check - 16 points, exit 0 means safe to demo.
php /var/www/bec-pmo/scripts/demo_preflight.php

# 3. HTTP is redirected, not served.
curl -sI http://yourdomain.com | head -1        # expect 301
```

Then by hand, in a browser:

1. The landing page loads and the three portals route correctly.
2. **An admin OTP email arrives.** This is the real SMTP test — if it fails, nobody can
   sign in, and the cause is almost always port 587 blocked by the cloud provider.
3. Submit a test defect report from a phone. The confirmation email arrives and the photo
   upload succeeds — that is what proves the 40M limit took effect.
4. A technician can sign in and open a task.
5. **On a real iPhone and a real Android phone**, not a desktop browser resized: the camera
   button must open the camera, not the file picker. There is no other way to test this.
6. Tomorrow morning, a new archive exists in `/var/www/bec-pmo/backups/`.

---

## Step 7 — Pushing an update afterwards

This is the routine, every time, once the site is live. From your laptop:

```bash
php scripts/ui_smoke.php        # the interface still works
php scripts/e2e_smoke.php       # the whole lifecycle still works
rm -f data/mail_outbox/*.json   # the E2E can mail real staff - clear it
git add -A && git commit && git push origin main
```

Then on the server:

```bash
cd /var/www/bec-pmo
git pull --ff-only
chown -R www-data:www-data .
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 600 .env config/chat_secrets.php data/system_settings.json
```

**Run the smoke tests before pushing, not after.** `e2e_smoke.php` posts over raw HTTP and
does not execute browser JavaScript, so it stays green while the interface is completely
broken for real users — that has happened. `ui_smoke.php` is the one that loads the pages
in a real browser and asserts against the DOM the browser produced.

---

## When it breaks

| What you see | What it is |
|---|---|
| The site does not load at all | The cloud provider's firewall, not `ufw`. Open 80 and 443 in their web console. |
| "Connection refused" on the domain, but the IP works | DNS has not propagated, or the A record points at the wrong IP. |
| Every page is a database error | `pdo_pgsql` missing, or `.env` did not copy. Check with `php -r 'var_dump(extension_loaded("pdo_pgsql"));'` |
| Nobody receives their login code | Outbound port 587 is blocked. Common on Oracle Cloud; ask support to lift it. |
| Mail "sends" but never arrives | The sweeps cron is not running. Check `ls /etc/cron.d/bec-pmo-sweeps` and `logs/sweeps.log`. |
| Photo upload fails from phones | `upload_max_filesize` is still 2M. It lives in `/etc/php/8.3/apache2/php.ini` — the **apache2** one, not the `cli` one. Reload Apache after editing. |
| `/.env` returns the file instead of 403 | `AllowOverride All` is missing from the vhost, so every `.htaccess` is being ignored. Fix it and `systemctl reload apache2` **immediately** — your credentials are public until you do. |
| `git pull` says "dubious ownership" | The tree is owned by `www-data` and you are root. `git config --global --add safe.directory /var/www/bec-pmo` |
| Certificate expired | `certbot.timer` is not running: `systemctl enable --now certbot.timer` |
| Apache wedges after a while | On a 1 GB machine, check swap exists: `swapon --show` |

---

## Appendix — what becpmo.com runs today

Read off the live machine on 2026-09-24. Use it as the reference for what "correct" looks
like.

| | |
|---|---|
| OS | Ubuntu 24.04.4 LTS (x86_64) |
| Web server | Apache 2.4.58, `mod_php` — modules `rewrite`, `ssl`, `headers` |
| PHP | 8.3.6, with `pdo_pgsql`, `pgsql`, `curl`, `gd`, `mbstring`, `openssl`, `zip` |
| Document root | `/var/www/bec-pmo` — `AllowOverride All`, `Options -Indexes` |
| Vhosts | `bec-pmo.conf` (:80, 301 to HTTPS) and `bec-pmo-le-ssl.conf` (:443) |
| Canonical host | `www.becpmo.com` 301s to `becpmo.com` |
| Certificate | Let's Encrypt, `becpmo.com` + `www.becpmo.com`, auto-renewed by `certbot.timer` |
| Firewall | `ufw`: OpenSSH, 80/tcp, 443/tcp |
| PHP limits (apache2 ini) | `upload_max_filesize 40M`, `post_max_size 40M`, `memory_limit 128M`, `display_errors Off`, `date.timezone Asia/Manila` |
| Cron | `bec-pmo-backup` daily 18:00 UTC · `bec-pmo-sweeps` every 15 minutes |
| DNS | Two A records at the registrar, apex and `www`, both to the VPS IP |
| Database | Supabase Postgres — unchanged from local |

---

## Related

- `docs/VM_SETUP.md` — choosing a provider and creating the machine, before this guide starts
- `docs/SETUP_NEW_LAPTOP.md` — getting a development copy running on a new PC
- `docs/DEPLOYMENT.md` — the other two hosting routes: a temporary HTTPS demo tunnel, and shared hosting
- `docs/EMAIL_DELIVERABILITY.md` — SPF/DKIM/DMARC, if you move off Gmail to `@bec.edu.ph`
- `CLAUDE.md` — architecture notes and the traps that are not visible in the code

---

*Batangas Eastern Colleges · Property Management Office*
