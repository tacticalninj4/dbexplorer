# Release Notes

## [Unreleased](https://github.com/tacticalninj4/dbexplorer/compare/v1.0.2...main)

## [v1.0.2](https://github.com/tacticalninj4/dbexplorer/compare/v1.0.1...v1.0.2) - 2026-10-12

### Fixed

- Deleting or editing a row whose text has leading or trailing spaces, or is an empty string, no longer fails with a false "The row was changed or deleted since it was read" 409. Laravel's global `TrimStrings` and `ConvertEmptyStringsToNull` middleware rewrote the values the grid sent back; DbExplorer's API now opts out of both, so edits also save `' padded '` and `''` exactly instead of `'padded'` and `NULL`.

## [v1.0.1](https://github.com/tacticalninj4/dbexplorer/compare/v1.0.0...v1.0.1) - 2026-10-12

### Added

- A Laravel Boost skill, `dbexplorer-setup` (`resources/boost/skills/`), that guides an AI agent through installing, configuring, upgrading and troubleshooting DbExplorer. `php artisan boost:install` or `boost:update` picks it up.

## [v1.0.0](https://github.com/tacticalninj4/dbexplorer/commits/v1.0.0) - 2026-10-12

The first release: a PostgreSQL explorer, editor and automation package for Laravel 9–13 on PHP 8.0–8.5 and PostgreSQL 12+. No earlier version was tagged; the `v0.1.0` placeholder that used to sit here was never released and is folded into this entry.

Every feature below works through the JSON API and has a screen in the browser UI: browsing, the data grid with inline editing, the SQL editor, saved queries, schedules, backups, active sessions, the audit log viewer, export, run in background, CSV import, scripts, the Runs page, and forms for create table, view and materialized view, columns, indexes and constraints. Altering a column's type, default or nullability runs from the SQL editor or the API.

### Upgrade notes

Only relevant if you ran a pre-release checkout of `main`; a fresh install just runs `php artisan migrate`.

- **Run the migrations.** `2026_10_09_000006_add_sql_snapshot_to_dbx_schedules_table` gives schedules an approved SQL snapshot and **disables every existing schedule** until an admin re-syncs it (docs/security.md §6). `2026_10_09_000007_add_name_to_dbx_schedules_table` adds `dbx_schedules.name`, filled from each saved query's current name. If you published the migrations (`--tag=dbexplorer-migrations`), publish them again first.
- **API: `sql_hash` is required** on `POST api/{label}/schedules` and `POST api/{label}/schedules/{id}/resync`. Send the `sql_hash` the saved queries API returned with the SQL you reviewed; a missing or malformed hash is a 422, and a hash that no longer matches the saved query is a 409 with nothing approved.
- **API: 429 above the run cap.** A user may have `limits.background.max_active_per_user` (default **3**) runs queued or running per label; one more answers 429. A published config without the key gets the default; `0` turns the cap off.
- **API: 403 for a viewer's DML.** `POST api/{label}/query` and `POST api/{label}/queries/{id}/run` answer a viewer's `INSERT`, `UPDATE`, `DELETE`, `MERGE`, `CALL` or `SELECT … FOR UPDATE` with 403 instead of the 409 confirmation, also with `background: true`.
- **Editor tabs start empty once.** SQL editor and Scripts tabs are now stored per user, and tabs saved by earlier builds are dropped on the first load.

### Added

#### Explorer (M0–M1)
- Auth-gated, CSP-hardened SPA (assets served from the package, no Node on the host) with per-label connections resolved from an allowlist, the `viewDbExplorer` gate, an optional IP allowlist and `DbExplorer::roleUsing()` roles (`viewer`, `editor`, `admin`).
- Object browser over `pg_catalog`: tables, views, materialized views, partitions, sequences, functions, enums and extensions, with structure, indexes, constraints, triggers, generated DDL and stats tabs.
- Server-paged data grid (keyset when a key exists) with sorting, a filter builder (identifiers validated against introspected columns), type-aware cells, hidden objects (404, never 403, plus a best-effort EXPLAIN check on queries) and column masking that follows the data into exports.
- SQL editor (CodeMirror, schema-aware autocomplete) with EXPLAIN and EXPLAIN ANALYZE plan trees, notices, history, single-statement enforcement (S8), read-only transactions for viewers (S9), and per-role timeouts and row caps (S10).
- Append-only, HMAC hash-chained audit log (S17–S19, S22) with `dbx:audit:verify`, `dbx:prune` checkpoints, optional log-channel forwarding, and `dbx:install`.

#### Writes (M2)
- Inline row insert, edit and delete with optimistic concurrency (409 carries the current row), SQL preview before anything runs, and masked columns never editable. An insert needs at least one value, or `defaults: true` to insert a row of column defaults (422 otherwise). After a 409 the editor offers a field-level diff and "Overwrite with mine", which re-saves against the current row (still conflict-checked) and is marked `overwrite` in the audit log.
- DML and DDL in the editor behind a server-driven confirmation ladder (409 confirm, then 423 password re-confirmation for destructive SQL, S5). SQL the role can never run is refused up front with 403: DDL from viewers and editors, DML from viewers. The database grants, not the classifier, are the real boundary, proven by evasion tests. Users without a stored password (SSO-only) can't confirm, so can't run destructive actions.
- Schema forms with generated SQL and copy-as-migration. `CREATE INDEX CONCURRENTLY` and every `REFRESH MATERIALIZED VIEW` (plain or `CONCURRENTLY`) are queued as background runs; each DDL statement carries a `background` flag.

#### Background work (M3)
- Every long operation is a run: background queries, exports (CSV, JSON Lines or SQL INSERTs, masked, memory-flat through cursors), CSV imports (all-or-nothing, or skip-bad-rows with savepoint retry), multi-statement scripts (transaction or step mode, per-statement outcomes), and `pg_dump` backups.
- Cancel kills the PostgreSQL backend mid-statement, and stops a running `pg_dump`. Progress, signed short-lived downloads (S16), daily pruning (scheduled by the package) and queue-health warnings.
- A per-user, per-label cap on active runs, `limits.background.max_active_per_user` (default 3, 429 above it; scheduled runs don't count).
- `limits.background.timeout` is every run's job timeout and the statement timeout of background queries and exports. `limits.import.max_upload_kb` and `limits.import.batch` size CSV imports.

#### Automation and ops (M4)
- Saved queries with typed `:name` parameters (lexer-based rewriting, so literals never bind), private or shared per label. The saved queries API returns each query's `sql_hash`.
- Scheduled queries: one `->everyMinute()` tick registered by the package, cache-lock overlap protection, and mail notifications on success, failure or row thresholds. A schedule runs the snapshot of SQL (and name) an admin approved, as that admin, read-only unless approved with `allow_writes` (DML) or `allow_ddl` (DDL), each needing a password confirmation. `POST api/{label}/schedules/{id}/resync` re-approves the saved query's current SQL.
- Backups via `pg_dump` as an argument array with the password and SSL settings only in the environment (`PGPASSWORD`, `PGSSL*`, S20), a version preflight (`POST` answers 422 rather than queueing a dump that would fail), and retention. Restores deliberately stay a CLI job.
- Active sessions (cancel and terminate, same login only, audited) and the audit log viewer, scoped to labels where the user is admin.

### Security

Hardening from the pre-release security audits. Each one only affects pre-release builds of `main`.

- **Schedules run approved SQL only.** Scheduled queries used to re-read the saved query's live SQL, so a viewer could edit a shared query after an admin scheduled it and have it run as admin. A schedule now runs a snapshot taken at approval (migration 000006 disables existing schedules until re-synced).
- **An approval binds to the SQL the admin reviewed.** An edit to the saved query between loading the page and approving used to be approved instead. Create and re-sync now require the reviewed `sql_hash` and answer 409 when the saved query changed; the screens then show the current SQL to review again.
- **The host app's queue can't be flooded.** Any user could queue unlimited background runs. The run cap above stops that, and the README and docs/security.md recommend a dedicated queue (`queue.queue = 'dbexplorer'` with its own worker).
- **Schedule mail can't carry a user's link.** A saved query renamed to `[Reset password](https://…)` rendered as a real link. The name is now snapshotted at approval (migration 000007) and user-supplied text in the mail is Markdown-escaped.
- **SQL tabs don't outlive the user.** Editor and Scripts tabs were stored per label and survived the session, so the next user of a shared browser could read the SQL, literals included. They're now stored per user under an opaque `user.storageKey`, cleared when a 401/419 sends the user back to sign in, and other users' tabs are removed on load, so a normal host logout leaves nothing behind either.
- **`pg_dump` can't reach the wrong server.** A `port` (or host or username) configured as an integer was dropped, so `pg_dump` connected to 5432, possibly a different server, with the connection's password.
- **The audit log viewer is scoped per label.** It showed rows from every label; it now shows only rows from labels where the user is admin.
- **Table exports quote their identifiers** and resolve the table before exporting (S12).

### Changed
- A viewer's DML in the SQL editor or a saved query is refused with 403 "Viewers can't change data" before any confirmation, instead of asking to confirm a write the database then refused. SQL the classifier can't place still asks to confirm, and the classifier stays UX only: a viewer's side-effecting function is stopped by the read-only transaction and the grants (docs/security.md §4).
- The Saved queries screen titles a refusal "Not allowed" and the run cap "Limit reached", like the SQL editor and Scripts screens.

### Fixed
- Cancel stops background queries and exports mid-statement, not only between chunks.
- Cancel stops a running backup: `pg_dump` is stopped and its partial file deleted, and the run ends cancelled. Before, the dump ran on and could end "succeeded".
- A scheduled query's lock is released when its run ends, so a failed schedule runs again on the next due tick.
- Materialized view refreshes no longer run inside the HTTP request.
- The Scripts screen shows each statement's own text when several statements share a line.
- CSV uploads that libmagic reports as `application/csv` or `text/x-csv` are accepted, including on PHP 8.0.
- A backup whose preflight fails is refused with the real cause instead of being queued to fail.
- `pg_dump` connects with the connection's SSL settings.
- An import's run reports `inserted`, `skipped` and `errors` (row number and database message, never cell values).
- A user without a stored password gets a plain refusal on password confirmation instead of a 500 (S5).
- `dbx:prune` is scheduled once.

### Known limitations
- API gaps the screens work around, planned for v1.1: exports don't apply the grid's filters or sort, backups have no format or schema/table options and no Cancel of their own (cancel them from the Runs page), schedules have no server-computed next run, and the audit API doesn't return the chain hashes.
- Documented gaps, pinned by `GAP:` tests (docs/security.md §4): a function reading a hidden table isn't caught, an aliased masked column isn't masked (use a column-level `REVOKE`), and deleting the newest audit rows together with `dbx_audit_head` goes undetected without log forwarding.

### Tests and CI
- CI covers Laravel 9–13 on PHP 8.0–8.5 with Pest 1–5, PostgreSQL 12 and 18, Windows, and a committed-`dist/` check.
- One 512 MB memory limit for serial and parallel test runs; `intl` is a dev requirement for the arch tests.
- Tests include cell values as plain JSON strings (S15), a backup restored with `pg_restore` into a fresh database, the package's own scheduler entries, and README tables checked against the config file and commands.
