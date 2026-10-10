-- DbExplorer PostgreSQL logins (docs/security.md).
-- Run once, connected to your app's database, as a superuser (on a managed
-- service, its master login). DbExplorer itself never needs a superuser.
-- Before running, replace:
--   app_owner   the login your migrations run as (it owns your tables)
--   change-me   one strong password per login
--   public      repeat the schema grants for every schema DbExplorer should see

-- 1. Logins. Never SUPERUSER, never BYPASSRLS.
CREATE ROLE dbx_viewer LOGIN PASSWORD 'change-me' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE dbx_editor LOGIN PASSWORD 'change-me' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE dbx_admin  LOGIN PASSWORD 'change-me' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

-- 2. Server-side defaults. Sessions can still SET these; DbExplorer sets its own per query.
ALTER ROLE dbx_viewer SET statement_timeout = '30s';
ALTER ROLE dbx_editor SET statement_timeout = '60s';
ALTER ROLE dbx_admin  SET statement_timeout = '120s';
ALTER ROLE dbx_viewer SET default_transaction_read_only = on;

-- 3. PostgreSQL 14 and older let every role CREATE in schema public (15+ doesn't).
--    If app_owner doesn't own public, first: GRANT CREATE ON SCHEMA public TO app_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

-- 4. Viewer: SELECT only. Editor: DML, no TRUNCATE, no DDL.
GRANT USAGE ON SCHEMA public TO dbx_viewer, dbx_editor;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO dbx_viewer;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO dbx_viewer;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO dbx_editor;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO dbx_editor;

-- 5. The same grants on tables app_owner creates later.
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT SELECT ON TABLES TO dbx_viewer;
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT SELECT ON SEQUENCES TO dbx_viewer;
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO dbx_editor;
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO dbx_editor;

-- 6. Admin: DDL on the app's objects through membership of the owner role.
GRANT app_owner TO dbx_admin;
-- Optional, for the active-sessions API: also show other logins' queries.
-- DbExplorer only ever cancels or terminates dbx_admin's own sessions.
-- GRANT pg_read_all_stats TO dbx_admin;

-- 7. A schema for DbExplorer's own tables (audit log, runs, ...) that the viewer
--    and editor can't use. Point dbexplorer.storage.connection at it.
CREATE SCHEMA dbexplorer AUTHORIZATION app_owner;

-- If you keep DbExplorer's tables in public instead, steps 4 and 5 grant them to
-- the viewer and editor. This takes them back. Step 5's default privileges
-- grant every new dbx_ table again, so rerun it after each `php artisan migrate`.
DO $$
DECLARE r record;
BEGIN
  FOR r IN SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
           WHERE n.nspname = 'public' AND c.relname LIKE 'dbx\_%' AND c.relkind IN ('r', 'p', 'S')
  LOOP
    EXECUTE format('REVOKE ALL ON public.%I FROM dbx_viewer, dbx_editor', r.relname);
  END LOOP;
END $$;

-- 8. Optional: keep sensitive columns from the viewer. A column REVOKE does
--    nothing while a table-wide SELECT exists, so swap it for a column list.
-- REVOKE SELECT ON public.users FROM dbx_viewer;
-- GRANT SELECT (id, name, email, created_at, updated_at) ON public.users TO dbx_viewer;
