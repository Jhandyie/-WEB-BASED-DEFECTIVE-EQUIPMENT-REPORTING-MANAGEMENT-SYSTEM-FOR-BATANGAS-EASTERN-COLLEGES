-- 2026-08-04 — The reporter's year level becomes a field of its own.
--
-- The official enrolment export carries a "Year Level" column ("Grade 7",
-- "Grade 11 - STEM", "1st Year"), and it was the one thing the importer had
-- nowhere to put. Until now the level was smuggled into the course string
-- ("BSIT - 2nd Year") by becSyncReporterProfile(), which meant it could not be
-- read back out cleanly to pre-fill the report form — the reporter retyped it
-- on every single report.
--
-- Holding it separately is what lets Reporter Information arrive already
-- filled in: department, course and level all come off the directory record.

ALTER TABLE public.bec_directory ADD COLUMN IF NOT EXISTS year_level varchar(40);

-- Same field on a registered account, so a reporter who also holds a login
-- shows one consistent profile in User Management.
ALTER TABLE public.users ADD COLUMN IF NOT EXISTS year_level varchar(40);

COMMENT ON COLUMN public.bec_directory.year_level IS
    'Grade or year standing only, e.g. "Grade 11" or "2nd Year". The strand or programme belongs in program.';
