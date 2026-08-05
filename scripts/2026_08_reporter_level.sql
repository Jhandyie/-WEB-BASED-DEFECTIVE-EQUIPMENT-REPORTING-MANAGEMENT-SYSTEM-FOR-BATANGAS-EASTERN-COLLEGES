-- 2026-08-05 — The reporter's year level becomes a column on the report too.
--
-- It was being glued onto the end of the course ("BSIS — 2nd Year") because
-- there was nowhere else to put it. That made the level unreadable as data: it
-- could not be filtered, counted, or fed back into the form to pre-fill it, and
-- the course no longer matched any entry in the official programme list.
--
-- Companion to scripts/2026_08_directory_year_level.sql, which gave the
-- directory and user records the same field.

ALTER TABLE public.defect_reports ADD COLUMN IF NOT EXISTS reporter_level varchar(40);

-- Unpick the reports already filed under the combined form. The separator is
-- an em dash surrounded by spaces, which is what student_dashboard.php joined
-- them with; a course containing an ordinary hyphen is untouched.
UPDATE public.defect_reports
   SET reporter_level  = TRIM(SPLIT_PART(reporter_course, ' — ', 2)),
       reporter_course = TRIM(SPLIT_PART(reporter_course, ' — ', 1))
 WHERE reporter_course LIKE '% — %'
   AND COALESCE(reporter_level, '') = '';

-- Earlier reports joined the two with a plain hyphen instead. Only split those
-- where the tail really is a standing ("2nd Year", "Grade 11") — a programme is
-- allowed to contain a hyphen of its own, and must not be cut in half.
UPDATE public.defect_reports
   SET reporter_level  = TRIM(SUBSTRING(reporter_course FROM ' - ([0-9]+(st|nd|rd|th) Year|Grade [0-9]+)$')),
       reporter_course = TRIM(REGEXP_REPLACE(reporter_course, ' - ([0-9]+(st|nd|rd|th) Year|Grade [0-9]+)$', ''))
 WHERE reporter_course ~ ' - ([0-9]+(st|nd|rd|th) Year|Grade [0-9]+)$'
   AND COALESCE(reporter_level, '') = '';

-- A separator left with nothing after it is just noise on the end of the course.
UPDATE public.defect_reports
   SET reporter_course = TRIM(REGEXP_REPLACE(reporter_course, '\s*[-—–]\s*$', ''))
 WHERE reporter_course ~ '\s[-—–]\s*$';

COMMENT ON COLUMN public.defect_reports.reporter_level IS
    'Year or grade standing of the reporter, e.g. "Grade 11" or "2nd Year". The programme belongs in reporter_course.';
