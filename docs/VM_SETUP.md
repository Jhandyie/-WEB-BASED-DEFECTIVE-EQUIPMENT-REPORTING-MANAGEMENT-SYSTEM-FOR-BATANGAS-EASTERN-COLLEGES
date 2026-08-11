# Running on a free VM — step by step

For demoing to reporters, admins and technicians at once without the laptop having to stay
awake. You get an empty Ubuntu machine; `scripts/provision_vm.sh` does the rest.

Budget about an hour the first time. Most of it is waiting.

---

## Before you start — pick the provider

|                       | Google Cloud e2-micro   | Oracle Always Free          |
|-----------------------|-------------------------|-----------------------------|
| Machine               | 1 shared vCPU · 1 GB    | up to 4 ARM cores · 24 GB   |
| Region near PH        | US only on free tier    | Singapore / Tokyo           |
| Free egress           | ~1 GB/month             | ~10 TB/month                |
| **Outbound SMTP 587** | usually allowed         | **usually blocked**         |
| Main risk             | tight egress, US latency| ARM capacity often full     |

They cut in opposite directions. Oracle is the better machine and much closer to Batangas,
but it is the one likely to block outbound mail — and **the entire login is an emailed code**,
so a machine that cannot reach Gmail cannot log anyone in.

Google's limit is egress: photos are ~2 MB each, so ~1 GB/month is roughly 500 photo views.
Fine for a demo, not for a term of real use.

> If you only want to demo, and you want it to just work: **Google Cloud**. If you want it to
> stay useful afterwards and are willing to fight the SMTP question: **Oracle**.

---

## Step 1 — Create the machine  *(only you can do this)*

Account creation needs a credit card, a phone code and accepting terms. Free tier is not
charged, but the card is required for identity checks.

**Google Cloud** — console.cloud.google.com → Compute Engine → Create instance
- Machine type **e2-micro**
- Region **us-west1**, **us-central1** or **us-east1** — the free tier is *only* these
- Boot disk **Ubuntu 22.04 LTS**, 30 GB standard persistent disk
- Tick **Allow HTTP traffic** and **Allow HTTPS traffic**

**Oracle Cloud** — cloud.oracle.com → Compute → Instances → Create
- Shape **VM.Standard.A1.Flex** (ARM), 1–4 cores. If it says *out of capacity*, try another
  availability domain or come back later — this is common
- Image **Ubuntu 22.04**
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
git clone https://github.com/YOUR-REPO.git /var/www/bec-pmo
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
config/chat_secrets.php     Anthropic API key
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

The machine is free but not guaranteed. Oracle reclaims idle instances and free ARM capacity
in the good regions is frequently unavailable. Keep the nightly backup running, and never let
this be the only copy of anything.
