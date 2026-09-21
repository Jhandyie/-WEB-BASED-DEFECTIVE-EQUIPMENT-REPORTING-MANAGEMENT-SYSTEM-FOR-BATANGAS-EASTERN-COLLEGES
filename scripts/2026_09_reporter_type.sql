-- 2026-09-22 — Who reported it: student, teacher or staff.
--
-- The report form used to open with the reporter's department, course, year
-- level and contact number — four pickers before the fault could be named.
-- The defense panel called it too much, and it was: the PMO needs to know who
-- is asking, not their programme. Sign-in now asks one question with three
-- buttons, and this is where the answer is kept.
--
-- The department / course / level columns stay. They are filled silently from
-- the BEC directory when it knows the reporter (every student), and left blank
-- otherwise; nothing asks the reporter for them any more.
--
-- Safe to run before or after the code ships: addDefectReport() writes only
-- columns that exist, and every reader uses `?? ''`.

ALTER TABLE public.defect_reports ADD COLUMN IF NOT EXISTS reporter_type varchar(20);

COMMENT ON COLUMN public.defect_reports.reporter_type IS
    'What the reporter said they are at sign-in: student, teacher or staff. Empty on reports filed before September 2026 and on walk-ins logged by the PMO.';

-- Reports filed before this column existed: the directory knows every student,
-- so those can be labelled after the fact. Teachers and staff are not in it and
-- stay blank rather than guessed.
UPDATE public.defect_reports r
   SET reporter_type = CASE lower(d.user_type)
                           WHEN 'student' THEN 'student'
                           WHEN 'faculty' THEN 'teacher'
                           WHEN 'staff'   THEN 'staff'
                       END
  FROM public.bec_directory d
 WHERE lower(d.email) = lower(r.reporter_email)
   AND COALESCE(r.reporter_type, '') = ''
   AND lower(d.user_type) IN ('student', 'faculty', 'staff');
