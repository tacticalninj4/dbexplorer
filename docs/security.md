# DbExplorer — Security

DbExplorer puts a database client inside your Laravel app. This page covers what it defends against, how the layers fit together, the PostgreSQL setup that makes them real, and what the package can't guarantee on its own.

The short version: **the database grants are the security boundary.** Everything DbExplorer checks in PHP is a second line of defense. Give each role its own PostgreSQL login with only the privileges that role needs, using the recipe below.

## 1. Threat model

**In scope:**

- People who can reach your app but shouldn't reach the explorer: anonymous visitors, ordinary app users, clients outside your network.
- Explorer users acting beyond their role: a viewer trying to write, an editor trying to change schema, including through crafted SQL such as stacked statements, side-effecting functions, `DO` blocks or writable CTEs.
- Leaking connection details (host, user, password) to the browser.
- Script injection through data shown in the UI.
- Someone with write access to the audit table but not the audit key, quietly editing or removing audit rows.

**Out of scope:**

- A compromised app server or app code. Whoever runs code in your app has `APP_KEY`, the audit key and every database password in its config.
- Database superusers, and anyone holding your migration login, acting outside DbExplorer.
- A malicious DbExplorer admin. Admins are trusted with DDL; the audit log gives you accountability, not prevention.
- Database server hardening: `pg_hba.conf`, TLS, network exposure, patching.

## 2. Layers

Every request passes these in order. Each one assumes the ones before it can fail.

| # | Layer | What it does |
|---|---|---|
| 0 | `dbexplorer.enabled` | While false, no routes are registered and every URL is a 404. Off by default. |
| 1 | IP allowlist | `dbexplorer.ip_allowlist` rejects clients outside the listed IPs and CIDR ranges with a 403. |
| 2 | Your middleware | `dbexplorer.middleware`, `['web', 'auth']` by default. Keep an authentication middleware in it. |
| 3 | `viewDbExplorer` gate | Decides who may enter at all. Without a gate, only the `local` environment gets in. |
| 4 | Role and connection allowlist | `DbExplorer::roleUsing()` maps the user to `viewer`, `editor`, `admin` or no access, per connection label. Only labels in `dbexplorer.connections` exist, and each role uses its own Laravel connection. |
| 5 | Database login | Each of those connections logs in as a PostgreSQL role holding only that role's privileges (§3). **This is the boundary.** |
| 6 | Query limits | One statement per query via a native prepare, viewer queries in `BEGIN READ ONLY` (always rolled back), a transaction-local `statement_timeout` and a row cap per role, reads streamed through a server-side cursor. After every query the connection runs `DISCARD ALL` and gets its own settings back, so prepared statements, cursors, `SET ROLE` and other session state never reach the next user (it also drops temp tables, so give DbExplorer its own connections). A connection with an open transaction is refused. |
| 7 | Audit log | Every action is written to an HMAC hash-chained log (§5), optionally forwarded to a log channel. |

**Background work can't take over the host's queue.** The `dbexplorer` rate limiter (`limits.queries_per_minute`) caps API calls per user, and `limits.background.max_active_per_user` (3 by default) caps the runs one user can have queued or running per label: one more background query, export, import, script, background DDL or backup answers 429 and says why. Each run can hold a worker for up to `limits.background.timeout` seconds, so give DbExplorer **its own queue** (`queue.queue = 'dbexplorer'`) with its own worker (`php artisan queue:work --queue=dbexplorer`). Then even work within the caps never holds up the app's own jobs.

## 3. PostgreSQL roles

`php artisan dbx:install` prints this recipe too. It creates three logins:

- `dbx_viewer`: `SELECT` only, on existing and future tables, read-only transactions by default.
- `dbx_editor`: `SELECT`, `INSERT`, `UPDATE`, `DELETE`. No `TRUNCATE`, no DDL, no `CREATE` on any schema.
- `dbx_admin`: a member of your migration login (`app_owner` below), so it can change the schema of the tables that login owns.

None of them is a superuser. Never point a DbExplorer connection at a superuser: a superuser ignores every grant on this page.

The recipe was run against PostgreSQL 12 and 18. With it applied, the viewer gets `permission denied` on `INSERT`, `UPDATE`, `DELETE` and `CREATE TABLE` even after switching its session to read-write. The editor gets `permission denied` on `CREATE TABLE` and `CREATE FUNCTION`, and `must be owner` on `ALTER TABLE` and `DROP TABLE`.

```sql
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
```

Notes:

- **Timeouts** set with `ALTER ROLE` are session defaults, not caps: any session can `SET statement_timeout`. They catch connections that forget to set one.
- **Active sessions** (`GET api/{label}/sessions`, admins only) list the label database's sessions. Cancel and terminate only ever signal sessions of the same login (`usename = current_user`), so a reused pid can't hit someone else's backend; terminate needs a password confirmation, and both are audited. `pg_read_all_stats` lets `dbx_admin` see other logins' query text, your app's included. Don't grant `pg_signal_backend`: DbExplorer doesn't need it, and through the SQL editor it would let an admin cancel or terminate any non-superuser session.
- **DbExplorer's own tables** (`dbx_audit_log`, `dbx_runs`, ...) must not be writable by the explorer logins, or an editor could rewrite the audit log from the SQL editor. Two layouts:
  - *Recommended:* keep them in the `dbexplorer` schema (step 7), which the viewer and editor have no `USAGE` on, through a dedicated connection (below). Already migrated into `public`? Move them with `ALTER TABLE ... SET SCHEMA dbexplorer`.
  - *Default* (`storage.connection` null): they land in `public`, and steps 4 and 5 grant them to the viewer and editor. The `DO` block after step 7 revokes that, but step 5's default privileges grant each new `dbx_` table again, so rerun the block after every `php artisan migrate`. Alternatively, replace steps 4 and 5 with grants on an explicit table list, or keep the app's tables in their own schema.
  - Either way `dbx_admin` reaches them through `app_owner`. For audit rows no explorer login can touch, put `storage.connection` on a separate database. The default `hidden` patterns (`*.dbx_*`, `dbexplorer.*`) also keep these tables out of the UI, but that's best-effort, not a boundary.
- **Functions** are executable by `PUBLIC` by default, and a `SECURITY DEFINER` function runs with its owner's privileges. Review those, or run `ALTER DEFAULT PRIVILEGES FOR ROLE app_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC`.
- **Extensions** like `dblink` and `postgres_fdw` open their own connections outside the read-only transaction. Don't grant them to the explorer logins.

Then add one connection per login to `config/database.php`, alongside your `pgsql` connection:

```php
'pgsql_readonly' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => 'dbx_viewer',
    'password' => env('DBEXPLORER_VIEWER_PASSWORD'),
    'charset' => 'utf8',
    'prefix' => '',
    'search_path' => 'public',
    'sslmode' => 'prefer',
],

// 'pgsql_editor' and 'pgsql_admin': the same, with dbx_editor / dbx_admin and their passwords.

// DbExplorer's own tables: your usual login, but in the dbexplorer schema.
// `search_path` is the key on Laravel 9 through 13 (`schema` still works as an alias).
'dbexplorer_meta' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'charset' => 'utf8',
    'prefix' => '',
    'search_path' => 'dbexplorer',
    'sslmode' => 'prefer',
],
```

Set `DBEXPLORER_STORAGE_CONNECTION=dbexplorer_meta` in `.env` before running `php artisan migrate`, and map the role connections in `config/dbexplorer.php`:

```php
'connections' => [
    'main' => [
        'viewer' => 'pgsql_readonly',
        'editor' => 'pgsql_editor',
        'admin' => 'pgsql_admin',
    ],
],

```

## 4. What the app-level checks do not guarantee

These are known limits. Where a test exists, it is named `GAP: ...` so the behavior can't change silently.

- **The SQL classifier is UX only.** It labels statements as read, DML, DDL, destructive or unknown to drive confirmations: the query API asks the client to confirm anything that isn't a read (409), asks for the password for destructive SQL (423), and refuses DDL, including DROP, TRUNCATE and ALTER … DROP, from viewers and editors (403). It also refuses what it reads as DML (`INSERT`, `UPDATE`, `DELETE`, `MERGE`, `CALL`, `SELECT … FOR UPDATE`, a writable CTE) from viewers with a 403 "Viewers can't change data" in the SQL editor and for saved queries, before any confirmation, so a viewer isn't asked to confirm a write the database would refuse anyway. None of that is a security check; the database login is: a viewer's `SELECT f()`, where `f()` writes, passes the 403 and is stopped only by the read-only transaction and the grants. A function or procedure that runs DDL, `query_to_xml()`, `lo_import()` or `LOAD` reads as harmless to the classifier, and `Pgsql/ClassifierEvasionTest` proves the recipe's logins refuse each of them where the role's grants forbid it. Where the grants allow it, nothing refuses: an editor's `SELECT f()`, where `f()` updates rows, reads as harmless and writes without any confirmation prompt. Every login can still create temporary tables, because PostgreSQL grants `TEMPORARY` on the database to `PUBLIC`. They live only in that session, and DbExplorer's `DISCARD ALL` after every query drops them. Revoking `TEMPORARY` from `PUBLIC` would also take it from your app's own roles, so the recipe leaves it alone.
- **Masking misses aliases.** Masking matches `schema.table.column` through the source table PostgreSQL reports for each result column. `SELECT password AS x FROM users` returns the value unmasked, because PostgreSQL doesn't report the source column of an aliased expression. To really keep a column from someone, use a column grant (recipe step 8).
- **NOTICE messages are not masked.** A function or `DO` block can `RAISE NOTICE` any value it can read, and the SQL editor shows notices as they are (PHP 8.4+ only). Like aliasing, this is why a column grant, not masking, is the way to keep a value from a role.
- **Hidden objects leak through functions.** Before a query runs, DbExplorer plans it with `EXPLAIN` and rejects it, with the same error a missing table gives, when the plan touches a hidden table directly, through a view, a CTE or a subquery. A function that reads the table is not caught, and statements `EXPLAIN` can't plan (`COPY`, `CREATE VIEW`, `DO`, ...) aren't checked. Existence isn't secret either: the catalogs (`pg_class`, `to_regclass()`) show every table name. Revoke access in the database for real enforcement.
- **Hidden objects can show up in definitions.** The object browser leaves hidden objects out of listings and answers 404 for them, and a foreign key to a hidden table is shown as `REFERENCES <hidden>`. View, materialized view and function definitions are shown as PostgreSQL returns them, so a view over a hidden table still names it. Rewriting SQL bodies reliably isn't possible; revoke access in the database if the names themselves are sensitive.
- **Password re-confirmation is a speed bump, not a boundary.** Destructive actions answer 423 until the user re-enters their password at `POST api/confirm-password` (5 attempts a minute per user, every attempt audited, the password never). It uses Laravel's session key `auth.password_confirmed_at` and `auth.password_timeout`, so a confirmation in your app counts here and the reverse. The password is checked by the guard's user provider. Users without a password, such as SSO-only accounts, can't confirm and so can't run destructive actions. `dbexplorer.confirm_password => false` turns the check off: then a stolen or unattended session can drop tables straight away, so only do that when your SSO enforces its own re-authentication.
- **Tail truncation of the audit log isn't detectable from the chain.** Someone who deletes the newest rows can also rewrite `dbx_audit_head`. Set `audit.log_channel` so every entry, with its hash, is also shipped somewhere the database can't reach.
- **IP checks trust `$request->ip()`.** Behind a load balancer or proxy, configure the app's trusted proxies (`TrustProxies` middleware, or `trustProxies()` in `bootstrap/app.php` on Laravel 11+) to your proxy's addresses only. Too few and every request has the proxy's IP; trusting `*` on a server reachable directly lets clients forge `X-Forwarded-For`. The same IP goes into the audit log.
- **The viewer's read-only default is a session setting.** `default_transaction_read_only` can be turned off by the session. The grants are what stop writes.

## 5. Operating the audit log

Each `dbx_audit_log` row stores `hash = HMAC-SHA256(key, prev_hash . canonical_json(row))`. The key is `dbexplorer.audit.key` (`DBEXPLORER_AUDIT_KEY`), falling back to `APP_KEY`. Without the key, someone with only database access can't rebuild a valid chain after editing rows.

- **Verify on a schedule.** `php artisan dbx:audit:verify` recomputes the chain and exits non-zero at the first edited, deleted or reordered row. Run it daily and alert on failure.
- **Pruning is scheduled for you.** The package schedules `dbx:prune` daily on the host's scheduler (don't add it again). It deletes audit rows older than `audit.retention_days` (90 by default), after writing a `pruned` checkpoint so verification still passes, along with finished runs, query history and expired result files.
- **Forward entries.** Set `audit.log_channel` to a channel that leaves the box (syslog, a SIEM) to cover tail truncation.
- **The viewer is per label.** `GET api/{label}/audit` needs the admin role on `{label}`, and shows only rows from labels where the user is admin. Rows without a label (password confirmations) show only to a user who is admin on every configured label. A `label` filter outside that set gives an empty page.
- **Set a dedicated key.** Rotating `APP_KEY` would otherwise break verification of every existing row.

**Rotating the audit key** breaks verification of every row signed with the old key; rows aren't re-signed. To rotate:

1. Disable DbExplorer for the window (`DBEXPLORER_ENABLED=false`) so no new rows arrive.
2. Run `php artisan dbx:audit:verify` and make sure it passes.
3. Archive `dbx_audit_log` and `dbx_audit_head`, for example with `pg_dump --table`, and keep the old key with the archive so it can be verified later.
4. Start a new chain: `DELETE FROM dbx_audit_log; UPDATE dbx_audit_head SET hash = NULL WHERE id = 1;`
5. Set the new `DBEXPLORER_AUDIT_KEY`, clear the config cache, run `dbx:audit:verify` (0 entries), and re-enable DbExplorer.

## 6. Scheduled queries

A schedule runs unattended as an admin, so it never runs SQL an admin didn't approve.

- **Snapshot, not a reference.** Creating a schedule (`POST api/{label}/schedules`) copies the saved query's SQL and parameter definitions into the schedule, with its SHA-256 (`sql_hash`), and records the approving admin (`approved_by`, `approved_at`). Later edits to the saved query, or making it private, change nothing: the schedule's `in_sync` turns false, and the job runs the snapshot. `saved_query_id` is provenance only.
- **Approval binds to the reviewed SQL.** Creating and re-syncing both require the `sql_hash` of the SQL the admin was shown (the saved queries API returns it with each query). The server compares it with the saved query's current SQL while holding the saved query's row lock, and answers 409 ("the saved query changed") without approving anything when they differ, so an edit made between the admin's review and their click can't slip through. A missing or malformed hash is a 422. The UI reloads the saved query and shows its current SQL to review again.
- **Re-sync is a fresh approval.** `POST api/{label}/schedules/{id}/resync` (admins only) copies the saved query's current SQL again — only if the admin can still see it, own or shared, else 404 — and records them as the approver. Both creating and re-syncing are audited (`schedule.created`, `schedule.resynced`) with the approved SQL, its hash and the permissions granted.
- **Runs as the approver.** Each tick and each job re-resolves the approver's role on the label. If it isn't `admin` any more, the run fails, the schedule is disabled (audited as `schedule.disabled`) and `ScheduleFailed` fires. A snapshot that is missing, unapproved or doesn't match its hash is disabled the same way and never runs.
- **Read-only unless approved for writes.** A schedule's statement runs in `BEGIN READ ONLY`, so even a write the classifier misses (a volatile function, `nextval()`) fails at the database. `allow_writes` lets it run in a read-write transaction; DDL also needs `allow_ddl` (which implies `allow_writes`). Both are set only when approving: SQL the classifier calls DML or destructive is refused without `allow_writes` (422), DDL without `allow_ddl` (422), and granting either needs a recent password confirmation (423, S5). Nobody confirms at run time — there is no one there.
- **Upgrading an install from before snapshots.** The migration copies each existing schedule's current saved-query SQL into it but leaves it **unapproved and disabled**: the SQL may have been edited by someone else since it was scheduled. Review each one, re-sync it, then enable it (`PATCH … {"enabled": true}` is refused until it is approved).
- **Mail can't carry a viewer's link.** The schedule keeps the saved query's name as approved with the SQL, so renaming the saved query changes nothing in its mail until an admin re-syncs. User-supplied text in the mail (the name, the error) is Markdown-escaped, so `[Reset password](https://…)` arrives as text, never a link.
- **ScheduleFailed** carries the schedule id, run id and the audit-style error (SQLSTATE and primary message), never SQL, bound values or connection config, and fires after the run's audit row. A failed run doesn't disable the schedule: the job releases the schedule's lock when it ends, so the next due tick runs it again.

## 7. Backups

Backups run `pg_dump` through Symfony Process as an **argument array** — never a shell string — and the password reaches it only through the `PGPASSWORD` environment variable, never on the command line where `ps` or an error log would show it. The connection's `sslmode`, `sslrootcert`, `sslcert` and `sslkey` reach it the same way (`PGSSLMODE`, `PGSSLROOTCERT`, `PGSSLCERT`, `PGSSLKEY`), so it connects as securely as the app does. Before queueing, `POST api/{label}/backups` runs a preflight (pg_dump present and at least the server's major version) and answers 422 with the problem instead of queueing a dump that would fail. A failed dump's error has the host, port, login, database and password redacted. Cancelling a backup run stops `pg_dump` (ending its database session) and deletes the partial file, so a cut-off dump never sits among the backups. A dump is stored on the private disk configured at `backup.disk`, in custom format, and downloads through the same short-lived signed URL as any run result, for its owner or an admin of the label.

**Restoring is a CLI job, deliberately not an API.** An authenticated admin being able to trigger a restore of the database they browse would make every other control in this document decorative. To restore:

```bash
pg_restore --host ... --username ... --dbname <target database> --clean --if-exists <dump file>
```

Point `pg_restore` at a database *you* choose (a copy first, the real one after checking it), with a login *you* control. The dump's `pg_restore --list` shows what it contains before you commit to anything.

## 8. Reporting a vulnerability

Please don't open a public issue. Follow [the security policy](../.github/SECURITY.md).
