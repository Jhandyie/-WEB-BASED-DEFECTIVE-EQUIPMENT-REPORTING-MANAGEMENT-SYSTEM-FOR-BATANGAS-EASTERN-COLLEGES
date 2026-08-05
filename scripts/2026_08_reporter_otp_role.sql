-- 2026-08-05 — Let a one-time code be issued to a reporter.
--
-- email_otp.user_role is constrained to a list written before reporters existed
-- as a role of their own: it still names 'student', 'dean' and 'finance', all
-- since retired, and has no entry for 'reporter'. Issuing a reporter a sign-in
-- code therefore failed on the check constraint.
--
-- The retired names are kept rather than dropped — there are historical rows
-- carrying them, and this migration is about unblocking the new value, not
-- about tidying the old ones.

ALTER TABLE public.email_otp DROP CONSTRAINT IF EXISTS email_otp_user_role_check;

ALTER TABLE public.email_otp ADD CONSTRAINT email_otp_user_role_check
    CHECK (user_role = ANY (ARRAY[
        'admin'::text, 'handler'::text, 'technician'::text, 'faculty'::text,
        'student'::text, 'pmo'::text, 'dean'::text, 'finance'::text,
        'guest'::text, 'reporter'::text
    ]));
