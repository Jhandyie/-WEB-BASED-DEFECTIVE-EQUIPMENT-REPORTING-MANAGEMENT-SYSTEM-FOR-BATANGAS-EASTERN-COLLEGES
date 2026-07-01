# BEC PMO — Email Deliverability Setup (bec.edu.ph)

**Goal:** make mail sent from `jhanmark_decastro@bec.edu.ph` (via Google Workspace / Gmail SMTP) land in the **inbox** instead of spam.

**Status (diagnosed 2026-07-01):**
- ✅ SMTP works — Gmail authenticates the account and **accepts** every message (queue IDs confirmed).
- ❌ Mail is **spam-filed** because the domain is not DNS-authorized for Google *sending*:
  - **DKIM fails** — Google signs outbound mail with the `google._domainkey` selector, which is **not published** in DNS (only a `default._domainkey` exists, belonging to the website host).
  - **SPF soft-fails** — the SPF record does **not** include `_spf.google.com`.
  - **DMARC** — no `_dmarc.bec.edu.ph` record exists.

The application is configured correctly (single sender `BEC PMO <jhanmark_decastro@bec.edu.ph>`, valid app password). **No code/app change is needed** — only the three DNS/Workspace steps below. These require **Google Workspace Admin** + **DNS access** for `bec.edu.ph`.

---

## Step 1 — Enable DKIM in Google Workspace (most important)
1. Sign in to **admin.google.com** as a Workspace administrator.
2. Go to **Apps → Google Workspace → Gmail → Authenticate email**.
3. Select the domain **bec.edu.ph**, choose **2048-bit** key, click **Generate new record**.
4. Google shows a TXT record. Publish it in DNS:
   - **Host/Name:** `google._domainkey`  (i.e. `google._domainkey.bec.edu.ph`)
   - **Type:** `TXT`
   - **Value:** the long `v=DKIM1; k=rsa; p=…` string Google gives you
5. Back in the admin console, click **Start authentication**.

> Leave the existing `default._domainkey` record alone — it belongs to the website host and is unrelated.

## Step 2 — Add Google to the SPF record
Edit the existing `TXT` record on the root domain (`bec.edu.ph`). Add `include:_spf.google.com` right after `v=spf1`.

**Current:**
```
v=spf1 ip4:216.104.46.210 ip4:184.154.180.68 +a +mx +ip4:173.236.52.146 +ip4:99.198.107.218 +ip4:184.154.49.138 +ip4:67.212.173.114 ~all
```
**Change to:**
```
v=spf1 include:_spf.google.com ip4:216.104.46.210 ip4:184.154.180.68 +a +mx +ip4:173.236.52.146 +ip4:99.198.107.218 +ip4:184.154.49.138 +ip4:67.212.173.114 ~all
```
- Keep it as **one** SPF record (a domain must have only one `v=spf1` TXT record).
- Stays within SPF's 10-DNS-lookup limit (`include` + `a` + `mx` ≈ 5 lookups).

## Step 3 — Add a DMARC record (recommended)
Create a new `TXT` record:
- **Host/Name:** `_dmarc`  (i.e. `_dmarc.bec.edu.ph`)
- **Type:** `TXT`
- **Value:**
```
v=DMARC1; p=none; rua=mailto:jhanmarkdecastro128@gmail.com; fo=1
```
`p=none` only monitors (safe). You can tighten to `p=quarantine` later once DKIM/SPF are confirmed passing.

---

## Verify (after DNS propagates — minutes to a few hours)
- **Easiest:** send a message from `jhanmark_decastro@bec.edu.ph` to a Gmail address, open it, **Show original**, and confirm **SPF: PASS** and **DKIM: PASS** with the domain `bec.edu.ph`.
- Or use an external checker (e.g. mail-tester.com) — send to the address it gives you and aim for 10/10.
- Command-line spot checks:
  ```
  nslookup -type=txt google._domainkey.bec.edu.ph
  nslookup -type=txt bec.edu.ph
  nslookup -type=txt _dmarc.bec.edu.ph
  ```

Once **SPF PASS + DKIM PASS** show up, inbox delivery from `BEC PMO <jhanmark_decastro@bec.edu.ph>` will be reliable.

---

## App-side reference (already configured — no change needed)
- Sender for all roles: `data/system_settings.json → email.{admin,student,technician}` →
  `from_name: "BEC PMO"`, `from_email/smtp_username: jhanmark_decastro@bec.edu.ph`, host `smtp.gmail.com:587`.
- If the app password is ever rotated, update `smtp_password` in that file (generate a new App Password at **myaccount.google.com → Security → App passwords**).
