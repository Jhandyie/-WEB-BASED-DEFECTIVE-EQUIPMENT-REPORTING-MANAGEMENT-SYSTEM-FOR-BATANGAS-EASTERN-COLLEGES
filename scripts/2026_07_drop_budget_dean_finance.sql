-- 2026_07_drop_budget_dean_finance.sql
-- Schema cleanup after retiring the budget-request and Dean/Finance approval features.
--
-- Safe to run: verified before execution that the budget_* tables held only stale
-- pre-removal rows (captured in bec_db_backup_20260715_082815.zip) and that every
-- defect_reports column dropped below was 100% NULL with zero code references.
--
-- Reversibility: structure is restorable from the DOWN section at the bottom; the two
-- budget tables' data lives in the pre-migration backup ZIP under backups/.
--
-- Postgres / Supabase. Idempotent (IF EXISTS). Wrap in a transaction when running.

BEGIN;

-- 1) Budget-request feature tables (child first for the FK).
DROP TABLE IF EXISTS public.budget_request_items;
DROP TABLE IF EXISTS public.budget_requests;

-- 2) Dead Dean / Finance / budget / tokenized-approval columns on defect_reports.
ALTER TABLE public.defect_reports
    DROP COLUMN IF EXISTS dean_approval_status,
    DROP COLUMN IF EXISTS dean_approved_by,
    DROP COLUMN IF EXISTS dean_approved_at,
    DROP COLUMN IF EXISTS dean_notes,
    DROP COLUMN IF EXISTS finance_approval_status,
    DROP COLUMN IF EXISTS finance_approved_by,
    DROP COLUMN IF EXISTS finance_approved_at,
    DROP COLUMN IF EXISTS finance_notes,
    DROP COLUMN IF EXISTS budget_status,
    DROP COLUMN IF EXISTS approval_token,
    DROP COLUMN IF EXISTS approval_stage,
    DROP COLUMN IF EXISTS approval_notified_at;

COMMIT;

-- ── DOWN (structure only — run manually to revert; data comes from the backup ZIP) ──
-- ALTER TABLE public.defect_reports
--     ADD COLUMN dean_approval_status    text,
--     ADD COLUMN dean_approved_by        text,
--     ADD COLUMN dean_approved_at        timestamp,
--     ADD COLUMN dean_notes              text,
--     ADD COLUMN finance_approval_status text,
--     ADD COLUMN finance_approved_by     text,
--     ADD COLUMN finance_approved_at     timestamp,
--     ADD COLUMN finance_notes           text,
--     ADD COLUMN budget_status           text,
--     ADD COLUMN approval_token          text,
--     ADD COLUMN approval_stage          text,
--     ADD COLUMN approval_notified_at    timestamp;
