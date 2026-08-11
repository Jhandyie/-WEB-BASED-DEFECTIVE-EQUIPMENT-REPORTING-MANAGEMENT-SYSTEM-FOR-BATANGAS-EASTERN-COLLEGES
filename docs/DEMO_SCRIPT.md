# Defense Demo Script — BEC PMO Equipment Reporting System

A 12–15 minute live demonstration that walks the panel through the **entire equipment
lifecycle**, hitting every "wow" feature in a natural order. Follow it top to bottom.

---

## Before the defense (30-minute checklist)

- [ ] Start **Apache** (XAMPP) and open `http://localhost/bec-pmo/` — confirm the landing page loads
- [ ] Internet is up (Supabase + Gmail + Becca need it)
- [ ] *(Optional, impressive)* `cloudflared tunnel --url http://localhost:80` → gives a public
      HTTPS URL so **panelists can open the system on their own phones** and you can demo the
      QR scan + PWA install for real
- [ ] Sign in to the **reporter Gmail inbox** in a browser tab (to show arriving emails live) —
      this is also where the reporter's **6-digit sign-in code** arrives, so it is not optional
- [ ] Confirm the demo reporter address is **on the BEC directory** (`Admin → BEC Directory`) —
      no directory entry, no code, no report
- [ ] Phone ready with the **technician PWA** installed (or install it live — see step 6)
- [ ] One **printed equipment QR** sticker (Admin → Inventory → QR → Print)
- [ ] Know your accounts: admin email (OTP login), one technician username/password
- [ ] Delete any leftover junk/test reports so the dashboard looks clean
- [ ] Backup plan: screenshots of every step in case the venue internet dies

---

## The storyline (follow in order)

### 1. The front door (1 min)
Open the **landing page**. Point out: campus hero, the module grid ("one platform for the
whole workflow"), the **live public-reports preview** (transparency), and the floating
**Becca AI** orb. One sentence: *"Everything you'll see next is reachable from here."*

### 2. Reporting a defect — the 10-second QR path (2 min)
- Scan the **printed QR sticker** with a phone (or open the same link) →
  the reporter gate opens with *"You scanned an equipment QR…"*
- Enter a name + **BEC email** + privacy consent → the system emails a **6-digit code, valid 3
  minutes** (mention: the address must be on the official BEC directory, so outsiders can't file, and
  the code proves the reporter actually holds the mailbox — no password to maintain). Enter it.
  *A browser that verified within the last 30 days skips straight in — if that happens on your demo
  laptop, say so rather than pretending the step doesn't exist.*
  **Pre-flight:** the demo address must be in the directory and its inbox open on screen.
- The form is **pre-filled with the exact equipment** — banner shows what was scanned;
  the cursor is already in the description. Type one sentence, attach a photo, submit.
- Show the **ticket number** + the **confirmation email** landing in the Gmail tab.

### 3. Public tracking (30 sec)
Paste the ticket into **Track Report** — show the status timeline. *"The reporter never
needs an account to follow their case."*

### 4. PMO review — secure admin access (2 min)
- Admin login → **OTP arrives by email** (show it; mention the 3-minute expiry).
- Dashboard tour, briefly: KPI cards, **Asset Health** scores, priority alerts.
- **Defect Reports** → open the new report → **Mark as Received**
  (point at Gmail: the reporter is notified) → **Approve** (set department + priority —
  the report moves straight to Ready for Assignment).

### 5. Assignment (1 min)
**Assign Technicians** → pick the report → click a technician card (workload is shown).
Show Gmail: the technician's **"New Task Assigned"** email with the *Open Repair
Workspace* button — a deep link to that exact task.

### 6. The technician experience — the star of the show (3–4 min)
On the phone (PWA) or a second browser:
- *(If not installed)* show **"Add to Home Screen"** — it installs like a native app.
- Open the task → point out the **workflow stepper**, live **SLA "Due in…" chip**.
- **Receive Task** → **Start Repair** (repair timer starts ticking — each action shows
  its own themed loading animation; worth a sentence).
- **Completion Report** — required fields flag red if skipped (show once),
  add before/during/after photos, submit.

### 7. Closing the loop (1 min)
- Admin: report shows **Completed** → **Verify & Close** (reporter emailed; report is closed).
- Reporter: Track page → progress ring reads **100%** → **"Was your issue resolved?" → Yes** →
  satisfaction recorded.
- One line: *"Every step you just saw is recorded with the user and a timestamp."* (Logging is
  server-side; the browsable Audit Log page was removed in Aug 2026, so don't offer to open it.)
- *(Optional, ~30s)* **Admin → Backup & Recovery**: "Back up now" makes a compressed snapshot
  of every table; a nightly Windows Task Scheduler job does this automatically, and any
  snapshot can restore records if data is ever deleted — the system's data-recovery safeguard.

### 8. Rapid-fire extras (1–2 min, pick 2–3)
- **Exports**: Users → Export PDF (official BEC letterhead + blue band form)
- **Inventory**: Excel upload repopulates totals; QR print per equipment
- **Becca (admin)**: ask *"give me a summary"* — answers from live data
- **Backups**: show `backups/` folder + the scheduled task ("runs daily at 6 PM")

---

## Likely panel questions — and honest answers

**"How is it secured?"**
Role-scoped sessions per portal; admin access needs a password **plus an emailed OTP** that
expires in 3 minutes; every state-changing request is CSRF-protected; login attempts are
rate-limited; uploads are validated by actual image content (not filenames); secrets
(`.env`, API keys, SMTP passwords) are never committed; server folders (`/config`, `/data`,
`/logs`, `/backups`) return 403 over HTTP; every action is written to an audit log.

**"Why Supabase/PostgreSQL instead of local MySQL?"**
The database lives in the cloud, so it survives the laptop, supports the hosted deployment
without migration, and is professionally managed. The code talks to it through a
compatibility adapter, and a scheduled job archives **all tables daily** as an extra backup.

**"What happens if email fails?"**
Every notification is dual-channel — the **in-app notification** always fires; email is
best-effort and never blocks the workflow (a failed send is logged, the action still
succeeds).

**"Is the AI making things up?"**
No — each Becca is **read-only**, is given only live database facts for its role, and is
instructed to say "that isn't available to me" rather than guess. If the AI service is
down, a built-in rules engine answers from the same live data, so it never breaks the demo.

**"Can it scale to the whole school?"**
The heavy state is in managed Postgres; the PHP layer is stateless per request and hosts
anywhere PHP runs. The deployment guide (docs/DEPLOYMENT.md) covers shared hosting and VPS,
HTTPS, and cron backups.

**"What are the limitations / future work?"**
Currently hosted locally (deployment guide ready); email uses a Gmail account (institutional
SPF/DKIM setup documented in docs/EMAIL_DELIVERABILITY.md); future: SMS notifications,
an executive analytics dashboard, and printed monthly PMO summaries.

---

## Emergency fallbacks

| Problem | Do this |
|---|---|
| Venue internet dies | Screenshots folder; narrate the flow |
| OTP email slow | On localhost the system shows the code inline when sending fails — say it's the local-dev fallback |
| Apache not responding | XAMPP Control Panel → Stop/Start Apache |
| Becca offline | It auto-falls back to the rules brain — demo continues, mention the design |
