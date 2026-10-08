# DbExplorer — Security

DbExplorer puts a database client inside your Laravel app. This page covers what it defends against, how the layers fit together, the PostgreSQL setup that makes them real, and what the package can't guarantee on its own.

The short version: **the database grants are the security boundary.** Everything DbExplorer checks in PHP is a second line of defense. Give each role its own PostgreSQL login with only the privileges that role needs, using the recipe below.

Items marked *(planned)* are designed and specified in [PRD.md](PRD.md) but not built yet.

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
| 6 | Query limits | One statement per query via a native prepare, viewer queries in `BEGIN READ ONLY` (always rolled back), a transaction-local `statement_timeout` and a row cap per role, reads streamed through a server-side cursor. |
| 7 | Audit log | Every action is written to an HMAC hash-chained log (§5), optionally forwarded to a log channel. |

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
-- Optional, for the planned active-sessions screen: see and cancel other sessions.
-- GRANT pg_read_all_stats, pg_signal_backend TO dbx_admin;

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
- **`pg_signal_backend`** lets `dbx_admin` cancel or terminate *any* non-superuser session, your app's included. Grant it only if you want that from the browser.
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

- **The SQL classifier is UX only** *(planned, M1)*. It labels statements as read, DML, DDL or destructive to drive confirmations. It is not a security check; the database login is.
- **Masking misses aliases** *(planned, M1)*. Masking matches `schema.table.column` through the source table PostgreSQL reports for each result column. `SELECT password AS x FROM users` returns the value unmasked, because PostgreSQL doesn't report the source column of an aliased expression. To really keep a column from someone, use a column grant (recipe step 8).
- **NOTICE messages are not masked.** A function or `DO` block can `RAISE NOTICE` any value it can read, and the SQL editor shows notices as they are (PHP 8.4+ only). Like aliasing, this is why a column grant, not masking, is the way to keep a value from a role.
- **Hidden objects leak through functions.** Before a query runs, DbExplorer plans it with `EXPLAIN` and rejects it, with the same error a missing table gives, when the plan touches a hidden table directly, through a view, a CTE or a subquery. A function that reads the table is not caught, and statements `EXPLAIN` can't plan (`COPY`, `CREATE VIEW`, `DO`, ...) aren't checked. Existence isn't secret either: the catalogs (`pg_class`, `to_regclass()`) show every table name. Revoke access in the database for real enforcement.
- **Hidden objects can show up in definitions.** The object browser leaves hidden objects out of listings and answers 404 for them, and a foreign key to a hidden table is shown as `REFERENCES <hidden>`. View, materialized view and function definitions are shown as PostgreSQL returns them, so a view over a hidden table still names it. Rewriting SQL bodies reliably isn't possible; revoke access in the database if the names themselves are sensitive.
- **Tail truncation of the audit log isn't detectable from the chain.** Someone who deletes the newest rows can also rewrite `dbx_audit_head`. Set `audit.log_channel` so every entry, with its hash, is also shipped somewhere the database can't reach.
- **IP checks trust `$request->ip()`.** Behind a load balancer or proxy, configure the app's trusted proxies (`TrustProxies` middleware, or `trustProxies()` in `bootstrap/app.php` on Laravel 11+) to your proxy's addresses only. Too few and every request has the proxy's IP; trusting `*` on a server reachable directly lets clients forge `X-Forwarded-For`. The same IP goes into the audit log.
- **The viewer's read-only default is a session setting.** `default_transaction_read_only` can be turned off by the session. The grants are what stop writes.

## 5. Operating the audit log

Each `dbx_audit_log` row stores `hash = HMAC-SHA256(key, prev_hash . canonical_json(row))`. The key is `dbexplorer.audit.key` (`DBEXPLORER_AUDIT_KEY`), falling back to `APP_KEY`. Without the key, someone with only database access can't rebuild a valid chain after editing rows.

- **Verify on a schedule.** `php artisan dbx:audit:verify` recomputes the chain and exits non-zero at the first edited, deleted or reordered row. Run it daily and alert on failure.
- **Prune on a schedule.** `php artisan dbx:prune` deletes rows older than `audit.retention_days` (90 by default). It first writes a `pruned` checkpoint, so verification still passes afterwards.
- **Forward entries.** Set `audit.log_channel` to a channel that leaves the box (syslog, a SIEM) to cover tail truncation.
- **Set a dedicated key.** Rotating `APP_KEY` would otherwise break verification of every existing row.

**Rotating the audit key** breaks verification of every row signed with the old key; rows aren't re-signed. To rotate:

1. Disable DbExplorer for the window (`DBEXPLORER_ENABLED=false`) so no new rows arrive.
2. Run `php artisan dbx:audit:verify` and make sure it passes.
3. Archive `dbx_audit_log` and `dbx_audit_head`, for example with `pg_dump --table`, and keep the old key with the archive so it can be verified later.
4. Start a new chain: `DELETE FROM dbx_audit_log; UPDATE dbx_audit_head SET hash = NULL WHERE id = 1;`
5. Set the new `DBEXPLORER_AUDIT_KEY`, clear the config cache, run `dbx:audit:verify` (0 entries), and re-enable DbExplorer.

## 6. Reporting a vulnerability

Please don't open a public issue. Follow [the security policy](../.github/SECURITY.md).
