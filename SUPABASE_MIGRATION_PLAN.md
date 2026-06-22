# Supabase Migration Plan

This project can move from XAMPP MySQL to Supabase PostgreSQL, but it is not a direct connection-string swap.

The codebase currently depends on:
- `mysqli`
- MySQL-specific SQL
- runtime schema inspection with `SHOW COLUMNS`
- MySQL date helpers like `NOW()`, `CURDATE()`, `DATE_ADD()`, `TIMESTAMPDIFF()`
- MySQL ordering helpers like `FIELD(...)`
- MySQL upsert syntax like `ON DUPLICATE KEY UPDATE`
- MySQL schema types like `ENUM` and `AUTO_INCREMENT`

## Current Database Entry Point

Primary database layer:
- [config/database.php](/c:/xampp/htdocs/bec_equipment/config/database.php:1)

Current driver:
- `mysqli`

Used widely by:
- dashboards
- auth/login handlers
- notifications
- equipment/inventory flows
- defect reports
- reservations
- OTP/password reset flows

## Schema Sources Found

Primary schema/data reference:
- [full_backup.sql](/c:/xampp/htdocs/bec_equipment/full_backup.sql:1)

Additional schema/migration files:
- [maintenance_scheduling_schema.sql](/c:/xampp/htdocs/bec_equipment/maintenance_scheduling_schema.sql:1)
- [data/create_work_orders_table.sql](/c:/xampp/htdocs/bec_equipment/data/create_work_orders_table.sql:1)
- [scripts/2026_04_hybrid_workflow_migration.sql](/c:/xampp/htdocs/bec_equipment/scripts/2026_04_hybrid_workflow_migration.sql:1)
- [scripts/2026_04_workflow_role_portals.sql](/c:/xampp/htdocs/bec_equipment/scripts/2026_04_workflow_role_portals.sql:1)

Main tables seen in the backup:
- `activity_log`
- `admins`
- `categories`
- `defect_reports`
- `email_otp`
- `equipment`
- `faculty_members`
- `maintenance_technicians`
- `notifications`
- `password_resets`
- `reservations`
- `students`
- `user_sessions`
- `users`

Additional feature tables outside the main dump:
- `work_orders`
- maintenance scheduling tables

## Biggest Migration Risks

### 1. Driver replacement

The app is built around `mysqli`, including:
- `prepare`
- `bind_param`
- `get_result`
- `fetch_assoc`
- `fetch_all(MYSQLI_ASSOC)`

Supabase uses PostgreSQL. In PHP, this means we need to move to one of:
- `PDO` with `pgsql`
- `pg_connect` / `pg_query_params`

Recommended choice:
- `PDO` with `pgsql`

### 2. Runtime schema detection

The app frequently checks live schema with `SHOW COLUMNS`.

Examples found in:
- admin pages
- inventory helpers
- technician guard
- `config/database.php`

PostgreSQL equivalent exists, but it is different and slower to keep patching everywhere.

Recommended fix:
- stop using runtime schema detection where possible
- standardize the final schema first

### 3. MySQL-specific SQL that must be rewritten

Patterns already found:
- `SHOW COLUMNS`
- `FIELD(...)`
- `CURDATE()`
- `DATE_ADD(...)`
- `TIMESTAMPDIFF(...)`
- `YEARWEEK(...)`
- `DATE_FORMAT(...)`
- `ON DUPLICATE KEY UPDATE`
- `AUTO_INCREMENT`
- `ENUM`

### 4. Dual user design

The codebase uses:
- a central `users` table
- older role-specific tables like `admins`, `students`, `maintenance_technicians`, `faculty_members`

Before migrating to Supabase, we should decide whether to:
- keep the current mixed model, or
- normalize around `users`

Recommended:
- normalize around `users` as the main identity table
- keep legacy role tables only if they still hold real extra fields

## Recommended Migration Strategy

### Phase 1: Freeze the target schema

Goal:
- decide the final PostgreSQL schema before changing PHP queries

Tasks:
- choose canonical tables
- remove reliance on old duplicate role tables where possible
- define PostgreSQL-friendly column types
- replace MySQL `ENUM` with either:
  - PostgreSQL enum types, or
  - `TEXT` + `CHECK` constraints

Recommendation:
- prefer `TEXT` + `CHECK` for easier iteration

### Phase 2: Create Supabase schema SQL

Goal:
- produce a clean PostgreSQL schema for Supabase

Tasks:
- convert `AUTO_INCREMENT` to identity columns or sequences
- convert `ENUM`
- convert datetime defaults
- convert unique constraints and indexes
- rebuild foreign keys explicitly

Deliverable:
- `supabase/schema.sql`

### Phase 3: Export and transform existing MySQL data

Goal:
- move current data safely

Tasks:
- export data from MySQL
- transform IDs/types/date formats if needed
- import into Supabase

Deliverables:
- `supabase/data-load.sql` or CSV import set

### Phase 4: Add a new PostgreSQL connection layer in PHP

Goal:
- keep the app runnable while introducing a Supabase-capable database layer

Recommended approach:
- create a new database abstraction using `PDO`
- move all new query execution through one wrapper
- keep MySQL code untouched until the wrapper is ready

Deliverables:
- updated [config/database.php](/c:/xampp/htdocs/bec_equipment/config/database.php:1)
- environment-driven config

### Phase 5: Convert queries in batches

Goal:
- reduce breakage by converting feature groups one by one

Recommended order:
1. authentication and users
2. defect reports
3. technician dashboard/workflow
4. notifications
5. reservations
6. analytics/reporting
7. inventory/import tools

Reason:
- analytics and imports contain the most MySQL-specific SQL

### Phase 6: Optional Supabase platform features

After database migration works, decide whether to adopt:
- Supabase Auth
- Supabase Storage
- Supabase Row Level Security

Recommendation:
- do not migrate auth/storage in the same step as the database driver change

## What We Should Do Next

Immediate next step:
- build the PostgreSQL target schema from the current MySQL schema and app expectations

After that:
- introduce a `PDO` connection layer without switching production behavior yet

## Notes For This Repo

Files with especially heavy MySQL coupling:
- [config/database.php](/c:/xampp/htdocs/bec_equipment/config/database.php:1)
- [admin_analytics.php](/c:/xampp/htdocs/bec_equipment/admin_analytics.php:1)
- [admin_users.php](/c:/xampp/htdocs/bec_equipment/admin_users.php:1)
- [admin_work_orders.php](/c:/xampp/htdocs/bec_equipment/admin_work_orders.php:1)
- [inventory_functions.php](/c:/xampp/htdocs/bec_equipment/inventory_functions.php:1)
- [includes/otp_helper.php](/c:/xampp/htdocs/bec_equipment/includes/otp_helper.php:1)
- [technician_dashboard.php](/c:/xampp/htdocs/bec_equipment/technician_dashboard.php:1)

## Definition Of Done For Migration

The migration is only complete when:
- Supabase schema is created
- current data is imported
- PHP app runs using PostgreSQL
- login works
- dashboards load
- create/update flows work
- notifications work
- no page still depends on MySQL-only SQL
