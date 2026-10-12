---
name: dbexplorer-setup
description: Install, configure, upgrade or troubleshoot tacticalninj4/dbexplorer, the browser-based PostgreSQL explorer for Laravel — PostgreSQL logins per role, connections, the viewDbExplorer gate, roles, migrations, queue worker and scheduler.
---

# DbExplorer setup

## When to use this skill

Use it when adding `tacticalninj4/dbexplorer` to a Laravel app, changing its configuration, upgrading it, or working out why `/dbexplorer` returns 404/403 or background runs never finish.

The full reference ships with the package: `vendor/tacticalninj4/dbexplorer/README.md` (every config key and command) and `vendor/tacticalninj4/dbexplorer/docs/security.md` (threat model and the PostgreSQL role recipe). Read them rather than guessing.

## Ground rules

- **The database is the security boundary.** Each role (`viewer`, `editor`, `admin`) must use its own Laravel connection that logs in as its own PostgreSQL login with only the grants it needs. Never map a role to the app's main login, and never to a superuser: a superuser ignores every grant.
- **Don't run the role SQL yourself against a shared or production database.** Generate it, show it, and let the developer run it as a superuser (on a managed service, the master login). Running it on a local dev database is fine when asked.
- **Never invent secrets.** Use the audit key `dbx:install` prints; ask for or generate strong passwords for the logins and put them in `.env`, never in committed files.
- **Requirements:** PHP 8.0+, Laravel 9–13, PostgreSQL 12+ with `pdo_pgsql`. MySQL isn't supported.

## Install

```bash
composer require tacticalninj4/dbexplorer:^1.0
php artisan dbx:install
```

`dbx:install` publishes `config/dbexplorer.php` (`--force` to overwrite) and prints the remaining steps, the role SQL and a fresh audit key. It never edits `.env` or code — that is your job, step by step:

1. **`.env`**

   ```dotenv
   DBEXPLORER_ENABLED=true
   DBEXPLORER_AUDIT_KEY=base64:...        # the value dbx:install printed
   DBEXPLORER_VIEWER_PASSWORD=...
   DBEXPLORER_EDITOR_PASSWORD=...
   DBEXPLORER_ADMIN_PASSWORD=...
   DBEXPLORER_STORAGE_CONNECTION=dbexplorer_meta
   ```

   Without `DBEXPLORER_ENABLED=true` no routes exist (every URL 404s). Without an audit key the log falls back to `APP_KEY`, and rotating `APP_KEY` then breaks `dbx:audit:verify`.

2. **PostgreSQL logins.** Take the SQL from `docs/security.md` §3 (or the `dbx:install` output). Ask the developer which login runs their migrations and owns the tables: it replaces `app_owner`. Repeat the schema grants for every schema besides `public` that should be visible. The SQL creates `dbx_viewer` (SELECT only, read-only transactions), `dbx_editor` (DML, no DDL, no TRUNCATE), `dbx_admin` (member of `app_owner`), and a `dbexplorer` schema for the package's own tables.

3. **`config/database.php`**: one connection per login, copied from the app's `pgsql` connection, plus one for the package's tables:

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
   // 'pgsql_editor' (dbx_editor) and 'pgsql_admin' (dbx_admin): the same shape.

   // DbExplorer's own tables: the app's usual login, in the dbexplorer schema.
   'dbexplorer_meta' => [
       // ...same as 'pgsql', with:
       'search_path' => 'dbexplorer',
   ],
   ```

   Keep the app connection's `sslmode` and host settings: backups pass them on to `pg_dump`.

4. **`config/dbexplorer.php`**: map each connection label to role connections. A role left out can't use that label.

   ```php
   'connections' => [
       'main' => [
           'viewer' => 'pgsql_readonly',
           'editor' => 'pgsql_editor',
           'admin' => 'pgsql_admin',
       ],
   ],
   'queue' => [
       'connection' => null,
       'queue' => 'dbexplorer',
   ],
   ```

5. **Migrate**, after `DBEXPLORER_STORAGE_CONNECTION` is set, so the `dbx_*` tables land in the `dbexplorer` schema where the viewer and editor logins can't touch them:

   ```bash
   php artisan migrate
   ```

   If the developer insists on leaving `storage.connection` unset (tables in `public`), the `DO $$ … REVOKE` block in `docs/security.md` §3 must be re-run after every migrate, or editors can rewrite the audit log.

6. **Gate and roles** in `App\Providers\AppServiceProvider::boot()`. Outside the `local` environment nobody gets in until the gate exists; without a role callback every user who passes the gate is a `viewer`.

   ```php
   use Illuminate\Support\Facades\Gate;
   use TacticalNinj4\DbExplorer\Facades\DbExplorer;

   Gate::define('viewDbExplorer', fn ($user) => in_array($user->email, [
       'dba@example.com',
   ], true));

   DbExplorer::roleUsing(function ($user, string $label) {
       return $user->is_dba ? 'admin' : 'viewer'; // or null: no access to $label
   });
   ```

   Use whatever the app already has for authorization (roles, permissions, a column); ask if it's unclear who should be an admin.

7. **Queue worker and scheduler.** The package schedules `dbx:prune` (daily) and `dbx:schedule:tick` (every minute) itself — don't add them again. The host needs:
   - the usual `* * * * * php artisan schedule:run` cron;
   - a worker on the package's queue: `php artisan queue:work --queue=dbexplorer` (Supervisor, Horizon, Forge daemon…);
   - a daily `dbx:audit:verify` with alerting — `Schedule::command('dbx:audit:verify')->daily();` in `routes/console.php` on Laravel 11+, or in `app/Console/Kernel.php` on 9–10;
   - the queue connection's `retry_after` above `limits.background.timeout` (3600 s by default), or a second worker picks up a long run that is still going.

8. **Backups** (optional) need `pg_dump` whose major version is at least the server's; set `backup.pg_dump_path` if the one on `PATH` is older.

## Verify

```bash
php artisan route:list --path=dbexplorer   # routes exist only when enabled
php artisan dbx:audit:verify               # chain OK
```

Then open `/dbexplorer` signed in as a user who passes the gate. In the SQL editor, as a viewer, `select current_user` should return `dbx_viewer`; an `INSERT` should be refused.

## Configuration worth knowing

All keys are in `config/dbexplorer.php` and the README's configuration table.

- `path`, `domain`, `middleware` (keep an auth middleware), `ip_allowlist` (IPs/CIDRs, checked before the gate).
- `hidden` and `masking`: globs for objects hidden from browsing and for masked columns (`*.password`, `*.*token*`, `*.*secret*` by default). Masking is UX; for data viewers must never see, use a column-level grant (`docs/security.md` §3 step 8).
- `limits.{viewer,editor,admin}.timeout|rows`, `limits.background.timeout`, `limits.background.max_active_per_user` (default 3, 429 above it), `limits.import.max_upload_kb`.
- `confirm_password`: destructive actions ask for the user's password. Users without a stored password (SSO-only) can't confirm; set it to `false` only if the app has no passwords at all.
- `audit.retention_days`, `audit.log_channel` (forward audit entries off the database).
- `storage.disk` and `backup.disk` must be private disks.

## Upgrading

```bash
composer update tacticalninj4/dbexplorer
php artisan migrate
```

Read the package `CHANGELOG.md` upgrade notes for the target version. If the migrations were published (`--tag=dbexplorer-migrations`), publish them again before migrating. A published config keeps working; new keys fall back to their defaults.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `/dbexplorer` is 404 | `DBEXPLORER_ENABLED` isn't true (or config is cached: `php artisan config:clear`), or `path`/`domain` differ |
| 403 for everyone | No `viewDbExplorer` gate outside `local`, the gate returns false, or the IP isn't in `ip_allowlist` |
| A label is missing for a user | `roleUsing` returned null for it, or `connections` has no mapping for that role |
| Runs stay "queued" | No worker on `queue.queue`, or `queue.connection` is `sync` (the UI warns) |
| Long runs fail or run twice | Queue `retry_after` lower than `limits.background.timeout` |
| Backup refused with 422 | `pg_dump` older than the server's major version |
| Can't confirm destructive actions | The user has no stored password (SSO); see `confirm_password` |
| Schedules disabled after upgrading to 1.0 | Expected: an admin must re-sync (re-approve) each schedule |
