DbExplorer mounts a secure, browser-based PostgreSQL explorer inside your Laravel app. It reuses the app's auth, database connections, queue and scheduler. This is the first release.

**Requirements:** PHP 8.0–8.5, Laravel 9–13, PostgreSQL 12+ with `pdo_pgsql`, a queue worker, Laravel's scheduler, and `pg_dump` for backups.

## Highlights
- **Explore:** an object browser over `pg_catalog`, a server-paged data grid with keyset paging, sorting and filters, and a SQL editor with autocomplete, EXPLAIN plan trees, notices and history.
- **Write safely:** inline row editing with optimistic concurrency and "Overwrite with mine", DML and DDL behind a confirm → password ladder, and schema forms with SQL preview and copy-as-migration.
- **Run in the background:** background queries, CSV/JSON Lines/SQL exports, CSV imports, multi-statement scripts and `pg_dump` backups, with progress, cancel that stops the database backend, and signed downloads.
- **Automate:** saved queries with typed parameters, and scheduled queries that run the exact SQL an admin approved, with mail notifications.
- **Operate:** active sessions (cancel and terminate), and an audit log viewer.

## Security model
- Off by default and deny by default: no routes until `DBEXPLORER_ENABLED=true`, and nobody gets in outside `local` until you define the `viewDbExplorer` gate.
- The database is the boundary. Each role (`viewer`, `editor`, `admin`) uses its own PostgreSQL login; viewers run in read-only transactions. The [role recipe](https://github.com/tacticalninj4/dbexplorer/blob/v1.0.0/docs/security.md#3-postgresql-roles) sets up the logins.
- Hidden objects, column masking, per-role timeouts and row caps, a per-user cap on active background runs, and an HMAC hash-chained audit log checked by `dbx:audit:verify`.

## Install
```bash
composer require tacticalninj4/dbexplorer:^1.0
php artisan dbx:install
php artisan migrate
```
`dbx:install` prints the remaining steps. We recommend a dedicated queue (`queue.queue = 'dbexplorer'`, with `php artisan queue:work --queue=dbexplorer`). See the [README](https://github.com/tacticalninj4/dbexplorer/blob/v1.0.0/README.md).

## Upgrading from a pre-release checkout of `main`
Run `php artisan migrate`. Migration `000006` disables existing schedules until an admin re-syncs them, and `000007` snapshots schedule names. Creating or re-syncing a schedule through the API now requires `sql_hash`. Too many active runs get a 429 (`limits.background.max_active_per_user`, default 3). A viewer's DML gets a 403. The [CHANGELOG](https://github.com/tacticalninj4/dbexplorer/blob/v1.0.0/CHANGELOG.md) has the details.

## Known limitations
Exports don't apply the grid's filters, backups have no format options, and a few other API gaps are planned for v1.1. The documented security gaps (a function reading a hidden table, aliased masked columns) are in [docs/security.md §4](https://github.com/tacticalninj4/dbexplorer/blob/v1.0.0/docs/security.md#4-what-the-app-level-checks-do-not-guarantee).

**Full changelog:** [CHANGELOG.md](https://github.com/tacticalninj4/dbexplorer/blob/v1.0.0/CHANGELOG.md)
