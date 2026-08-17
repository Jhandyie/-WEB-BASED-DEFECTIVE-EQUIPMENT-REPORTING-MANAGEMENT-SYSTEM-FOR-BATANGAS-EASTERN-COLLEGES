# Running on a VPS — step by step

For demoing to reporters, admins and technicians at once without the laptop having to stay
awake. You get an empty Ubuntu machine; `scripts/provision_vm.sh` does the rest.

Budget about an hour the first time. Most of it is waiting.

## Where to click

| What | Link |
|---|---|
| Hostinger VPS plans (paid, recommended) | <https://www.hostinger.com/vps-hosting> |
| Oracle Always Free (free) | <https://www.oracle.com/cloud/free/> |
| Google Cloud free tier | <https://cloud.google.com/free> |
| Gmail app passwords | <https://myaccount.google.com/apppasswords> |
| This project's repo | <https://github.com/Jhandyie/-WEB-BASED-DEFECTIVE-EQUIPMENT-REPORTING-MANAGEMENT-SYSTEM-FOR-BATANGAS-EASTERN-COLLEGES> |

**Buy VPS, not shared hosting.** On Hostinger's site the shared plans (Single / Premium /
Business / Cloud) are the prominent ones and they are the wrong product here — see below.
Their prices are also 48-month prepayments, not monthly rates.

---

## Why a VPS and not shared hosting

The database is Supabase, which is PostgreSQL, so PHP needs the **`pdo_pgsql`** extension to
reach it. Hostinger's shared plans document `PDO` and `pdo_mysql` and route PostgreSQL to VPS;
the client extension is not something to rely on there. Shared hosting that lacks it cannot
connect to the database at all — so a VPS, where you install extensions yourself, is the
dependable option.

Nothing here needs a database *on* the server. Supabase stays where it is.

## Pick the provider

|                       | Hostinger VPS           | Oracle Always Free          | Google Cloud e2-micro    |
|-----------------------|-------------------------|-----------------------------|--------------------------|
| Cost                  | paid monthly            | free                        | free                     |
| Region near PH        | **Malaysia** (53 ms)    | Singapore / Tokyo           | US only on free tier     |
| `pdo_pgsql`           | you install it          | you install it              | you install it           |
| **Outbound SMTP 587** | allowed                 | **usually blocked**         | usually allowed          |
| Egress                | generous                | ~10 TB/month                | **~1 GB/month**          |
| Support               | yes                     | none                        | none                     |
| Main risk             | costs money             | ARM capacity often full     | trans-Pacific latency    |

**Region is the deciding factor.** The Supabase project is in `ap-southeast-1` (Singapore) and
every page makes several queries, so a US machine pays a Pacific crossing on each one — slower
during an evaluation than the laptop already is.

Pick whatever the provider offers nearest that region: **Malaysia** on Hostinger, which has no
Singapore option for VPS, or **Singapore** on Oracle. The leg that counts is server-to-database,
not you-to-server, because it is paid several times per page rather than once.

> **Paying and want it to just work: Hostinger VPS, Malaysia.** Near region, SMTP works,
> support exists.
>
> **Free: Oracle Always Free, Singapore** — plus a mail relay on port 2525 to get around
> their SMTP block (see Step 3).
>
> **Google Cloud free tier is the wrong choice here** — US-only, on the far side of the ocean
> from the database.

---

## Step 1 — Create the machine  *(only you can do this)*

Account creation needs a card, a phone code and accepting terms — including on the free tiers,
where the card is for identity checks rather than billing.

**Hostinger VPS** — hPanel → VPS → Create
- Location **Malaysia**. Their VPS list has no Singapore option (Malaysia 53 ms, India 112 ms,
  everything else 200 ms+), and Kuala Lumpur is a short regional hop from the Supabase region.
  What matters is the *server to database* leg, since each page makes several queries — putting
  the web server next door to the database is the win, even though your own connection to it
  is 53 ms
- OS template **Ubuntu 24.04 LTS** plain — not a one-click LAMP image, since `provision_vm.sh`
  installs the right versions and settings itself
- Note the root password and public IP it gives you
- hPanel has its own firewall page: allow **80** and **443** there as well as on the machine

**Google Cloud** — console.cloud.google.com → Compute Engine → Create instance
- Machine type **e2-micro**
- Region **us-west1**, **us-central1** or **us-east1** — the free tier is *only* these
- Boot disk **Ubuntu 24.04 LTS**, 30 GB standard persistent disk
- Tick **Allow HTTP traffic** and **Allow HTTPS traffic**

**Oracle Cloud** — cloud.oracle.com → Compute → Instances → Create
- Shape **VM.Standard.A1.Flex** (ARM), 1–4 cores. If it says *out of capacity*, try another
  availability domain or come back later — this is common
- Image **Ubuntu 24.04 LTS**
- Afterwards: **Networking → Security List → add ingress rules for 80 and 443**. Oracle does
  not open them for you and this is the usual reason a new instance looks dead

Then reserve a **static IP** on either provider, or the address changes on reboot and your QR
code stops working.

## Step 2 — Get in

Both providers give you a browser SSH button — easiest for the first run.

```bash
sudo apt update && sudo apt upgrade -y
```

## Step 3 — Test the one thing that decides everything

```bash
sudo apt install -y netcat-openbsd
nc -zv smtp.gmail.com 587
```

**Connection succeeded** → carry on.
**Anything else** → stop. Nobody will be able to sign in. Either switch provider, or ask
support to unblock outbound mail (Oracle free accounts are often refused), or move the mailer
to an HTTP email API — which is a real change to `includes/mail_helper.php`, not a setting.

## Step 4 — Run the provisioning script

```bash
# git is not on every Ubuntu image, and /var/www needs root to write into,
# so both of these need sudo - the clone included.
sudo apt install -y git
sudo git clone https://github.com/YOUR-REPO.git /var/www/bec-pmo
sudo bash /var/www/bec-pmo/scripts/provision_vm.sh --domain pmo.bec.edu.ph
```

Leave off `--domain` if you have no domain yet; it will skip HTTPS and you reach the site by
IP. Re-run it later with the domain once DNS points at the machine.

It installs Apache and PHP with `pdo_pgsql`, sets `AllowOverride All` (without which every
`.htaccess` is ignored, including the ones hiding your secrets), adds swap on the 1 GB
machines, opens the firewall, schedules the nightly backup, requests the certificate, and then
checks the things that hurt to find out late. Safe to run twice.

## Step 5 — Copy the three secret files  *(only you can do this)*

They are gitignored on purpose, so cloning does **not** bring them:

```
.env                        Supabase connection
config/chat_secrets.php     Gemini API key
data/system_settings.json   SMTP account + app password
```

`scripts/carry_secrets.ps1` bundles them from your laptop. Then re-run the provisioning
script — with `.env` present it also verifies it can actually reach Supabase.

## Step 6 — Copy the photos

About 73 MB in `uploads/`. They live on disk, not in the database.

```bash
# from your laptop
scp -r uploads/* USER@YOUR-IP:/var/www/bec-pmo/uploads/
# then, on the VM
sudo chown -R www-data:www-data /var/www/bec-pmo/uploads
```

## Step 7 — Check your secrets are not public

```bash
curl -I http://YOUR-IP/data/system_settings.json
```

Anything other than **403** or **404** means `.htaccess` is not being honoured and your Gmail
app password is downloadable by anyone. Fix that before telling a single person the address.

## Step 8 — Verify

```bash
php /var/www/bec-pmo/scripts/demo_preflight.php
php /var/www/bec-pmo/scripts/e2e_smoke.php http://YOUR-IP
```

Preflight never signs in, so it stays green while login-only pages are broken. `e2e_smoke`
walks a report through its whole lifecycle — it writes real rows, so expect a test ticket to
tidy up afterwards.

Then the one no script can do: **have a real person sign in and receive a real code.**

## Step 9 — Point the QR sheet at it

```
php scripts/make_demo_qr.php https://pmo.bec.edu.ph/
```

---

## When it breaks

| Symptom | Cause |
|---|---|
| Site unreachable, machine is up | Cloud firewall — separate from the machine's own. Oracle especially |
| Every page 500s | Secret files missing, or `uploads/`,`data/`,`logs/` not writable by `www-data` |
| Pages load, no email arrives | Outbound 587 blocked — Step 3 |
| Codes arrive in spam | DNS records missing — see `EMAIL_DELIVERABILITY.md` |
| Everything dies under load | Out of memory on a 1 GB machine; the script adds swap, check `swapon --show` |

## What this does not solve

**A VPS makes you the sysadmin.** Shared hosting patches itself; a VPS does not. Turn on
automatic security updates once, on any provider:

```bash
sudo apt install -y unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades
```

On the free tiers the machine is also not guaranteed: Oracle reclaims idle instances, and free
ARM capacity in the good regions is frequently unavailable.

Either way, keep the nightly backup running and never let this be the only copy of anything.
