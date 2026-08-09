-- ============================================================================
--  2026_08_dispatch.sql
--  Technician dispatch: capacity, scheduling, skills and assignment history.
--
--  NOTHING HERE HAS BEEN RUN. Read it, then run it with:
--      c:\xampp\php\php.exe scripts\run_sql.php scripts\2026_08_dispatch.sql
--  or paste it into the Supabase SQL editor.
--
--  Why it exists
--  -------------
--  The Assign Technicians screen is asked to show a match score, a workload
--  ratio ("3 / 5"), a due date and a reassignment history. None of those exist
--  in the schema today:
--
--    * users.specialization is ONE free-text field. It cannot honestly produce
--      "92% match" — that would be a number invented to look intelligent.
--    * There is no capacity column, so the 5 in "3 / 5" would be fiction.
--    * defect_reports has no due date of any kind.
--    * There is no record of who a report was assigned to before.
--
--  Until this runs, the dispatch screen shows the real active-task count and the
--  technician's specialization, and nothing else is claimed.
--
--  Safety
--  ------
--  Every statement is additive and IF NOT EXISTS. No column is dropped, no data
--  is rewritten, and every existing row keeps working: the new columns are
--  nullable or defaulted. The rollback at the bottom undoes all of it.
--  Take a backup first — scripts/backup_db.php, or Admin -> Backup & Recovery.
-- ============================================================================

BEGIN;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Technician capacity
--    Turns "1 active task" into "1 of 5", and lets Overloaded mean something
--    per person instead of the hardcoded >= 4 the page uses today.
--    Default 5 matches the current hardcoded threshold, so behaviour does not
--    change on the day it runs; the PMO can then tune it per technician.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS max_concurrent_tasks SMALLINT NOT NULL DEFAULT 5;

COMMENT ON COLUMN public.users.max_concurrent_tasks IS
  'How many open repairs this technician is expected to carry. Used for the workload ratio and the overload warning on Assign Technicians.';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Scheduling
--    A repair with no due date cannot be late, which is why the SLA logic has
--    to infer everything from priority. These are nullable: existing reports
--    stay exactly as they are and simply have no target.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE public.defect_reports
  ADD COLUMN IF NOT EXISTS scheduled_start TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS due_date        TIMESTAMPTZ NULL;

COMMENT ON COLUMN public.defect_reports.due_date IS
  'When the repair is expected to be finished. Set at assignment. NULL means no target was given.';

CREATE INDEX IF NOT EXISTS idx_defect_reports_due_date
  ON public.defect_reports (due_date)
  WHERE due_date IS NOT NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Skills
--    One row per skill per technician, so "Electrical, HVAC" stops being a
--    string somebody typed and becomes something a query can match against the
--    equipment category. This is what makes a match score defensible rather
--    than decorative.
--
--    The seed below copies each technician's existing specialization across as
--    their first skill, so nobody starts empty and nothing is invented.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.technician_skills (
    id          BIGSERIAL PRIMARY KEY,
    user_id     TEXT NOT NULL,
    skill       TEXT NOT NULL,
    proficiency SMALLINT NOT NULL DEFAULT 3
                CHECK (proficiency BETWEEN 1 AND 5),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_technician_skill UNIQUE (user_id, skill)
);

CREATE INDEX IF NOT EXISTS idx_technician_skills_user
  ON public.technician_skills (user_id);

INSERT INTO public.technician_skills (user_id, skill)
SELECT u.user_id, TRIM(u.specialization)
  FROM public.users u
 WHERE u.role = 'technician'
   AND COALESCE(TRIM(u.specialization), '') <> ''
   AND COALESCE(u.status, '') <> 'deleted'
ON CONFLICT (user_id, skill) DO NOTHING;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Assignment history
--    Reassignment currently overwrites assigned_to, so the previous technician
--    and the reason are lost. This keeps the trail without touching the live
--    column that the workflow reads.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.assignment_history (
    id             BIGSERIAL PRIMARY KEY,
    report_id      TEXT NOT NULL,
    technician_id  TEXT NULL,          -- who it went TO
    previous_id    TEXT NULL,          -- who it came FROM, NULL on first assignment
    assigned_by    TEXT NULL,
    reason         TEXT NULL,          -- required by the UI only on reassignment
    priority       TEXT NULL,
    department     TEXT NULL,
    due_date       TIMESTAMPTZ NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_assignment_history_report
  ON public.assignment_history (report_id, created_at DESC);

COMMENT ON TABLE public.assignment_history IS
  'Append-only. One row per assignment or reassignment. Never updated, so the trail cannot be rewritten.';

COMMIT;

-- ============================================================================
--  ROLLBACK — run this to undo everything above.
--  Dropping the two tables loses the skills and the history recorded since;
--  the two ALTERs lose whatever capacities and due dates were set.
-- ============================================================================
-- BEGIN;
-- DROP TABLE IF EXISTS public.assignment_history;
-- DROP TABLE IF EXISTS public.technician_skills;
-- ALTER TABLE public.defect_reports
--   DROP COLUMN IF EXISTS due_date,
--   DROP COLUMN IF EXISTS scheduled_start;
-- ALTER TABLE public.users
--   DROP COLUMN IF EXISTS max_concurrent_tasks;
-- COMMIT;
