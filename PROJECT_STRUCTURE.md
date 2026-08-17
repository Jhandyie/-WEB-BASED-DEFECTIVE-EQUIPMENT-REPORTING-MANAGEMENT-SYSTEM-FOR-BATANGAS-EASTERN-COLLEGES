# Project Structure

Web-Based Defective Equipment Reporting and Maintenance Management System
Batangas Eastern Colleges · Property Management Office

Pages live flat in the web root, grouped by **filename prefix** — the prefix is
meaningful: it selects the session context (admin / technician / student
cookies), so files must **not** be moved into subfolders without updating
`includes/session_bootstrap.php`.

## Root pages — by audience

### Public (no login)
| File | Purpose |
|---|---|
| `index.php` | Landing page / front door |
| `public_reports.php` → `includes/public_reports.php` | Transparency board of publicly visible reports |
| `track_report.php` | Ticket / equipment ID / asset tag tracker |
| `logout.php` | Shared logout |

### Reporter (students, faculty, staff)
| File | Purpose |
|---|---|
| `student_index.php` | Sign-in (name + BEC email, Data Privacy consent) |
| `student_dashboard.php` | The report form (equipment, location, evidence, priority) |
| `register_process.php` | Registration handler |

### PMO / ITSO admin (desktop-only by design)
| File | Purpose |
|---|---|
| `admin_dashboard.php` | Overview, stats, unit-scoped queues |
| `admin_defect_reports.php` | Case review, approval, detail modal |
| `admin_assign_technicians.php` | Technician assignment & workloads |
| `admin_inventory.php` | Equipment records, asset tags, QR |
| `admin_preventive.php` | Preventive-maintenance scheduling |
| `admin_analytics.php` | Charts & exports |
| `admin_users.php` | User management (admins need Unit: PMO/ITSO) |
| `admin_notifications.php` | Notification center |
| `admin_backup.php` | Backup & Recovery (snapshots + restore) |
| `admin_bec_directory.php` | Official BEC directory import |
| `admin/` | Admin login + OTP flow (`admin_login_otp.html`, `admin_login_process.php`, password reset) |

### Technician
| File | Purpose |
|---|---|
| `technician_dashboard.php` | 3-panel repair workspace (queue → case → completion) |
| `technician_claim_task.php` / `technician_complete_task.php` | Task lifecycle handlers |
| `technician_cost_estimate.php` | Printable DepEd-method Total Service Cost worksheet |
| `technician_service_report.php` | Printable formal Service Report (after repair) |
| `defect_report_ticket.php` | Printable formal Defect Report ticket (before repair) |
| `technician/` | Technician login (`login.html`, `login_process.php`) |

### AI assistant (Becca)
| File | Purpose |
|---|---|
| `includes/ai_client.php` | The only place the app calls a model. Key loading, Gemini request/response mapping, graceful failure |
| `chat_proxy.php` | Public/reporter chat proxy (Gemini via `includes/ai_client.php` + offline fallback) |
| `admin_chat_proxy.php` / `technician_chat_proxy.php` | Role-specific proxies |
| `scripts/check_ai_key.php` | Pre-demo check: key valid, configured model reachable, live round-trip |
| `push_subscribe.php` | Web-push subscription endpoint |

### Shared helpers kept at root (required by many pages)
`FileStorage.php`, `file_storage_helpers.php`, `inventory_functions.php`

## Folders
| Folder | Purpose |
|---|---|
| `includes/` | Shared PHP: auth, session bootstrap, nav/footer/hero, Becca widget, assistants, audit, mail, public reports engine |
| `config/` | DB connection + mysqli→PDO/Postgres compat adapter, SLA config, secrets (gitignored) |
| `api/` | JSON endpoints (dashboard data, exports, notifications) |
| `controllers/` | Controller layer for API endpoints |
| `assets/` | Images + shared premium JS (date picker, file upload, search, selects, camera capture, auth loader, pagination) |
| `css/` | Shared stylesheets (typography) |
| `data/` | Runtime data: settings, rate limits, mail outbox, inventory reference (mostly gitignored) |
| `scripts/` | Cron/maintenance scripts (weekly summary, e2e smoke test) |
| `supabase/` | Database schema + SQL compat functions (reference) |
| `docs/` | User manual, demo script, deployment notes |
| `uploads/` | Reporter evidence photos/videos (gitignored — backed up separately) |
| `backups/` | Database snapshots from Backup & Recovery (gitignored) |
| `logs/` | Runtime logs (gitignored) |

## Conventions
- **Filename prefix = session context.** `admin_*` / `technician_*` / `student_*` select the right session cookie (`includes/session_bootstrap.php`). Dual-access pages (e.g. printable documents) detect the cookie instead.
- **Postgres under mysqli syntax.** Legacy `mysqli` calls run against Supabase Postgres through `config/mysqli_compat.php`. Booleans must be `true/false`, never `1/0`.
- **Two-admin unit scoping.** `users.department` (`PMO`/`ITSO`) drives which reports each admin sees.
