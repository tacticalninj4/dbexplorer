# Release Notes

## [Unreleased](https://github.com/tacticalninj4/dbexplorer/compare/v1.0.0...1.x)

### Security
- Scheduled queries run an approved snapshot of their SQL, as the approving admin, in a read-only transaction unless the approval allowed writes (`allow_writes`) or DDL (`allow_ddl`, both needing a password confirmation). Editing a shared saved query after it was scheduled no longer changes what runs as admin. New `POST api/{label}/schedules/{id}/resync`. **Upgrading:** the migration disables existing schedules until an admin re-syncs them (docs/security.md §6).
- The audit log viewer shows only rows from labels where the user is admin.

### Fixed
- A scheduled query's lock is released when its run ends, so it runs again on the next due tick (it waited for the background timeout before).


## [v1.0.0](https://github.com/tacticalninj4/dbexplorer/compare/...v1.0.0) - 2026-10-09

First full release: a Postgres explorer, editor and automation package for Laravel 9–13 (PHP 8.0–8.4).

### Explorer (M0–M1)
- Auth-gated, CSP-hardened SPA (assets served from the package, no Node on the host) with per-label connections resolved from an allowlist.
- Object browser over `pg_catalog`: tables, views, materialized views, partitions, sequences, functions, enums and extensions, with structure, indexes, constraints, triggers, generated DDL and stats tabs.
- Server-paged data grid (keyset when a key exists) with sorting, a filter builder (identifiers validated against introspected columns), type-aware cells, hidden objects (404, never 403, plus a best-effort EXPLAIN check on queries) and column masking that follows the data into exports.
- SQL editor (CodeMirror, schema-aware autocomplete) with EXPLAIN/EXPLAIN plan trees, notices, history, single-statement enforcement (S8), read-only transactions for viewers (S9), per-role timeouts and row caps (S10).
- Append-only, HMAC hash-chained audit log (S17–S19, S22) with `dbx:audit:verify`, `dbx:prune` checkpoints and `dbx:install`.

### Writes (M2)
- Inline row insert/edit/delete with optimistic concurrency (409 carries the current row), SQL preview before anything runs, and masked columns never editable.
- DML and DDL in the editor behind a server-driven confirmation ladder (409 confirm → 423 password re-confirmation, S5) — with the database grants, not the classifier, as the real boundary (proven by evasion tests).
- Schema forms with generated SQL, copy-as-migration, and `CREATE INDEX CONCURRENTLY` queued as a background run.

### Background work (M3)
- Every long operation is a run: background queries, exports (CSV/JSONL/SQL INSERTs, masked, memory-flat through cursors), CSV imports (all-or-nothing or skip-bad-rows with savepoint retry), multi-statement scripts (transaction or step mode, per-statement outcomes), and `pg_dump` backups — with cancel that kills the Postgres backend, progress, signed short-lived downloads (S16), daily pruning and queue-health warnings.

### Automation & ops (M4)
- Saved queries with typed `:name` parameters (lexer-based rewriting, so literals never bind), private or shared per label.
- Scheduled queries: one `->everyMinute()` tick, cache-lock overlap protection, creator-role re-checks, and mail notifications on success, failure or row thresholds.
- Backups via pg_dump as an argument array with `PGPASSWORD` only in the environment (S20), version preflight, retention; restores deliberately stay a CLI job.
- Active sessions (cancel/terminate, same-login only, audited) and the admin audit log viewer.

## [v0.1.0](https://github.com/tacticalninj4/dbexplorer/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
