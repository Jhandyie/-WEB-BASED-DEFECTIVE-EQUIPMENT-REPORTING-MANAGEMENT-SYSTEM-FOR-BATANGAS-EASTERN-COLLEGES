# BEC PMO — Defective Equipment Reporting Management System

A web-based defective equipment reporting and maintenance management system built for the
**Property Management Office (PMO) of Batangas Eastern Colleges** (San Juan, Batangas · Est. 1940).

It gives the campus one official channel to **report, track, repair, and resolve** defective
equipment — from the first report to PMO verification and reporter sign-off — with budget
requests routed to the Finance office, email notifications at every stage, and a built-in
AI assistant ("Becca") on every portal.

---

## Portals

| Portal | Who | Highlights |
|---|---|---|
| **Landing page** (`index.php`) | Everyone | Campus-photo hero, modules overview, live public-reports preview, Becca AI chat |
| **Reporter portal** (`student_index.php`) | Students, faculty & staff | Guided defect reporting with photos, priority inference, email confirmations |
| **Public tracker** (`track_report.php`, `public_reports.php`) | Everyone | Track any ticket by ID/asset tag, follow-up requests, satisfaction confirmation |
| **Admin / PMO suite** (`admin_*.php`) | PMO administrators | Dashboard + analytics, defect review (receive → approve → assign → verify), work orders, budget approvals, preventive maintenance, inventory, user management, audit log, branded exports |
| **Technician portal** (`technician_dashboard.php`) | Maintenance technicians | Installable **PWA**; scroll-down repair workspace with live SLA/repair timers, workflow stepper, budget requests, photo-documented completion reports |
| **Finance acknowledgment** (`budget_ack.php`) | Finance office | Tokenized email link — mark budget requests *Received* / *Accepted*, no login needed |

## The report lifecycle

```
Reported → Received by PMO → Approved → Assigned → In Progress
        → (Waiting for Materials / Replacement recommended)
        → Completed → Verified / Closed  →  Reporter satisfaction check
```

Every transition notifies the right people **in-app and by branded email**
(reporter confirmations, technician assignment mail with a deep link, Finance
budget notifications, PMO alerts).

## Feature highlights

- **Becca AI assistant ×3** — student, admin, and technician variants (Anthropic Claude via a
  server-side proxy; each is session-gated, reads live data for its role, and falls back to a
  built-in rules brain so it always answers). English & Filipino.
- **Budget → Finance flow** — technicians request parts with itemized costs; the Finance office
  is automatically emailed with a secure acknowledgment link; PMO approves/rejects; everyone is
  notified of each state (visible on the technician's task).
- **Official branded exports** — PDF and true `.xlsx` Excel (built without extensions) matching
  the official BEC PMO inventory form: letterhead, grouped bands, and real signatories.
- **Inventory by Excel upload** — the PMO uploads the official inventory workbook; items are
  parsed by property-number prefix (dependency-free XLSX reader).
- **Security** — OTP email login for admins (3-minute codes), role-scoped sessions, CSRF
  protection on every state change, rate limiting, upload validation by real image content,
  `.htaccess` hardening, secrets kept out of git.
- **Operations** — daily automated database backup (Windows scheduled task → rotating ZIP of
  every table), admin **audit log** viewer, configurable **SLA windows** (`config/sla.php`).
- **UX** — consistent maroon/gold design system, branded loading screens, contextual
  per-action loading animations, inline required-field validation, mobile-first technician
  experience with bottom navigation.

## Tech stack

- **PHP 8** on Apache (XAMPP) — no framework, no Composer dependencies
- **Supabase PostgreSQL** through a custom mysqli-compatibility adapter (`config/database.php`)
- **Vanilla JS + CSS** design system (Fraunces / DM Sans / Outfit, maroon `#7B1D1D` / gold `#C9960C`)
- **Anthropic Claude API** for the Becca assistants (server-side proxies; keys never reach the browser)
- **Gmail SMTP** for all transactional email (`includes/mail_helper.php`)
- **PWA** — manifest + service worker for the installable technician app

## Getting started

1. **Requirements:** XAMPP (Apache + PHP 8+), a Supabase (PostgreSQL) project, a Gmail account
   with an app password for SMTP.
2. Clone into your web root:
   ```
   c:\xampp\htdocs\-WEB-BASED
   ```
3. Create the database: run `supabase/schema.sql` against your Supabase project.
4. Configure secrets (all are gitignored — copy from the examples):
   - `.env` ← from `.env.example` (Supabase connection)
   - `config/chat_secrets.php` ← from `config/chat_secrets.example.php` (Anthropic API key)
   - `data/system_settings.json` — SMTP account(s) per role (`smtp_username`,
     `smtp_password` app password, `from_name: "BEC PMO"`), plus optional `mail_redirects`
   - `config/finance.php` — the Finance office email
   - `config/sla.php` — SLA hours per priority
5. Open `http://localhost/-WEB-BASED/` — the landing page routes every audience to its portal.
6. *(Optional)* Schedule backups: `scripts/backup_db.php` daily via Task Scheduler
   (`BEC PMO DB Backup`), archives to `backups/` with 14-day rotation.

> **Production note:** serve over **HTTPS** for PWA installation and secure cookies, and point
> `config/finance.php` + SMTP at institutional accounts.
> See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** for the full hosting guide — including a
> 10-minute public HTTPS demo via Cloudflare Tunnel, shared-hosting requirements
> (`pdo_pgsql`, SMTP), cron backups, and a post-launch verification checklist.

## Project structure (key paths)

```
index.php                     Landing page
student_index.php             Reporter portal (entry + identity)
student_dashboard.php         Reporter dashboard (submit/track reports)
track_report.php              Public ticket tracker + satisfaction
technician_dashboard.php      Technician repair workspace (PWA)
technician_*.php              Technician endpoints (budget, completion, chat proxy)
admin_*.php                   PMO admin suite (dashboard, defects, work orders, budget,
                              preventive, inventory, users, analytics, audit log…)
budget_ack.php                Finance tokenized acknowledgment page
api/                          JSON APIs + exports
includes/                     Shared: auth/sessions, CSRF, mail, exports, Becca widgets,
                              loaders, nav/footer
config/                       Database adapter, finance + SLA settings, secrets (ignored)
supabase/schema.sql           Full database schema
scripts/backup_db.php         Automated backup job
assets/, uploads/, backups/   Static assets, user uploads, DB archives (ignored)
```

## Testing

The full lifecycle is covered by an end-to-end exercise against the running system
(report → receive → approve → assign → accept → start → budget + Finance ack →
complete with photos → verify → satisfaction), with every status transition asserted
in the database and all notification emails delivered.

## Academic context

Developed as a capstone project for **Batangas Eastern Colleges** —
*"Beacons of Education, Molders of Educators."*

© Batangas Eastern Colleges · Property Management Office. All rights reserved.
