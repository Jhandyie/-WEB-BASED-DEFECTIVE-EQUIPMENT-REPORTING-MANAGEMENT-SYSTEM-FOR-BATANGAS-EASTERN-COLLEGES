-- 2026-08-05 — Narrow the role vocabulary to the roles that exist.
--
-- users.role permitted ten values. Only three are real:
--
--   admin       every portal that gates on a role asks for this one
--   technician  the other
--   reporter    files reports; holds no login, identified by BEC email
--
-- The rest were never reachable or have been retired:
--
--   dean, finance   removed with the budget module
--   handler         never built
--   student         renamed to reporter (2026_08_reporter_user_type.sql); who a
--                   person is at BEC now lives in user_type, not in the role
--   faculty, guest  same — these are user_type values, not roles
--   pmo             the trap. Nothing calls requireRole('pmo') and the admin
--                   login hard-codes 'admin', so a PMO account could be created
--                   and then could not sign in anywhere. The PMO and the ITSO
--                   are administrators, told apart by users.department, which is
--                   what adminUnitForUser() reads.
--
-- Verified before writing this: no account, and no email_otp row, uses any of
-- the removed values. Nothing needs migrating — this only stops them coming back.

ALTER TABLE public.users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE public.users ADD CONSTRAINT users_role_check
    CHECK (role = ANY (ARRAY['admin'::text, 'technician'::text, 'reporter'::text]));

-- email_otp gains 'reporter' (reporters can now be sent a sign-in code) and
-- loses the same retired names.
ALTER TABLE public.email_otp DROP CONSTRAINT IF EXISTS email_otp_user_role_check;
ALTER TABLE public.email_otp ADD CONSTRAINT email_otp_user_role_check
    CHECK (user_role = ANY (ARRAY['admin'::text, 'technician'::text, 'reporter'::text]));
