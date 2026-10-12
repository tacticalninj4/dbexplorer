# Release Notes

## [Unreleased](https://github.com/tacticalninj4/dbexplorer/commits/main)

The first full release, to be tagged 1.0.0 once verification passes: a PostgreSQL explorer, editor and automation package for Laravel 9–13 on PHP 8.0–8.5.

Every feature below works through the JSON API and has a screen in the browser UI: saved queries, schedules (approve, resync, `allow_writes`/`allow_ddl`), backups, active sessions, the audit log viewer, export, run in background, CSV import, scripts, the Runs page, create table/view/materialized view, constraint forms and the row-conflict "Overwrite with mine". Altering a column's type, default or nullability runs from the SQL editor or the API.

Known API gaps the screens work around, planned for v1.1: exports don't apply the grid's filters or sort, backups have no format or schema/table options, schedules have no server-computed next run, and the audit API doesn't return the chain hashes.

### Explorer (M0–M1)
- Auth-gated, CSP-hardened SPA (assets served from the package, no Node on the host) with per-label connections resolved from an allowlist.
- Object browser over `pg_catalog`: tables, views, materialized views, partitions, sequences, functions, enums and extensions, with structure, indexes, constraints, triggers, generated DDL and stats tabs.
- Server-paged data grid (keyset when a key exists) with sorting, a filter builder (identifiers validated against introspected columns), type-aware cells, hidden objects (404, never 403, plus a best-effort EXPLAIN check on queries) and column masking that follows the data into exports.
- SQL editor (CodeMirror, schema-aware autocomplete) with EXPLAIN/EXPLAIN plan trees, notices, history, single-statement enforcement (S8), read-only transactions for viewers (S9), per-role timeouts and row caps (S10).
- Append-only, HMAC hash-chained audit log (S17–S19, S22) with `dbx:audit:verify`, `dbx:prune` checkpoints and `dbx:install`.

### Writes (M2)
- Inline row insert/edit/delete with optimistic concurrency (409 carries the current row), SQL preview before anything runs, and masked columns never editable. An insert needs at least one value, or `defaults: true` to insert a row of column defaults (422 otherwise). After a 409 the editor offers a field-level diff and "Overwrite with mine", which re-saves against the current row (still conflict-checked) and is marked `overwrite` in the audit log.
- DML and DDL in the editor behind a server-driven confirmation ladder (409 confirm → 423 password re-confirmation, S5), with the database grants, not the classifier, as the real boundary (proven by evasion tests). Users without a stored password (SSO-only) can't confirm, so can't run destructive actions.
- Schema forms with generated SQL, copy-as-migration, and `CREATE INDEX CONCURRENTLY` and every `REFRESH MATERIALIZED VIEW` (plain or `CONCURRENTLY`) queued as a background run; each DDL statement carries a `background` flag.

### Background work (M3)
- Every long operation is a run: background queries, exports (CSV/JSONL/SQL INSERTs, masked, memory-flat through cursors), CSV imports (all-or-nothing or skip-bad-rows with savepoint retry), multi-statement scripts (transaction or step mode, per-statement outcomes), and `pg_dump` backups.
- Cancel kills the Postgres backend mid-statement; progress, signed short-lived downloads (S16), daily pruning (scheduled by the package) and queue-health warnings.
- `limits.background.timeout` is every run's job timeout and the statement timeout of background queries and exports. `limits.import.max_upload_kb` and `limits.import.batch` size CSV imports.

### Automation & ops (M4)
- Saved queries with typed `:name` parameters (lexer-based rewriting, so literals never bind), private or shared per label.
- Scheduled queries: one `->everyMinute()` tick registered by the package, cache-lock overlap protection, and mail notifications on success, failure or row thresholds. A schedule runs an approved snapshot of its SQL as the approving admin, read-only unless approved with `allow_writes` (DML) or `allow_ddl` (DDL), each needing a password confirmation; `POST api/{label}/schedules/{id}/resync` re-approves the saved query's current SQL.
- Backups via pg_dump as an argument array with the password and SSL settings only in the environment (`PGPASSWORD`, `PGSSL*`, S20), a version preflight (`POST` answers 422 rather than queueing a dump that would fail), and retention; restores deliberately stay a CLI job.
- Active sessions (cancel/terminate, same-login only, audited) and the audit log viewer, scoped to labels where the user is admin.

### Security
Fixed before release, after an independent audit:
- Scheduled queries re-read the saved query's live SQL, so a viewer could edit a shared query after an admin scheduled it and have it run as admin. Schedules now snapshot the SQL at approval (above). **Upgrading an earlier install:** the migration disables existing schedules until an admin re-syncs them (docs/security.md §6).
- A schedule's approval wasn't tied to the SQL the admin reviewed: an edit to the saved query between loading the page and approving got approved instead. `POST api/{label}/schedules` and `…/resync` now require the reviewed `sql_hash` (the saved queries API returns it) and answer **409** without approving anything when the saved query changed since; a missing or malformed hash is a 422. **API change** for scripted clients.
- The audit log viewer showed rows from every label; it now shows only rows from labels where the user is admin.
- Table exports quote their identifiers and resolve the table before exporting (S12).
- A user without a stored password got a 500 on password confirmation; it's now a plain refusal (S5).

### Fixed
- Cancel stops background queries and exports mid-statement, not only between chunks.
- A scheduled query's lock is released when its run ends, so a failed schedule runs again on the next due tick.
- CSV uploads that libmagic reports as `application/csv` or `text/x-csv` are accepted.
- A backup whose preflight fails is refused with the real cause instead of being queued to fail.
- `pg_dump` connects with the connection's SSL settings.
- An import's run reports `inserted`, `skipped` and `errors` (row number and database message, never cell values).

### Tests and CI
- One 512 MB memory limit for serial and parallel test runs; `intl` is a dev requirement for the arch tests.
- New tests: cell values as plain JSON strings (S15), a backup restored with `pg_restore` into a fresh database, the package's own scheduler entries, and README tables checked against the config file and commands.

## [v0.1.0](https://github.com/tacticalninj4/dbexplorer/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
