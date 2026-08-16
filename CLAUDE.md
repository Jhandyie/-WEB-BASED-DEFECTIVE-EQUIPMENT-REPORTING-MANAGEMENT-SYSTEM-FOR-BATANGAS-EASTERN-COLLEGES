# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A web-based defective equipment reporting and maintenance management system for the Property
Management Office of Batangas Eastern Colleges — a capstone project, demoed to a panel.
`README.md` covers features and setup; `PROJECT_STRUCTURE.md` maps every file to its audience.
Read those for orientation; this file covers what neither makes obvious.

**Plain PHP 8 on Apache/XAMPP. No framework, no Composer, no Node, no build step.** Edit a `.php`
file and reload the browser. Everything that would normally be a library (XLSX read/write, web
push, SMTP, PDF-ready branding) is hand-rolled in `includes/`.

## Commands

There is no test runner, linter, or package manager. These scripts are the tooling:

```bash
c:\xampp\php\php.exe scripts\e2e_smoke.php        # full-lifecycle E2E over real HTTP (needs Apache up)
c:\xampp\php\php.exe scripts\ui_smoke.php         # renders pages in headless Edge and asserts the DOM
c:\xampp\php\php.exe scripts\demo_preflight.php   # 15-point health check; exit 0 = safe to demo
c:\xampp\php\php.exe -l <file.php>                # syntax check a single file
c:\xampp\php\php.exe scripts\backup_db.php        # DB snapshot → backups/ (also a Task Scheduler job)
c:\xampp\php\php.exe scripts\build_fonts.php      # re-vendor webfonts (needs internet)
scripts\start-demo.bat                            # Apache + preflight + public tunnel + watchdog
```

The app runs at `http://localhost/bec-pmo/`. There is no way to run "one test" — `e2e_smoke.php`
is a single linear walk through the workflow; comment out later steps if you need a shorter loop.

**`e2e_smoke.php` does not execute browser JavaScript.** It posts over raw HTTP, so it stays green
while the UI is entirely broken for real users (this has happened). `scripts/ui_smoke.php` covers
that gap — it loads the real pages in headless Edge, lets their scripts run, and asserts against
the DOM the browser produced (a token injected by `csrf_inject.php`, a date field wrapped by
`date_picker.js`, a recurrence preview a page filled in). Exit 0 = the interface works. It writes
a temporary session seeder at the web root and deletes it in a `finally`, so nothing that grants a
session outlives the run. For a one-off check of a page it does not cover, drive Edge directly:

```
"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" --headless --disable-gpu ^
  --user-data-dir=<temp> --virtual-time-budget=15000 --dump-dom <url>
```

Headless runs do not carry session cookies between invocations, so log-in-required pages need a
temporary `_diag_*.php` seeder page (call `startRoleSession()`, set `$_SESSION`, then redirect)
as the entry point. Delete every `_diag_*.php` when done.

## Architecture

### The database is Postgres pretending to be MySQL

~547 call sites use mysqli syntax. They run against Supabase Postgres through a compatibility
adapter, so **do not "fix" mysqli-style code into PDO** — the whole design is that it keeps working.

- `config/database.php` — `Database::getInstance()->getConnection()` returns `PgMysqliConnection`
  (from `config/mysqli_compat.php`) when `isPgSqlDriver()`, otherwise a real `mysqli`.
- `pgTranslateSql()` rewrites the dialect (backticks, `LIMIT o,c`, `IFNULL`, `CURDATE`,
  `DATE_SUB`, `SHOW TABLES/COLUMNS`, …); `supabase/mysql_compat_functions.sql` supplies
  `field()`, `datediff()`, `date_format()` as real Postgres functions.
- Still not translated, handle per query: `GROUP_CONCAT`, `YEARWEEK`, and MySQL's loose `GROUP BY`
  (Postgres is strict — match the SELECT expressions or use positional `GROUP BY 1,2`).
- **Booleans must be `true`/`false`, never `1`/`0`, in INSERT values.** The adapter rewrites
  comparisons like `is_read = 0` but not literals in a VALUES list.
- Some code bypasses the adapter and uses `getPgsqlPdoConnection()` with named parameters directly
  — mostly the user/OTP/password-reset helpers. Both paths exist in `config/database.php`; follow
  whichever the surrounding function already uses.

`config/database.php` is 3,000 lines and is far more than connection setup: workflow status
vocabulary (`defectWorkflowStatuses()`, `defectTimelineSteps()`), PMO/ITSO unit scoping
(`adminUnitForUser()`, `equipmentUnit()`), photo resolution, and `logActivity()` all live there.
Look here before adding a helper elsewhere.

### Sessions are keyed by filename prefix

`includes/session_bootstrap.php` derives the session cookie from the script name: `admin_*` or
`/admin/` → `BECSESSID_ADMIN`, `technician_*` → `BECSESSID_TECH`, `student_*` → `BECSESSID_STUDENT`,
everything else → `BECSESSID_MAIN`. This is what lets someone be logged into three portals at once.

**Renaming or moving a page changes which session it sees.** A new admin page must be named
`admin_something.php` at the web root, or it silently gets the wrong (empty) session.

### Request shape

Pages are self-contained: each `.php` file at the root does its own auth check, handles its own
POSTs at the top, then renders HTML with inline `<style>` and `<script>`. There is no router,
template engine, or shared controller layer (`controllers/` holds exactly one file). Shared behavior
comes from `require`-ing `includes/`:

`auth.php` (`requireRole()`, `getUserId()`), `csrf.php` (`csrf_field()`, `requireCsrf()` — every
state change needs one), `mail_helper.php` (`sendEmail()`, `sendDefectWorkflowEmail()`, with a
retry outbox in `data/mail_outbox/`), `notification_helper.php`, `sla_helper.php`, `audit.php`,
`rate_limiter.php`, `becca_widget.php` (the AI assistant, injected on every portal).

### Workflow

`reported → pmo_review → ready_for_assignment → assigned → accepted → in_progress →
completed → verified → closed`, plus `waiting_for_materials`, `for_replacement`, `rejected`.
Statuses are strings defined in `defectWorkflowStatuses()`; each transition fires in-app
notifications and branded email. `users.department` (PMO or ITSO) scopes which reports an admin sees.

## Conventions and traps

- **Never per-report work during page render.** Report lists render every matching row server-side,
  so anything O(rows) in a page load is O(backlog). `runSlaEscalationSweep()` cost 321 ms per
  overdue report and made admin pages 500 past ~2,000 reports; it is now batched, capped at 10 per
  run and throttled to once per 5 minutes. Check for this shape before adding page-load work.
  Pagination is client-side today — page weight is the real scaling ceiling (~11 MB at 5,000
  reports), and server-side pagination is the one architectural change still owed.
- **No CDN tags, ever.** Fonts, Font Awesome, Chart.js and SheetJS are vendored under
  `assets/vendor/` so the app works with no venue internet. Reintroducing
  `cdnjs.cloudflare.com`, `cdn.jsdelivr.net` or `fonts.googleapis.com` is a regression. The only
  two files that may reference them are `includes/mail_helper.php` (renders in the recipient's mail
  client) and `scripts/build_fonts.php` (it downloads the fonts). Pages in `admin/` and
  `technician/` need `../` prefixes.
- **Never put CSS inside a `<script>` block.** A stray `a:link{...}` parses as JS, throws
  `SyntaxError`, and kills every function the script defines — the page's buttons all go dead while
  PHP lint and server rendering stay clean. This has shipped twice.
- **In JS, use `form.getAttribute('action')`, not `form.action`.** A control named `action` (or
  `submit`) shadows the property and returns the element. Also: errors thrown inside `async` event
  listeners surface as unhandled promise rejections, not `window.onerror`.
- **Admin pages are desktop-only by design.** Do not spend effort on admin mobile layouts; mobile
  work belongs to the reporter, public and technician surfaces.
- **`activity_log.action` is empty in every row** — it is dead. `logActivity()` writes
  `action_type` / `action_description`, and ~400 legacy rows are column-shifted, so any future
  reader of this table needs to resolve a row across several columns rather than trusting one.
  (The `admin_audit_log.php` viewer was removed in Aug 2026; the *writing* side is untouched and
  every lifecycle action is still logged.)
- **Keep `.ps1` files ASCII-only.** Windows PowerShell reads them as ANSI; an em-dash inside a
  string literal terminates the string and breaks the script.
- **OPcache is deliberately off** in `C:\xampp\php\php.ini` — it was crashing Apache daily with
  `VirtualProtect() failed [87]`, and bought only ~15-25 ms on pages that are network-bound to
  Supabase (~429 ms per round trip). `scripts/apache_watchdog.ps1` restarts Apache if it wedges.

## Secrets

All gitignored, all needed to run: `.env` (Supabase), `config/chat_secrets.php` (Anthropic API key),
`data/system_settings.json` (Gmail SMTP app password per role). Copy from the `.example` files.
`scripts/carry_secrets.ps1` moves them between machines as an encrypted bundle;
`scripts/setup_new_machine.ps1` and `docs/SETUP_NEW_LAPTOP.md` cover a fresh install.
