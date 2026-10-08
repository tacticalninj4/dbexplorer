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

> [!IMPORTANT]
> **Pre-release.** The foundation is built: the enable switch, routing, gate, IP allowlist, roles, the role → connection mapping, the hash-chained audit log and the app shell. The explorer itself (object browser, data grid, SQL editor) and everything after it are **planned**, see [Roadmap](#roadmap). Don't expect to browse data yet.

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
| Scheduler | for `dbx:prune` and `dbx:audit:verify` |
| Queue worker | for background queries, exports, imports and scripts *(planned)* |
| `pg_dump` | for backups *(planned)*, matching your server's major version |

Several of these Laravel and PHP versions no longer get upstream security fixes; support for those is best-effort. MySQL support is planned for v2.

## Installation

```bash
composer require tacticalninj4/dbexplorer
php artisan dbx:install
```

`dbx:install` publishes `config/dbexplorer.php` (it won't overwrite an existing one unless you pass `--force`) and prints every remaining step: the `.env` values with a freshly generated audit key, the gate and role stubs, the PostgreSQL role SQL, and the scheduler entries. It never edits your `.env` or code.

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

### 6. Schedule the maintenance commands

Laravel 11+, in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('dbx:prune')->daily();
Schedule::command('dbx:audit:verify')->daily();
```

Laravel 9 and 10, in `app/Console/Kernel.php`:

```php
$schedule->command('dbx:prune')->daily();
$schedule->command('dbx:audit:verify')->daily();
```

Then open `/dbexplorer` in your app.

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
| `limits.{viewer,editor,admin}.timeout` | `30` / `60` / `120` | Statement timeout in seconds per role *(planned, M1)*. |
| `limits.{viewer,editor,admin}.rows` | `1000` / `5000` / `10000` | Maximum rows returned per role *(planned, M1)*. |
| `limits.background.timeout` | `3600` | Statement timeout in seconds for queued jobs *(planned, M3)*. |
| `limits.queries_per_minute` | `60` | The `dbexplorer` rate limiter, per user or per IP, for query execution *(planned, M1)*. |
| `hidden` | `pg_toast*`, `*.password_reset*`, `*.personal_access_tokens`, `*.dbx_*`, `dbexplorer.*` | Globs for schemas and tables hidden from browsing, including DbExplorer's own tables *(planned, M1)*. |
| `masking` | `*.password`, `*.*token*`, `*.*secret*` | Globs for `table.column` values masked in grids and exports *(planned, M1)*. |
| `queue.connection` | `null` | Queue connection for background work; null uses the app default *(planned, M3)*. |
| `queue.queue` | `'default'` | Queue name for background work *(planned, M3)*. |
| `backup.pg_dump_path` | `'pg_dump'` | The `pg_dump` binary *(planned, M4)*. |
| `backup.disk` | `'local'` | Private disk for backups *(planned, M4)*. |
| `backup.keep` | `7` | Backups kept per connection label *(planned, M4)*. |
| `storage.connection` | `env('DBEXPLORER_STORAGE_CONNECTION')` | Connection for the `dbx_*` tables; null uses the app default. Recommended: a connection on the `dbexplorer` schema. |
| `storage.disk` | `'local'` | Private disk for exports and other generated files *(planned, M3)*. |
| `storage.file_ttl_hours` | `24` | Generated files are pruned after this many hours *(planned, M3)*. |
| `audit.retention_days` | `90` | `dbx:prune` deletes older audit rows. Null keeps them forever. |
| `audit.log_channel` | `null` | Log channel that also receives every audit entry and its hash. |
| `audit.key` | `env('DBEXPLORER_AUDIT_KEY')` | HMAC key for the audit chain; falls back to `APP_KEY`. |

Other publishable resources: `--tag=dbexplorer-migrations`, `dbexplorer-views` and `dbexplorer-lang` via `php artisan vendor:publish`.

## Commands

| Command | What it does |
|---|---|
| `dbx:install [--force]` | Publishes the config and prints the remaining setup steps. |
| `dbx:audit:verify` | Recomputes the audit hash chain; exits non-zero at the first edited, deleted or reordered row. |
| `dbx:prune` | Deletes audit rows older than `audit.retention_days`, after writing a checkpoint so the chain still verifies. |

## Roadmap

| Milestone | Scope | Status |
|---|---|---|
| M0 | Config, routing, gate, IP allowlist, roles, audit log, app shell, `dbx:install` | Built |
| M1 | Object browser, read-only data grid and SQL editor, EXPLAIN, hidden objects, masking, timeouts and row caps | Planned |
| M2 | Inline row editing, DML and DDL, password re-confirmation for destructive actions | Planned |
| M3 | Background queries, exports, CSV import, script runner, signed downloads | Planned |
| M4 | Saved and scheduled queries, backups, active sessions, audit log viewer | Planned |

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

DbExplorer is open-sourced software licensed under the [MIT license](LICENSE.md).
