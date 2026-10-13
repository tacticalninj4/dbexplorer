<div align="center">
    <h1>DbExplorer</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/tacticalninj4/dbexplorer"><img src="https://img.shields.io/packagist/v/tacticalninj4/dbexplorer.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/tacticalninj4/dbexplorer"><img src="https://img.shields.io/packagist/php-v/tacticalninj4/dbexplorer.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/tacticalninj4/dbexplorer"><img src="https://badge.laravel.cloud/badge/tacticalninj4/dbexplorer?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/tacticalninj4/dbexplorer/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/tacticalninj4/dbexplorer/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/tacticalninj4/dbexplorer"><img src="https://img.shields.io/packagist/dt/tacticalninj4/dbexplorer.svg?style=flat-square" alt="Total Downloads"></a>
</p>

DbExplorer mounts a secure, browser-based PostgreSQL explorer inside your Laravel app. It reuses your app's auth, database connections, queue and scheduler, so there's no separate pgAdmin or phpMyAdmin to deploy, patch and expose.

> [!NOTE]
> **v1.0.0** is the first release. Every feature on the [Roadmap](#roadmap) is built and tested, through the JSON API and the browser UI: browsing, the data grid with inline editing and an explicit "Overwrite with mine" after a save conflict, the SQL editor with run in background and export, history, saved queries, schedules, backups, active sessions, the audit log viewer, CSV import, scripts, the Runs page, and schema forms for create table, view and materialized view, constraints and indexes. Altering a column's type, default or nullability runs from the SQL editor or the API. See the [CHANGELOG](CHANGELOG.md).

## Security model

- **Off by default.** Until `DBEXPLORER_ENABLED=true`, no routes exist and every URL is a 404.
- **Deny by default.** Outside the `local` environment nobody gets in until you define the `viewDbExplorer` gate. An optional IP/CIDR allowlist runs before it.
- **The database is the boundary.** Each role (`viewer`, `editor`, `admin`) uses its own Laravel connection and PostgreSQL login with only the privileges it needs. App-level checks are a second line of defense.
- **No credentials leave the server.** Only connections you list in `dbexplorer.connections` are usable, and their config is never sent to the browser.
- **Everything is audited.** Actions go to an HMAC hash-chained log that `dbx:audit:verify` checks for edited, deleted or reordered rows, and can be forwarded to a log channel.

Read [docs/security.md](docs/security.md) for the threat model, the PostgreSQL role recipe and the known limits.

## Requirements

| | |
|---|---|
| PHP | 8.0+ |
| Laravel | 9, 10, 11, 12, 13 |
| PostgreSQL | 12+, with the `pdo_pgsql` extension |
| Scheduler | Laravel's `schedule:run` cron, for the package's own `dbx:prune` and `dbx:schedule:tick`, and your `dbx:audit:verify` |
| Queue worker | for background queries, exports, imports, scripts, backups and scheduled queries |
| `pg_dump` | for backups, matching your server's major version |

Several of these Laravel and PHP versions no longer get upstream security fixes; support for those is best-effort. MySQL support is planned for v2.

## Installation

```bash
composer require tacticalninj4/dbexplorer:^1.0
php artisan dbx:install
```

Using an AI agent? With [Laravel Boost](https://laravel.com/docs/boost), `php artisan boost:install` (or `boost:update`) adds this package's `dbexplorer-setup` skill, which walks the agent through the steps below.

`dbx:install` publishes `config/dbexplorer.php` (it won't overwrite an existing one unless you pass `--force`) and prints every remaining step: the `.env` values with a freshly generated audit key, the gate and role stubs, the PostgreSQL role SQL, and the scheduler and queue requirements. It never edits your `.env` or code.

### 1. Enable it

```dotenv
DBEXPLORER_ENABLED=true
DBEXPLORER_AUDIT_KEY=base64:...   # printed by dbx:install
```

Without `DBEXPLORER_AUDIT_KEY` the audit log falls back to `APP_KEY`, and rotating `APP_KEY` would then break verification of existing audit rows.

### 2. Create the PostgreSQL logins

Run the SQL from [docs/security.md](docs/security.md#3-postgresql-roles) (also printed by `dbx:install`). It creates `dbx_viewer` (SELECT only), `dbx_editor` (DML, no DDL) and `dbx_admin`, and a `dbexplorer` schema for the package's own tables. Then add matching connections to `config/database.php`: the page has a copy-paste snippet.

### 3. Run the migrations

Recommended: keep DbExplorer's own tables (audit log, runs, ...) in the `dbexplorer` schema, out of reach of the viewer and editor logins. Add a `dbexplorer_meta` connection with `'search_path' => 'dbexplorer'` ([snippet](docs/security.md#3-postgresql-roles)) and point the package at it before migrating:

```dotenv
DBEXPLORER_STORAGE_CONNECTION=dbexplorer_meta
```

```bash
php artisan migrate
```

Left unset, the tables go to your default connection's `public` schema; see [docs/security.md](docs/security.md#3-postgresql-roles) for the extra `REVOKE` that layout needs.

### 4. Define the gate

In `App\Providers\AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewDbExplorer', function ($user) {
    return in_array($user->email, [
        'you@example.com',
    ], true);
});
```

### 5. Assign roles

Users who pass the gate get a role per connection label. Without a callback, everyone is a `viewer`.

```php
use TacticalNinj4\DbExplorer\Facades\DbExplorer;

DbExplorer::roleUsing(function ($user, string $label) {
    if ($label === 'staging') {
        return 'admin';
    }

    return $user->is_dba ? 'editor' : 'viewer'; // or null: no access to this label
});
```

Each label maps every role to its own Laravel connection, so a viewer's queries run as the `dbx_viewer` login no matter what the app layer does:

```php
// config/dbexplorer.php
'connections' => [
    'main' => [
        'viewer' => 'pgsql_readonly',  // logs in as dbx_viewer
        'editor' => 'pgsql_editor',    // logs in as dbx_editor
        'admin' => 'pgsql_admin',      // logs in as dbx_admin
    ],
    'staging' => [
        'admin' => 'staging_admin',    // no viewer or editor mapping: those roles can't use it
    ],
],
```

### 6. Run the scheduler and a queue worker

DbExplorer registers its own scheduled commands: `dbx:prune` daily and `dbx:schedule:tick` every minute. Don't add them yourself, or they run twice. The host only needs Laravel's usual scheduler cron:

```
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

Verify the audit chain daily and alert when it fails. Laravel 11+, in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('dbx:audit:verify')->daily();
```

Laravel 9 and 10, in `app/Console/Kernel.php`:

```php
$schedule->command('dbx:audit:verify')->daily();
```

Background queries, exports, imports, scripts, backups and scheduled queries run on the queue (`dbexplorer.queue`), so keep a worker running: `php artisan queue:work`. Better, and recommended, give them a queue of their own, so a long export never holds up your app's jobs:

```php
// config/dbexplorer.php
'queue' => [
    'connection' => null,       // the app's default queue connection
    'queue' => 'dbexplorer',
],
```

```bash
php artisan queue:work --queue=dbexplorer
```

Each user may have `limits.background.max_active_per_user` runs (default 3) queued or running per connection label; one more is refused with a 429. A run may take up to `limits.background.timeout` seconds (each job sets that as its own timeout), so give the queue connection a `retry_after` above it; otherwise a second worker picks up a long run while it's still going and fails it.

Then open `/dbexplorer` in your app.

## Upgrading

```bash
composer update tacticalninj4/dbexplorer
php artisan migrate
```

The package loads its migrations itself, so `php artisan migrate` picks up new ones; if you published them (`--tag=dbexplorer-migrations`), publish again first. Read the [CHANGELOG](CHANGELOG.md) for each version's upgrade notes. From a pre-release checkout of `main` to 1.0.0:

- Migration `000006` gives each schedule an approved SQL snapshot and **disables existing schedules** until an admin re-syncs them; migration `000007` snapshots each schedule's name. See [docs/security.md](docs/security.md#6-scheduled-queries).
- API clients that create or re-sync schedules must send the reviewed `sql_hash`, and should expect a 429 above the run cap.
- A published `config/dbexplorer.php` keeps working; new keys such as `limits.background.max_active_per_user` fall back to their defaults until you add them.

## Configuration

All keys live in `config/dbexplorer.php`.

| Key | Default | Description |
|---|---|---|
| `enabled` | `env('DBEXPLORER_ENABLED', false)` | Master switch. While false, no routes are registered. |
| `path` | `'dbexplorer'` | URI prefix of the explorer. |
| `domain` | `null` | Restrict the routes to one domain, e.g. `'db.example.com'`. |
| `middleware` | `['web', 'auth']` | Middleware for every route. Keep an auth middleware in it. |
| `ip_allowlist` | `[]` | IPs or CIDR ranges allowed in. Empty allows any IP. |
| `connections` | `main` → `pgsql_readonly` / `pgsql_editor` / `pgsql` | Label → role → Laravel connection. The only databases DbExplorer can reach. |
| `limits.{viewer,editor,admin}.timeout` | `30` / `60` / `120` | Statement timeout in seconds per role. |
| `limits.{viewer,editor,admin}.rows` | `1000` / `5000` / `10000` | Maximum rows returned per role, and the largest grid page. |
| `limits.background.timeout` | `3600` | Seconds a queued run may take (the job timeout), and the statement timeout of background queries and exports. |
| `limits.background.max_active_per_user` | `3` | Runs one user may have queued or running at once per connection label (background queries, exports, imports, scripts, background DDL, backups); one more answers 429. `0` turns the cap off. Scheduled runs don't count. |
| `limits.queries_per_minute` | `60` | The `dbexplorer` rate limiter, per user or per IP, for every per-connection API call. |
| `limits.sql_length` | `100000` | Maximum characters of SQL the editor may submit. |
| `limits.import.max_upload_kb` | `51200` | Largest CSV upload, in kilobytes. |
| `limits.import.batch` | `5000` | Rows per `COPY` batch in a CSV import; cancel is checked between batches, and skip-bad-rows mode retries a failed batch row by row. |
| `confirm_password` | `true` | Destructive actions need the password re-confirmed within Laravel's `auth.password_timeout` (shared with the host app's `password.confirm`). Users without a stored password (such as SSO-only accounts) can't confirm, so can't run them. `false` skips confirmation entirely; see [docs/security.md](docs/security.md). |
| `hidden` | `pg_toast*`, `*.password_reset*`, `*.personal_access_tokens`, `*.dbx_*`, `dbexplorer.*` | Globs for schemas and `schema.table` names hidden from browsing (404) and, best effort, from queries. The defaults include DbExplorer's own tables. |
| `masking` | `*.password`, `*.*token*`, `*.*secret*` | Globs (or callables) on `schema.table.column` whose values are masked in grids, edit views and exports. Aliased columns aren't masked; see [docs/security.md](docs/security.md). |
| `queue.connection` | `null` | Queue connection for runs; null uses the app default. A `sync` connection shows a warning. |
| `queue.queue` | `'default'` | Queue name for runs. Recommended: a dedicated queue such as `'dbexplorer'` with its own worker (`php artisan queue:work --queue=dbexplorer`), so runs can't starve the app's own jobs. |
| `backup.pg_dump_path` | `'pg_dump'` | The `pg_dump` binary. Its major version must be at least the server's. |
| `backup.disk` | `'local'` | Private filesystem disk backups are written to. |
| `backup.keep` | `7` | Backups kept per connection label. |
| `storage.connection` | `env('DBEXPLORER_STORAGE_CONNECTION')` | Connection for the `dbx_*` tables; null uses the app default. Recommended: a connection on the `dbexplorer` schema. |
| `storage.disk` | `'local'` | Private disk for exports and CSV uploads. A public disk logs a warning. |
| `storage.file_ttl_hours` | `24` | `dbx:prune` deletes exports and uploads older than this. |
| `audit.retention_days` | `90` | `dbx:prune` deletes audit rows, finished runs and query history older than this. Null keeps them forever. |
| `audit.log_channel` | `null` | Log channel that also receives every audit entry and its hash. |
| `audit.key` | `env('DBEXPLORER_AUDIT_KEY')` | HMAC key for the audit chain; falls back to `APP_KEY`. |

Other publishable resources: `--tag=dbexplorer-migrations`, `dbexplorer-views` and `dbexplorer-lang` via `php artisan vendor:publish`.

## Commands

| Command | What it does |
|---|---|
| `dbx:install [--force]` | Publishes the config and prints the remaining setup steps. |
| `dbx:audit:verify` | Recomputes the audit hash chain; exits non-zero at the first edited, deleted or reordered row. Schedule it yourself. |
| `dbx:prune` | Deletes expired exports and uploads (`storage.file_ttl_hours`), then finished runs, query history and audit rows older than `audit.retention_days`, writing an audit checkpoint so the chain still verifies. Scheduled daily by the package. |
| `dbx:schedule:tick` | Queues the scheduled queries that are due, one at a time per schedule. Scheduled every minute by the package. |

## Roadmap

| Milestone | Scope | API | UI |
|---|---|---|---|
| M0 | Config, routing, gate, IP allowlist, roles, audit log, app shell, `dbx:install` | Built | Built |
| M1 | Object browser, read-only data grid and SQL editor, EXPLAIN, history, hidden objects, masking, timeouts and row caps | Built | Built |
| M2 | Inline row editing, DML and DDL, password re-confirmation for destructive actions | Built | Built: row editing with conflict overwrite, forms for create table, view and materialized view, columns, indexes and constraints. Column type/default/nullability changes run from the SQL editor |
| M3 | Background queries, exports, CSV import, script runner, signed downloads | Built | Built: run in background, export dialog, CSV import wizard, scripts, Runs page (progress, cancel, download) |
| M4 | Saved and scheduled queries, backups, active sessions, audit log viewer | Built | Built |

The full plan is in [docs/PRD.md](docs/PRD.md).

## Testing

```bash
composer test        # PHPStan, Pint, type coverage and the Pest suite
```

The PostgreSQL integration tests run when `DB_PGSQL_HOST` is set, and are skipped otherwise. See [docs/TESTING.md](docs/TESTING.md) for the local Docker setup and the full strategy.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to DbExplorer! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Jhanarthananraja](https://github.com/tacticalninj4)
- [All Contributors](../../contributors)

## License

DbExplorer is dual-licensed: free under the [GNU AGPL v3.0](LICENSE.md), or under a commercial license for use without the AGPL's conditions, such as in closed-source products. See [LICENSING.md](LICENSING.md) for which one you need and how to get a commercial license.

Versions v1.0.0 to v1.0.2 were released under the MIT license and remain available under it.
