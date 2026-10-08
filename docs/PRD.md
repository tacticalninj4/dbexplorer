# DbExplorer — Product Requirements Document

| | |
|---|---|
| Package | `tacticalninj4/dbexplorer` |
| Status | Draft v0.1 |
| Last updated | 2026-10-09 |
| Owner | Jhanarthananraja |

---

## 1. Summary

DbExplorer is a Laravel package that mounts a secure, browser-based database explorer inside an existing Laravel app. It aims to fully replace phpMyAdmin/pgAdmin for teams that already run Laravel. It reuses the app's auth, connections, queue and scheduler, so nothing extra has to be deployed.

v1 targets **PostgreSQL**. MySQL comes in v2, behind a driver seam that is built in v1.

Security is the primary design constraint. When convenience and safety conflict, safety wins and the user can opt in to the riskier behavior through config.

## 2. Problem

- phpMyAdmin and pgAdmin are separate deployments. Each has its own auth, patching and network exposure, and they are often left reachable with weak credentials.
- Teams end up sharing raw database credentials so people can run "just one query", and nobody knows who ran what.
- Long operations like exports, imports, big scripts and backups run inside an HTTP request. They time out and leave work half-done.
- Repetitive SQL chores (reports, cleanups, dumps) are done by hand or through ad-hoc cron scripts that nobody owns.

## 3. Goals

1. Browse every Postgres object in an allowlisted Laravel connection: schemas, tables, views, materialized views, sequences, functions, types, indexes, constraints, triggers and extensions.
2. Write and run SQL, with permissions enforced by role and a full audit trail.
3. Edit data and schema through the UI (rows and DDL), with safety rails.
4. Move every long-running operation to queued jobs, with progress tracking, cancellation and result retention.
5. Automate SQL with saved queries, scheduled queries, multi-statement scripts and `pg_dump` backups.
6. Be safe to enable in production: off by default, deny by default, read-only by default, and everything audited.

## 4. Non-goals (v1)

- MySQL/MariaDB support (planned for v2).
- Connecting with credentials typed into the UI. Only connections the app already configures and the package allowlists can be used.
- Server administration: managing Postgres roles and grants, editing `postgresql.conf`, replication.
- A standalone user system. The package uses the host app's auth.
- Real-time collaborative editing, ER diagram editor, and AI query generation. These may come later.
- Restoring backups through the UI. Restore is too destructive for v1, so it stays documented as a CLI step.

## 5. Users and roles

The host app's auth guard identifies the user. A `viewDbExplorer` gate decides whether they can enter at all. A role resolver then maps the user to a package role.

| Capability | viewer | editor | admin |
|---|:-:|:-:|:-:|
| Browse objects, structure and data | ✓ | ✓ | ✓ |
| Run read-only SQL (SELECT, EXPLAIN) | ✓ | ✓ | ✓ |
| Export (queued) | ✓ | ✓ | ✓ |
| Saved queries (own) | ✓ | ✓ | ✓ |
| Inline row insert/update/delete | | ✓ | ✓ |
| Run write SQL (DML) | | ✓ | ✓ |
| CSV import (queued) | | ✓ | ✓ |
| Script runner | | ✓ | ✓ |
| DDL via UI or SQL (CREATE/ALTER/DROP) | | | ✓ |
| Scheduled queries, backups | | | ✓ |
| Active sessions: view, cancel, terminate | | | ✓ |
| View the full audit log | | | ✓ |

- Roles can be set per connection. For example, a user can be `admin` on `staging` and `viewer` on `pgsql`.
- Apps resolve roles with a callback: `DbExplorer::roleUsing(fn ($user, string $connection) => 'viewer')`. If no callback is registered, every user who passes the gate gets `viewer`.
- **Defense in depth:** each role can map to a *different Laravel connection*, which in turn uses a different Postgres login. For example, `viewer` maps to `pgsql_readonly`, whose database user has only `SELECT` privileges. The docs must strongly recommend this setup. App-level checks are the second line of defense, never the only one.

## 6. Security requirements

These requirements are non-negotiable, and each needs automated tests.

### 6.1 Access
- **S1** The package is disabled unless `DBEXPLORER_ENABLED=true`. When disabled, routes are not registered at all, so they return 404 rather than 403.
- **S2** In non-local environments, the `viewDbExplorer` gate denies everyone by default, the same way Telescope does.
- **S3** The route prefix, domain and middleware stack are configurable. The defaults are `web`, `auth` and the gate.
- **S4** An optional IP/CIDR allowlist middleware.
- **S5** Destructive actions require Laravel's `password.confirm`, with a configurable timeout. Destructive means DROP, TRUNCATE, DELETE or UPDATE without a WHERE clause, deleting an inline row, or ALTER ... DROP.
- **S6** Rate limiting on query execution, with a named limiter.

### 6.2 Connection and query safety
- **S7** Only connections listed in `dbexplorer.connections` are usable. Connection config such as host, user or password is never sent to the frontend.
- **S8** Interactive queries run as a **single statement** through a native (non-emulated) PDO prepare. Postgres rejects multiple commands in a prepared statement, which closes off the `COMMIT; DROP ...` escape. Multi-statement SQL only runs through the Script Runner (§7.6), which needs editor rights or higher.
- **S9** Viewer queries run inside `BEGIN READ ONLY`. A role's DB-level privileges (§5) are the main guarantee, and this is the backup.
- **S10** Every query gets `SET LOCAL statement_timeout` and a role-based row cap, with defaults of 30s and 1,000 rows. Background jobs have their own, larger limits.
- **S11** The SQL classifier (read, DML, DDL or destructive) is only for **UX and confirmation prompts**. It is never a security boundary. Enforcement comes from database privileges and transaction mode.
- **S12** Identifiers are always quoted through the grammar. Values are always bound. Grid filters and sorting never put user input into SQL text directly.

### 6.3 Data exposure
- **S13** Hidden schemas and tables are configured by glob patterns, such as `pg_toast`, `*.password_resets` and `public.personal_access_tokens`. Hidden objects are left out of browsing. Queries that reference them are rejected on a best-effort basis, so the docs must say that DB grants are the real enforcement.
- **S14** Column masking rules, such as `*.password`, `*.*token*`, `users.ssn` and custom closures, apply to grids, inline edit views and exports. Only admins can unmask columns, and every unmask is audited.
- **S15** Cell values are rendered as text and never as HTML. The SPA ships a strict CSP header.
- **S16** Exports and backups are written to a **private** filesystem disk. Downloads use short-lived signed URLs bound to the user who requested them. Files expire and are pruned after a configurable TTL, 24h by default.

### 6.4 Audit
- **S17** Every action that touches data or schema is written to `dbx_audit_log`: user, role, connection, IP, user agent, SQL text (masked bound values), classification, duration, row count, outcome and error. This covers queries, edits, DDL, exports, imports, scripts, schedules, backups, unmasking and session kills.
- **S18** The audit log is append-only from the package. There are no update or delete routes. Pruning happens only through `dbx:prune` with a configurable retention period, 90 days by default.
- **S19** Optional: forward audit events to a Laravel log channel so they reach a SIEM.

### 6.5 Process safety
- **S20** `pg_dump` runs through Symfony `Process` with an argument array, never a shell string. The password goes in `PGPASSWORD` in the process environment and never in the arguments.
- **S21** Job payloads carry IDs, never raw credentials.

## 7. Features

### 7.1 Object browser (M1)
- A connection switcher that shows only allowlisted connections.
- A tree of schemas, then tables, views, materialized views, sequences, functions/procedures, types/enums, and extensions.
- **Table detail tabs:** Data, Structure (columns, types, defaults, nullability, comments), Indexes, Constraints and FKs (with clickable navigation), Triggers, DDL (generated `CREATE`), and Stats (size, estimated rows from `pg_class.reltuples`, last vacuum/analyze, seq/idx scans).
- **Views and materialized views:** the definition, plus data. Admins can refresh materialized views.
- **Functions:** signature and source. Read-only in v1.
- Search across object names.

### 7.2 Data grid (M1 read, M2 write)
- Server-side pagination. Keyset pagination when a PK or unique key exists, offset pagination otherwise. Row counts are estimated by default, with an exact count on demand.
- Sort by column. Filter builder with column, operator and value, all bound as parameters. Show and hide columns.
- Type-aware rendering: json/jsonb pretty view, arrays, bytea shown as hex preview or size, timestamps with time zone, long text truncated with expand.
- **M2:** inline edit, insert and delete. Only available when the table has a PK. Each change runs in its own transaction with an optimistic check against the original row values, and a conflict is reported back. A diff preview of the generated SQL appears before commit.

### 7.3 SQL editor (M1 read, M2 write)
- CodeMirror 6 with Postgres dialect, autocomplete for schemas, tables and columns, formatting, and multiple tabs.
- Results grid, row count, duration, and NOTICE messages.
- `EXPLAIN` and `EXPLAIN ANALYZE`, with the plan shown as a tree. `ANALYZE` runs inside a transaction that is rolled back.
- Query history per user, stored server-side with masked parameters.
- If the classifier sees DML, DDL or destructive SQL, the user gets a confirmation dialog that shows the classification, plus password re-confirm (S5) where required.
- Queries that are likely to be slow or large can be sent to the background with "Run in background" (§7.5).

### 7.4 Schema editing (M2)
- Forms for create, alter and drop on tables, columns, indexes, constraints and views.
- Every form produces SQL that is **shown before it runs**. The user can copy it, for example into a Laravel migration, instead of executing it.
- DDL runs in a transaction, since Postgres supports transactional DDL. `CREATE INDEX CONCURRENTLY` is offered as an option and runs as a background job, because it cannot run inside a transaction.

### 7.5 Background jobs (M3)
Every long operation becomes a **Run**, a row in `dbx_runs` with a type, status (`queued`, `running`, `succeeded`, `failed`, `cancelled`), progress, timestamps, user, result file and error.

- Dispatched to a configurable queue connection and queue name. If the queue driver is `sync`, the UI shows a warning that background features will block the request.
- The UI polls for progress. Websockets are not required.
- **Cancel:** the job stores its `pg_backend_pid()`. Cancelling calls `pg_cancel_backend(pid)` and marks the run as cancelled.
- A Runs page lists everything: users see their own runs and admins see all runs.
- Run types: `query` (background SELECT, results to a file), `export`, `import`, `script`, `backup`, `scheduled_query`.

### 7.6 Import / export (M3)
- **Export:** a table, view or query result to CSV, JSON lines or SQL INSERTs. Rows are streamed in chunks from a server-side cursor, so memory stays flat. Masking rules (S14) apply.
- **Import:** CSV into an existing table. The user uploads to the private disk, maps columns, and previews the first N rows. The job loads data with `COPY ... FROM STDIN` through PDO pgsql, in a transaction mode chosen by the user: all-or-nothing, or batches that skip bad rows and report them.

### 7.7 Script runner (M3)
- Multi-statement SQL scripts run as a Run.
- Two modes: **single transaction**, where any error rolls everything back, and **step**, where each statement commits on its own and the run stops on the first error.
- Shows per-statement status, duration and rows affected. Cancel works between statements and also in the middle of a statement through `pg_cancel_backend`.
- Admin is required if the classifier finds DDL in the script.

### 7.8 Saved queries (M4)
- Named SQL with typed `:parameters` (string, int, date, enum list), plus a description and tags.
- Visibility is private or shared. Shared queries can be run by anyone whose role allows the query's classification.
- Run in the editor, run in the background, or export results.

### 7.9 Scheduled queries (M4, admin)
- A saved query plus a cron expression, using `dragonmantank/cron-expression`, which Laravel already ships.
- The package registers one scheduler entry, `dbx:schedule:tick`, which runs every minute and dispatches the schedules that are due. The host only needs the standard `schedule:run` cron.
- Overlap protection per schedule through cache locks.
- Results are kept as Runs. Optional notifications go out by mail, Slack or any Laravel notification channel on success, failure or a row-count threshold, for example "alert if > 0 rows".

### 7.10 Backups (M4, admin)
- An on-demand or scheduled `pg_dump` of a connection, in custom or plain format, with an optional schema or table selection. Output goes to a configured disk.
- On boot, the package checks for the `pg_dump` binary and its version, and the UI reports any problem.
- Retention: keep the last N backups per connection.
- Restore is documented as a CLI step and is not available in the UI (see Non-goals).

### 7.11 Active sessions (M4, admin)
- A view of `pg_stat_activity` for the connection's database, showing query, state, duration and wait events.
- Cancel (`pg_cancel_backend`) and terminate (`pg_terminate_backend`). Both are audited and terminate needs password re-confirm.

## 8. Architecture

```
Host Laravel app
 └─ DbExplorerServiceProvider
     ├─ routes (prefix, middleware, gate) ── JSON API  /dbexplorer/api/*
     ├─ SPA shell (Blade)  ──────────────── prebuilt Vue 3 assets (published)
     ├─ Driver seam: Introspector + Dialect   (PostgresDriver in v1; MySqlDriver in v2)
     ├─ QueryRunner (single statement, read-only tx, timeout, row cap)
     ├─ Classifier (UX only)   Masking   HiddenObjects
     ├─ Jobs: RunQuery, Export, Import, RunScript, Backup  → dbx_runs
     ├─ Console: dbx:schedule:tick, dbx:prune, dbx:install
     └─ Metadata store (configurable connection): dbx_audit_log, dbx_runs,
        dbx_saved_queries, dbx_schedules, dbx_query_history
```

- **Frontend:** a Vue 3 SPA that is built inside the package (Vite) and committed as compiled assets in `dist/`. Hosts publish it with `php artisan vendor:publish --tag=dbexplorer-assets`, or the package serves it from a route. The host never needs Node.
- **API:** JSON endpoints under the route prefix that use the host's session auth and CSRF. There is no separate token system.
- **Driver seam:** `Introspector` (lists objects and describes them) and `Dialect` (pagination, quoting, DDL generation, cancel, timeout). These are the only places with Postgres-specific SQL, which keeps v2 MySQL support contained.
- **Metadata tables:** all prefixed `dbx_`, created by publishable migrations on `dbexplorer.storage.connection`, which defaults to the app's default connection. Pointing it at a separate database keeps the audit log intact even if the explored database is damaged.

## 9. Compatibility

| | Supported |
|---|---|
| Laravel | 9, 10, 11, 12, 13 |
| PHP | 8.0 – 8.4 (per Laravel version's own range) |
| PostgreSQL | 12+ (v1) |
| MySQL | 8.0+ / MariaDB 10.6+ (v2) |
| Browsers | Last 2 versions of evergreen browsers |

Implications:
- Package code must be **PHP 8.0-compatible**. That rules out enums, readonly properties, `never`, first-class callable syntax and `new` in initializers, and string-backed constants stand in for enums.
- `composer.json` has to widen to `php: ^8.0` and `illuminate/*: ^9.0|...|^13.0`. Dev dependencies need version ranges per Laravel version, and the CI matrix pins Testbench and Pest for each Laravel version. The current scaffold (PHP ^8.3, Pest 4, Testbench 10/11) has to change before M1.
- Only use framework APIs that exist in Laravel 9, or wrap them in a version check: `password.confirm`, `RateLimiter`, `Schedule`, `LazyCollection` cursors and `Process` from Symfony are all fine.
- Laravel 9 and PHP 8.0 no longer get security fixes upstream. The README will say that support for them is best-effort.

## 10. Configuration (sketch)

```php
return [
    'enabled' => env('DBEXPLORER_ENABLED', false),
    'path' => 'dbexplorer',
    'domain' => null,
    'middleware' => ['web', 'auth'],
    'ip_allowlist' => [],

    'connections' => [
        // explorer label => role => laravel connection name
        'main' => [
            'viewer' => 'pgsql_readonly',
            'editor' => 'pgsql_editor',
            'admin'  => 'pgsql',
        ],
    ],

    'limits' => [
        'viewer' => ['timeout' => 30, 'rows' => 1000],
        'editor' => ['timeout' => 60, 'rows' => 5000],
        'admin'  => ['timeout' => 120, 'rows' => 10000],
        'background' => ['timeout' => 3600],
    ],

    'hidden' => ['pg_toast*', '*.password_reset*', '*.personal_access_tokens'],
    'masking' => ['*.password', '*.*token*', '*.*secret*'],

    'storage' => ['connection' => null, 'disk' => 'local', 'file_ttl_hours' => 24],
    'queue' => ['connection' => null, 'queue' => 'default'],
    'audit' => ['retention_days' => 90, 'log_channel' => null],
    'backup' => ['pg_dump_path' => 'pg_dump', 'disk' => 'local', 'keep' => 7],
];
```

## 11. Milestones

Each milestone can be released on its own as a tagged pre-release.

| Milestone | Scope | Exit criteria |
|---|---|---|
| **M0 — Foundation** | Widen composer and the CI matrix to L9–13 / PHP 8.0+, config, service provider wiring, gate, role resolver, metadata migrations, audit logger, SPA shell and build pipeline | CI is green on every matrix cell. S1–S4, S7 and S17 are tested. |
| **M1 — Read-only explorer** | Object browser, read-only data grid, read-only SQL editor, EXPLAIN, query history, hidden objects, masking, timeouts and row caps | A viewer can explore a real database with the DB-level read-only user. Security tests S8–S15 pass. |
| **M2 — Writes** | Inline row edit, DML in the editor, DDL forms with SQL preview, destructive confirmation and password re-confirm | Every write is audited. Optimistic conflict detection is tested. S5 and S6 are tested. |
| **M3 — Background work** | Runs infrastructure, cancel, background queries, export, import, script runner, signed downloads, pruning | Exporting 10M rows keeps memory flat. Cancel works mid-query. S16, S20 and S21 are tested. |
| **M4 — Automation & ops** | Saved queries, scheduled queries with notifications, backups, active sessions | Schedules survive overlap and failure. A backup round-trips with a CLI restore. |
| **v2** | MySQL/MariaDB driver | Feature parity minus Postgres-only objects. |

## 12. Success metrics

- Installed and usable in **≤ 5 minutes**: `composer require`, `dbx:install`, enable, define the gate.
- Browsing a page of a 10M-row table in **< 500 ms**, with a PK and keyset pagination.
- **Zero** known authz bypasses. Every S-requirement has at least one test, and CI runs those tests against a real Postgres service.
- Long operations never run inside an HTTP request: export, import, script, backup and any query run in the background.

## 13. Risks

| Risk | Mitigation |
|---|---|
| App-level read-only can be bypassed by a crafted statement or function call | DB-level role separation (§5) is the primary control. The docs and `dbx:install` push for it, and the UI shows a warning when viewer and admin map to the same connection. |
| The L9–13 / PHP 8.0–8.4 matrix slows development | Keep framework usage minimal and run the full matrix in CI. Revisit dropping L9 in 2027. |
| Prebuilt assets get out of date with the source | CI rebuilds and fails if `dist/` differs from the build output. |
| A queue that isn't running makes background features look broken | Show a heartbeat check in the UI ("no worker has picked up a job in N min"). |
| Masking misses a sensitive column | The default rules are conservative, the docs tell users to audit their schema, and admins see a "columns not masked" report. |

## 14. Open questions

1. Should the Vue SPA be served straight from the vendor directory through a route, Horizon-style, or require `vendor:publish`? Publishing means a stale-asset risk after upgrades.
2. Should audit log rows be hash-chained so tampering can be detected, or is append-only plus SIEM forwarding enough?
3. Should viewers be allowed to export tables that have masked columns, or only admins?
4. Should saved and scheduled queries be scoped per team or tenant for multi-tenant host apps?
5. Should the package expose events such as `QueryExecuted` and `RunFinished` so hosts can hook in?
