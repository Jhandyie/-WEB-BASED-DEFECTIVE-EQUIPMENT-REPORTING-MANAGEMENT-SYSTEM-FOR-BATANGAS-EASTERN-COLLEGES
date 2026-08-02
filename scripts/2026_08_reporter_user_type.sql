-- 2026-08-01 — One reporter role, with the person's affiliation as its own field.
--
-- "Reporter" and "Student" were both offered as roles, which forced the PMO to
-- decide whether a teacher who reports a broken aircon is a Reporter or a
-- Student. They are different questions: the ROLE is what the system lets a
-- person do; the TYPE is who they are at the college. The BEC directory already
-- models this with bec_directory.user_type, so accounts now carry the same field.

ALTER TABLE public.users ADD COLUMN IF NOT EXISTS user_type varchar(20);

-- Existing student accounts become reporters whose type is Student.
UPDATE public.users
   SET role = 'reporter',
       user_type = COALESCE(NULLIF(user_type, ''), 'student')
 WHERE role = 'student';

-- Any reporter without a type defaults to Student — the largest group, and the
-- PMO can correct it from User Management.
UPDATE public.users
   SET user_type = 'student'
 WHERE role = 'reporter' AND (user_type IS NULL OR user_type = '');
