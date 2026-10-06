-- 2026_10_close_public_api.sql — run once, 6 October 2026.
--
-- Supabase publishes the `public` schema through its REST and GraphQL APIs to
-- the `anon` and `authenticated` roles — the roles any holder of the project's
-- PUBLIC (anon / publishable) key acts as. Those keys are public by design, and
-- every table here was created with row-level security off and full grants to
-- both roles, so the public key could read, change and delete every row:
-- users, email_otp (live sign-in codes), password_resets, bec_directory,
-- defect_reports — Supabase's advisor reported it on 3 October 2026
-- (rls_disabled_in_public, sensitive_columns_exposed).
--
-- This system never uses that API. It connects to Postgres directly as
-- `postgres`, which owns every table and has BYPASSRLS, so none of this changes
-- what the application can do.
--
-- To undo (do not, unless something truly needs the public API):
--   ALTER TABLE <table> DISABLE ROW LEVEL SECURITY;
--   GRANT ALL ON ALL TABLES IN SCHEMA public TO anon, authenticated;

BEGIN;

-- 1. Row-level security on every table, with no policies: the API roles see no rows.
DO $$
DECLARE t regclass;
BEGIN
  FOR t IN
    SELECT c.oid::regclass
      FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relrowsecurity
  LOOP
    EXECUTE format('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', t);
  END LOOP;
END $$;

-- 2. And take the API roles' privileges away altogether. RLS does not cover
--    TRUNCATE, and a table someone forgets to protect later should still be
--    closed by default.
REVOKE ALL ON ALL TABLES    IN SCHEMA public FROM anon, authenticated;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated;

-- 3. Tables and sequences created from now on start out closed too.
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON TABLES    FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON SEQUENCES FROM anon, authenticated;

-- 4. The SECRET (service_role) key bypasses row-level security, and a copy of it
--    left the building in September (an old backup on a cloud-synced desktop).
--    Nothing here uses it either, so its table access goes too; rotating the key
--    in the Supabase dashboard is still the real fix.
--    To undo: GRANT ALL ON ALL TABLES IN SCHEMA public TO service_role;
REVOKE ALL ON ALL TABLES    IN SCHEMA public FROM service_role;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM service_role;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON TABLES    FROM service_role;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON SEQUENCES FROM service_role;

COMMIT;
