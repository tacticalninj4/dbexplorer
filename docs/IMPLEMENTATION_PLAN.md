# DbExplorer — Implementation Plan

| | |
|---|---|
| Implements | [PRD.md](PRD.md) |
| Status | Complete: M0–M4 implemented through the API and the browser UI, released as v1.0.0 |
| Last updated | 2026-10-12 |

Each task below is meant to be **one PR**. Tasks inside a milestone are ordered and usually depend on the ones before them. A milestone is done when its exit criteria pass in CI. The milestones weren't tagged on their own: v1.0.0, tagged per the `package-release` skill, is the first release.

`S#` refers to the security requirements in PRD §6. Every `S#` gets at least one test, and that test is named after it, e.g. `it('S8 rejects multiple statements')`, so `pest --filter=S8` finds it.

---

## 0. Decisions needed before starting

| # | Decision | Recommended default |
|---|---|---|
| D1 | The scaffold's PHP namespace didn't match the package name `tacticalninj4/dbexplorer`. | **Decided:** rename to `TacticalNinj4\DbExplorer` in M0.1. |
| D2 | PRD Q1: how assets are served. | **Decided:** serve `dist/` from the vendor directory through a package route, with hashed filenames and immutable cache headers. No publish step. |
| D3 | PRD Q2: hash-chain the audit log. | **Decided: yes.** HMAC chain plus `dbx:audit:verify` (M0.6, PRD S22). |
| D4 | PRD Q3: can viewers export masked columns? | **Decided: yes**, with masking applied in the file. |
| D5 | PRD Q4: tenant/team scoping. | **Decided: none in v1.** Saved queries belong to `user_id`. |
| D6 | PRD Q5: Laravel events. | **Decided: yes**, five core events (see §3a). |

All decisions were resolved on 2026-10-09.

---

## 1. Engineering ground rules

**PHP 8.0 source compatibility.** Code in `src/`, `config/`, `database/` and `routes/` must run on PHP 8.0, which rules out:
- `enum` (use `final class RunStatus { public const QUEUED = 'queued'; … }` instead)
- `readonly` properties and classes, `never`, first-class callables `foo(...)`, `new` in initializers
- pure intersection types, `array_is_list()`, and constants in traits

Enforcement: in `phpstan.neon.dist`, set `phpVersion: 80000`. PHPStan then reports newer syntax and functions while running on a modern PHP lane.

**Laravel 9 API floor.** Only use framework APIs that exist in Laravel 9. Known traps in the current scaffold and the plan:

| API | Status | Use instead |
|---|---|---|
| `publishesMigrations()` | Laravel 11+ | `publishes([...], 'dbexplorer-migrations')` + `loadMigrationsFrom()` |
| `Illuminate\Support\Facades\Process` | Laravel 10+ | `Symfony\Component\Process\Process` |
| `Context`, `Number`, `defer()`, `once()` | Laravel 11+ | Don't use them |
| `casts()` method on models | Laravel 11+ | `$casts` property |
| Route `->can()` | Laravel 9 ✓ | — |
| `callAfterResolving()`, `RateLimiter::for`, `URL::temporarySignedRoute`, `LazyCollection` | Laravel 9 ✓ | — |

**Tests must run on Pest 1.** The Laravel 9 lane runs Pest 1 because PHP 8.0 can't install Pest 2 or later. Shared tests therefore can't use `describe()`, `arch()`, `->todo()`, or `covers()`. `tests/ArchTest.php` returns early unless `arch()` and the Pest 3 presets exist (`Pest\ArchPresets\Php`).

**PostgreSQL in tests.** Most feature tests need a real Postgres, because SQLite can't tell us anything about `pg_catalog`, `statement_timeout`, or read-only transactions. The full strategy is in [TESTING.md](TESTING.md).
- Tests tagged `->group('pgsql')` connect using `DB_PGSQL_*` env vars. If those vars are missing, the tests are skipped.
- Local: `docker run -d --name dbx-pg -e POSTGRES_PASSWORD=secret -p 54320:5432 postgres:18` (port 54320 avoids Postgres.app)
- Tests create three roles, `dbx_viewer` (SELECT only), `dbx_editor` (DML) and `dbx_admin` (owner), plus a fixture schema. This exercises the role → connection mapping from PRD §5 for real.

**Shape.** Keep the existing provider, facade and singleton. Don't add an interface until a second implementation exists. Postgres-specific SQL lives only in `src/Drivers/Postgres/`. The MySQL milestone (v2) extracts the interface from it (see §8).

---

## 2. Target layout

```
config/dbexplorer.php
database/migrations/                 dbx_* tables (connection from config)
routes/dbexplorer.php                SPA shell + /api/*
resources/views/app.blade.php        SPA shell
resources/js/                        Vue 3 + TS source
dist/                                built assets (committed, CI-verified)
src/
  DbExplorer.php                     roleUsing(), role/connection resolution
  DbExplorerServiceProvider.php
  Facades/DbExplorer.php
  Http/
    Middleware/{Authorize,IpAllowlist,ContentSecurityPolicy}.php
    Controllers/{Shell,Asset,Me,Objects,Rows,Query,Ddl,Run,Download,SavedQuery,Schedule,Backup,Session,Audit,ConfirmPassword}Controller.php
  Drivers/Postgres/{Introspector,QueryRunner,RowWriter,DdlBuilder,Sessions}.php
  Sql/{Classifier,ScriptSplitter}.php
  Security/{HiddenObjects,Masker,PasswordConfirmation}.php
  Models/{AuditEntry,Run,QueryHistory,SavedQuery,Schedule}.php
  Events/{QueryExecuted,RowChanged,DdlExecuted,RunFinished,ScheduleFailed}.php
  Jobs/{RunQuery,Export,Import,RunScript,Backup,RunScheduledQuery}.php   (+ Concerns/TracksRun)
  Notifications/ScheduledQueryFinished.php
  Console/{InstallCommand,PruneCommand,ScheduleTickCommand,AuditVerifyCommand}.php
docs/  PRD.md  IMPLEMENTATION_PLAN.md  security.md  (user-facing docs live in README until they outgrow it)
```

---

## 3. M0 — Foundation

**Goal:** CI is green across the full matrix, an authenticated empty SPA shell loads, and the audit log exists.

### M0.1 Namespace + placeholder cleanup
- Apply D1: rename the scaffold namespace to `TacticalNinj4\DbExplorer` everywhere (composer autoload, `extra.laravel`, source, tests).
- Delete the scaffold placeholders: the placeholder migration, `placeholder.blade.php`, `DbExplorerCommand`, the `placeholder` config key, and `tests/*/ExampleTest.php`.
- **Done when:** `composer dump-autoload && composer test` passes.

### M0.2 Composer + CI matrix
- `composer.json`:
  - `php: ^8.0`
  - Replace `illuminate/support` with the components actually used: `illuminate/{support,contracts,database,routing,http,queue,console,auth}` `^9.0||^10.0||^11.0||^12.0||^13.0`
  - Add `ext-pdo_pgsql` to `suggest` in v1. It becomes required once Postgres is the only driver in use.
  - Dev: `orchestra/testbench ^7.0||^8.0||^9.0||^10.6||^11.0`, `pestphp/pest ^1.23||^2.0||^3.0||^4.0||^5.0`, `pestphp/pest-plugin-laravel ^1.4||^2.0||^3.0||^4.0||^5.0` (1.4 is the last 1.x plugin release)
- **Tooling lane vs. compatibility lanes.** `larastan`, `pint`, `pao` and `pest-plugin-type-coverage` need PHP 8.2+/8.3+. Only the tooling lane runs them. In compatibility lanes, CI first runs `composer remove --dev --no-update larastan/larastan laravel/pint laravel/pao pestphp/pest-plugin-type-coverage`, then runs only `vendor/bin/pest`. Compatibility lanes also set `composer config audit.block-insecure false`: Composer 2.9+ refuses to install versions with security advisories, which covers every Laravel 9/10 release and the lowest dependencies of the others.
- `.github/workflows/tests.yml`. The pairings below were checked against Packagist constraints and run locally in Docker (2026-10-09).

| Laravel | PHP | Testbench | Pest | Lane |
|---|---|---|---|---|
| 9 | 8.0, 8.2 | 7 | 1 | compat |
| 10 | 8.1, 8.3 | 8 | 2 | compat |
| 11 | 8.2, 8.4 | 9 | 3 | compat |
| 12 | 8.3, 8.5 | 10.6+ | 4 | compat |
| 13 | 8.4 | 11 | 5 | compat (prefer-lowest) |
| 13 | 8.5 | 11 | 5 | **tooling** (phpstan, pint, type coverage, arch) |

  - Each lane requires its Pest major explicitly, so the resolver can't drift to a newer one (e.g. Pest 4 on L11/PHP 8.4).
  - Pest 4 needs PHP 8.3 and Pest 5 needs PHP 8.4, which sets the lowest PHP for L12 and L13.
  - Testbench 10 starts at 10.6: older `testbench-core` calls `HandleExceptions::flushState()` without the test case and crashes on PHPUnit 12.3.
  - Arch tests run only on Pest 3+ (presets). Pest 1 has no `arch()`, and Pest 2 has no presets.
  - Pest 2.0 guesses the tests root from the first `<directory>` in `phpunit.xml.dist`, so those entries are written `./tests/Feature`, not `tests/Feature`. Without the `./`, `tests/Pest.php` isn't loaded.

  - `prefer-lowest` only on the lowest PHP of each Laravel version, which saves about half the runs.
  - Linux lanes start a `postgres` service. Postgres 12 runs on the L9 lanes and 18 on the others, so both ends of the support range are covered.
  - Keep one Windows lane (L13/PHP 8.4) without Postgres. It catches path and `Process` issues.
  - A separate `assets` job: `npm ci && npm run build && git diff --exit-code dist/`. Its steps are skipped until `package.json` exists (M0.7).
  - `composer run prepare` (workbench discovery) runs only in the tooling lane. Testbench 7.0's CLI can't read `laravel: '@testbench'`, and the tests don't need it.
- `phpstan.neon.dist`: add `phpVersion: 80000`.
- **Done when:** every matrix cell is green with the empty package.

### M0.3 Config
- Write the full `config/dbexplorer.php` from PRD §10, with a comment on every key.
- `DbExplorer::connectionFor(string $label, string $role): string` resolves an explorer label + role to a Laravel connection name. It throws when the label isn't allowlisted (S7).
- Tests: config merge and override, and that an unknown label throws.

### M0.4 Routing, gate, middleware (S1–S4)
- The provider registers routes **only if** `dbexplorer.enabled` is true (S1). The route group uses `domain`, `prefix` and `[...config middleware, IpAllowlist, Authorize]`.
- `Authorize`: if `Gate::has('viewDbExplorer')`, use `Gate::allows`. Otherwise allow only `app()->environment('local')` (S2). The check happens per request, so the host app's provider order doesn't matter.
- `IpAllowlist`: `Symfony\Component\HttpFoundation\IpUtils::checkIp($request->ip(), $list)`. An empty list allows any IP (S4).
- Tests: S1 (404 when disabled), S2 (403 in production without a gate, 200 in local, the host gate is respected), S3 (custom prefix/middleware), S4.

### M0.5 Roles
- `DbExplorer::roleUsing(callable $cb)`. The callback receives `($user, string $label)` and returns `'viewer'|'editor'|'admin'|null`. `null` means no access to that label.
- With no callback registered, everyone gets `viewer` (PRD §5).
- `final class Role` with constants and `atLeast(string $have, string $need): bool`.
- The request flow resolves role → connection once per request and keeps the result on the request attributes.
- Tests: default role, callback per label, `null` gives 403, hierarchy.

### M0.6 Metadata store + audit log (S17–S19, S22)
- Migrations for `dbx_audit_log`, `dbx_runs`, `dbx_query_history`, `dbx_saved_queries` and `dbx_schedules`. They're all created now so later milestones don't need to touch migrations. Each migration overrides `getConnection()` to return `config('dbexplorer.storage.connection')`.
- Provider: `loadMigrationsFrom()`, plus `publishes([...], 'dbexplorer-migrations')` for apps that want to customize them.
- `AuditEntry` model. Its `updating` and `deleting` events throw (S18). Only `PruneCommand` deletes, and it does so through the query builder.
- `DbExplorer::audit(array $entry)` writes the row. When `audit.log_channel` is set, it also does `Log::channel(...)->info(...)` (S19).
- **Hash chain (D3, S22):**
  - Each row stores `prev_hash` and `hash = HMAC-SHA256(key, prev_hash . canonical_json(row fields))`.
  - The key is `audit.key`, which falls back to `APP_KEY`. Without the key, someone with only DB access can't rebuild a valid chain after editing rows.
  - Inserts are serialized by `lockForUpdate()` on a single-row `dbx_audit_head` table inside a transaction. This is portable across pgsql, mysql and sqlite on the storage connection, and there's no cache dependency.
  - `dbx:audit:verify` recomputes the chain and reports the first broken id, exiting non-zero so it can run on a schedule.
  - Before `dbx:prune` deletes rows, it writes a `pruned` checkpoint entry. Verification then anchors on the oldest remaining row.
  - Known limit: deleting the *newest* rows can't be detected from the chain alone. Forwarding each `hash` to the log channel (S19) closes that gap. Document it in `docs/security.md`.
- Tests: tampering with any field, a deleted middle row, a reordered row, and a wrong key are all detected; a clean chain and post-prune verification both pass; concurrent inserts don't fork the chain (two processes on pgsql).
- Tests: a row gets written, updating or deleting through the model throws, the log channel receives the entry, and the separate storage connection is honoured.

### M0.7 SPA shell + asset pipeline (S15)
- `package.json`: `vue@3`, `vue-router@4`, `vite`, `@vitejs/plugin-vue`, `typescript`, `tailwindcss`. Nothing else is added until a milestone needs it.
- `vite.config.ts` builds to `dist/` with a manifest and hashed names.
- `ShellController` renders `app.blade.php`, which injects `window.DbExplorer = { basePath, csrfToken, user }`. It never sends connection config (S7). A catch-all route `/{view?}` serves it.
- `AssetController` serves only files listed in `dist/.vite/manifest.json`, which rules out path traversal by construction. It sets `Cache-Control: immutable`.
- `ContentSecurityPolicy` middleware: `default-src 'self'; script-src 'self' 'nonce-…'; object-src 'none'; frame-ancestors 'none'`.
- `GET /api/me` returns the user's name, plus the allowlisted labels that have a non-null role and that role.
- Frontend: a layout with a connection switcher and an empty main area. `api.ts` is a `fetch` wrapper that adds the CSRF header and handles 401, 403, 419 and 423.
- Tests: the shell renders with the CSP header, the asset route rejects any file not in the manifest, and `/api/me` leaks no host or password fields.

### M0.8 `dbx:install` + docs
- `dbx:install` publishes the config, prints the gate stub, and prints the SQL that creates the three Postgres roles (`docs/security.md`).
- `README.md`: install steps, enabling, the gate, role mapping, and the queue and scheduler requirements.
- `docs/security.md`: the threat model, the DB role recipe, what the app-level checks do *not* guarantee, and reporting (a link to `.github/SECURITY.md`).

**M0 exit:** matrix green; S1–S4, S7, S15, S17–S19, S22 tested; `composer serve` shows an authenticated shell in workbench.

### 3a. Events (D6). These cross-cut M1–M4.
Plain event classes, constructed with public properties (PHP 8.0-safe, no `readonly`). Each one is fired **after** its audit row is written and carries the `audit_id`.

| Event | Fired in | Payload |
|---|---|---|
| `QueryExecuted` | M1.6 QueryRunner | user, label, role, classification, masked SQL, duration, row count, outcome |
| `RowChanged` | M2.3 RowWriter | user, label, schema.table, operation, PK values (never column values) |
| `DdlExecuted` | M2.4, plus DDL through the editor or a script | user, label, SQL |
| `RunFinished` | M3.1 TracksRun | run id, type, status, duration |
| `ScheduleFailed` | M4.2 | schedule id, run id, error |

- **Security:** payloads never contain unmasked values, bound parameter values, or connection config. Tests assert this with `Event::fake()` for each event, both firing and payload (a no-leak assertion).
- Each event is added in the task that introduces its trigger, not as a separate task.

---

## 4. M1 — Read-only explorer

**Goal:** a viewer can browse a real database and run SELECT queries, with hidden objects, masking, and timeout and row limits enforced.

### M1.1 Workbench fixture
- The workbench uses Postgres when `DB_PGSQL_*` is set, and it seeds a fixture schema. The fixture has tables with and without a PK, a view, a materialized view, an enum, a function, a trigger, a partitioned table, jsonb/array/bytea columns, and a 1M-row table built with `generate_series` for perf checks.
- The same fixture SQL file feeds the pgsql test group.

### M1.2 Introspector
`Drivers/Postgres/Introspector`. It queries `pg_catalog` (not `information_schema`, which is slow and limited by privileges).
- `schemas()`, `objects(schema)` (relkind `r,p,v,m,S,f`, plus `pg_proc`, enum/domain/composite `pg_type`, and `pg_extension`)
- `columns(rel)` (`pg_attribute`, `format_type`, `pg_get_expr` defaults, `col_description`)
- `indexes(rel)` (`pg_get_indexdef`), `constraints(rel)` (`pg_get_constraintdef`, FK targets), `triggers(rel)` (`pg_get_triggerdef`, `NOT tgisinternal`)
- `viewDefinition`, `functionDefinition` (`pg_get_functiondef`)
- `stats(rel)` (`pg_stat_user_tables`, `pg_total_relation_size`, `reltuples`)
- `tableDdl(rel)`, built from columns, constraints and indexes, because Postgres has no built-in function for this
- `search(term)`
- Every method takes the already-resolved connection. Everything is bound or `::regclass`-cast. No identifiers from user input are ever placed in the SQL text.
- Tests (pgsql) for each object type in the fixture.

### M1.3 Hidden objects (S13)
- `Security/HiddenObjects::isHidden(schema, name)` uses `Str::is()` globs.
- Introspector results are filtered through it.
- Row and object endpoints return 404 for hidden objects, not 403. Answering 403 would confirm that the object exists.
- **Best-effort check for queries.** Before running a user query, run `EXPLAIN (FORMAT JSON)` on it and collect every `Relation Name` + `Schema` from the plan. Views are inlined in the plan, so their base tables show up too. If any of them is hidden, reject the query. Known gap: functions that read hidden tables aren't caught. `docs/security.md` says so.
- Tests: hidden objects missing from the tree, 404 on direct access, direct SELECT rejected, SELECT through a view rejected.

### M1.4 Masker (S14)
- `Security/Masker::maskRows(rows, columnMeta)`. Column metadata comes from `PDOStatement::getColumnMeta()`. pdo_pgsql provides `table` and `pgsql:table_oid`, so rules match `schema.table.column`. Columns with no table, such as expressions, fall back to matching the column name.
- Masked value: `'••••••'`. `NULL` stays `NULL`, so a masked cell still shows whether a value exists.
- Known gap: `SELECT password AS x` keeps the table oid but loses the column name. Mitigation: column-level `REVOKE` in the viewer DB role (recipe in `docs/security.md`). A test documents the current behaviour.
- Unmask: an admin-only per-request flag, audited (M1.7).

### M1.5 SQL classifier
- `Sql/Classifier::classify(string $sql): string` returns `read|dml|ddl|destructive|unknown`.
- A small tokenizer strips comments, quoted strings, dollar-quoted bodies and quoted identifiers, then reads the leading keyword. It looks inside `WITH` CTEs for data-modifying statements, and it flags UPDATE or DELETE that has no top-level `WHERE`, plus DROP, TRUNCATE and ALTER … DROP.
- **UX only (S11).** No permission check may depend on it.
- Tests: a table-driven unit test with roughly 60 cases (comment tricks, `$$` bodies, CTE writes, `SELECT … FOR UPDATE`, case and whitespace).

### M1.6 QueryRunner (S8–S10, S12)
`Drivers/Postgres/QueryRunner::run(conn, sql, bindings, role, opts): Result`
1. `BEGIN`, using `BEGIN READ ONLY` for viewers (S9)
2. `SET LOCAL statement_timeout = <role limit>` (S10)
3. Hidden-object check (M1.3)
4. **SELECT-like** (classifier says `read`): `DECLARE dbx_cur NO SCROLL CURSOR FOR <sql>` then `FETCH <cap+1>`. Postgres only accepts SELECT/VALUES in `DECLARE`, which adds another guard. Result memory stays bounded at the row cap. One row over the cap sets `truncated=true`.
5. **Everything else:** a native prepare (`PDO::ATTR_EMULATE_PREPARES=false`, `PDO::PGSQL_ATTR_DISABLE_PREPARES=false`, set explicitly). Postgres refuses to prepare more than one command (S8).
6. `COMMIT`, or `ROLLBACK` on error or for EXPLAIN ANALYZE
7. Capture duration, row count, column meta, and NOTICEs. NOTICE capture needs a spike (see §10).
- Every run writes the audit log (S17) and query history.
- `EXPLAIN [ANALYZE]` wraps the statement and always rolls back.
- Tests (pgsql): S8 `SELECT 1; DROP TABLE t` rejected with nothing dropped. S9 viewer `UPDATE` fails even on a connection that has write grants. S10 `pg_sleep(5)` with a 1s limit times out. The row cap and `truncated` flag work. An error rolls back.

### M1.7 Read APIs
- `GET /api/{label}/schemas`, `/objects`, `/objects/{schema}/{name}` (+ `/columns|indexes|constraints|triggers|ddl|stats|definition`), `/search?q=`
- `GET /api/{label}/rows/{schema}/{name}`:
  - Keyset pagination when a PK or unique key exists (`WHERE (pk) > (?) ORDER BY pk LIMIT n`), offset pagination otherwise.
  - `sort` and `filters[]`: column names are validated **against introspected columns** (S12) and operators against an allowlist (`= != < <= > >= like ilike is_null not_null in`). Values are always bound.
  - Count is `reltuples` by default. `?exact=1` runs `count(*)` under the timeout.
- `POST /api/{label}/query` uses QueryRunner, plus `explain=plain|analyze`. Throttled through `RateLimiter::for('dbexplorer')` (S6).
- `GET /api/{label}/history`
- Tests: authz per role, S12 (an injection attempt in sort or filter is rejected with 422), keyset correctness across pages.

### M1.8 Frontend: browser, grid, editor
- `ObjectTree` (lazy-loaded, searchable), table detail tabs, FK navigation.
- `DataGrid`: one component used by both the table view and query results. Cells render through `{{ }}` text interpolation only, and `v-html` is banned by an ESLint rule (S15). Type-aware rendering covers jsonb (collapsible), arrays, bytea (hex preview), and truncated text with expand.
- `SqlEditor`: CodeMirror 6 (`codemirror`, `@codemirror/lang-sql` with the PostgreSQL dialect), with schema-aware autocomplete fed by `/objects` and columns. Tabs persist in `localStorage`. There's a history panel and an EXPLAIN plan tree.
- No Playwright in v1. Covered by API feature tests plus a manual checklist in the PR template.

**M1 exit:** S8–S15 tested; a page of the 1M-row fixture renders in under 500 ms (manual check, results noted in the PR).

---

## 5. M2 — Writes

### M2.1 Password confirmation (S5)
- `Security/PasswordConfirmation` reads and writes the same session key Laravel uses (`auth.password_confirmed_at`) and respects `auth.password_timeout`. A confirmation made in the host app therefore counts here too, and the reverse.
- `POST /api/confirm-password` checks the password through the guard's user provider (`validateCredentials`, so custom providers work; `Hash::check` against `getAuthPassword()` only for guards without one), throttled at 5 attempts per minute by the `dbexplorer-confirm` limiter. Every attempt is audited as `password_confirm`.
- Endpoints that perform destructive actions return **423** when confirmation is stale. The SPA catches 423, shows a modal, confirms, and retries the request.
- Tests: 423 → confirm → success; wrong password; throttling; timeout expiry.

### M2.2 DML in the SQL editor
- Editor and admin roles can run `dml` through QueryRunner. `destructive` requires S5. The classifier result drives the confirmation dialog in the SPA.
- DDL in the editor is admin-only. The check uses the classifier for UX, while DB grants are the real enforcement: the editor connection has no DDL rights.
- Tests: an editor running DDL fails **at the database** even if the classifier misses it (use a classifier-evasion fixture).

### M2.3 Inline row editing
- `Drivers/Postgres/RowWriter`: `insert`, `update` and `delete`, available only for tables with a PK.
- **Optimistic check:** `UPDATE t SET … WHERE pk = ? AND col IS NOT DISTINCT FROM ? …`, checked on the edited columns plus the PK. If the affected row count isn't 1, it returns 409 with the current row.
- `?preview=1` returns the SQL with literals rendered for display only. Nothing executes.
- Delete row counts as destructive and requires S5.
- Each operation runs in its own transaction and writes one audit entry.
- Tests: conflict → 409, viewer → 403, no PK → 422, masked column not editable (unless admin unmasks).

### M2.4 DDL builder + forms
- `Drivers/Postgres/DdlBuilder` turns structured JSON into SQL for create, alter (add, drop or rename column, change type, set or drop default and nullability) and drop, for tables, indexes, constraints and views.
- **Type strings are a SQL injection vector.** They're validated with `SELECT to_regtype(?)`, which must return non-null. Identifiers are quoted with `"` doubled. Default expressions are raw SQL. That's acceptable because DDL is admin-only and admins can already run arbitrary SQL, and the docs say so.
- `POST /api/{label}/ddl/preview` and `/ddl/execute`. Execute runs in a transaction. `CREATE INDEX CONCURRENTLY` is delegated to a background Run (M3), so it's disabled until M3 is done.
- Frontend: forms plus a SQL preview panel with "Copy as SQL", "Copy as Laravel migration" (a best-effort `Schema::` snippet for simple cases), and "Execute".
- Tests: builder unit tests with a snapshot per operation, type validation rejects `int; DROP TABLE x`, non-admin gets 403.

### M2.5 Shared-connection warning
- If two roles of a label map to the same Laravel connection, `/api/me` returns `warnings[]` and the SPA shows a persistent banner (PRD §13).

**M2 exit:** S5 and S6 tested, every write is audited, and the classifier-evasion test proves DB grants enforce the boundary.

---

## 6. M3 — Background work

### M3.1 Run infrastructure
- `Models/Run`: type, status (`RunStatus` constants), `progress` (0–100 or null), `meta` (json), `result_path`, `error`, `pid`, `cancel_requested`, `user_id`, `label`, `role`, timestamps.
- `Jobs/Concerns/TracksRun` handles mark running, store `pg_backend_pid()`, progress updates, finish or fail, and `failed()` hooks.
- **All jobs have `$tries = 1`.** Write jobs aren't idempotent and must never retry. `$timeout` comes from `limits.background.timeout`. Jobs carry only the run ID (S21) and re-resolve the role's connection at runtime. If the user's role has been lowered since dispatch, the job fails.
- `POST api/{label}/runs/{id}/cancel` sets `cancel_requested` and calls `pg_cancel_backend(pid)` on the run's connection. Postgres allows the same DB role to cancel its own backends. Jobs also check the flag between chunks and statements.
- `GET api/{label}/runs` returns your own runs (admins of the label see every run on it) and `GET api/{label}/runs/{id}`. Anyone else's run is a 404. The SPA polls every 2s while any run is active.
- Queue health: if the queue driver is `sync` → banner. If any run has been `queued` for more than 5 minutes → "no worker seems to be running" banner. No heartbeat infrastructure.
- Tests: status transitions, cancel mid-`pg_sleep` (pgsql), demoted user fails the job, sync-driver warning.

### M3.2 Background query + export
- `Jobs/RunQuery` and `Jobs/Export` use the same core: inside a transaction, `DECLARE` a cursor, then `FETCH 5000` in a loop and write to a local temp stream, then `Storage::disk()->putFileAs()` (which works for S3 too). Memory stays flat.
- Formats: CSV (`fputcsv`), JSON Lines, SQL `INSERT`s (values quoted through PDO `quote`). Masking applies (S14, D4).
- Tests: a 1M-row export keeps peak memory under 64 MB (`memory_get_peak_usage`), formats round-trip, masked columns stay masked in the file.

### M3.3 Downloads + pruning (S16)
- `GET api/{label}/runs/{id}/download` returns `URL::temporarySignedRoute(..., 10 min)`. The signed route also checks `run.user_id === auth()->id()` (or an admin of the run's label), and answers 404 otherwise, so run ids can't be probed. The disk must be private: at boot, `local` without a `url` passes, `public` triggers a warning.
- `dbx:prune` deletes expired files (`storage.file_ttl_hours`), runs, history, and audit rows older than `audit.retention_days`. The provider schedules it daily through `callAfterResolving(Schedule::class, …)`.
- Tests: expired signature, a different user gets 404, prune removes only expired rows and files.

### M3.4 CSV import
- Upload → private disk → `POST /imports/preview` returns the first 20 rows plus detected columns → the user maps columns → dispatch `Jobs/Import`.
- **All-or-nothing:** one transaction and `pgsqlCopyFromArray` in batches of 5000. Any error rolls everything back.
- **Skip bad rows:** each batch runs under a `SAVEPOINT`. If a batch fails, roll back to the savepoint and insert that batch row by row to find the bad rows, which are recorded in `meta.errors` (capped at 1000).
- PHP 8.4 deprecates `PDO::pgsqlCopyFromArray` in favour of `Pdo\Pgsql::copyFromArray`. A small version check picks the right one.
- Editor role or higher. Upload size is capped by `limits.import.max_upload_kb`, and only content sniffed as `text/csv`, `text/plain`, `application/csv` or `text/x-csv` is accepted. The run reports `inserted`, `skipped` and `errors` (row number and message, never cell values).
- Tests: both modes, bad rows reported, viewer gets 403.

### M3.5 Script runner
- `Sql/ScriptSplitter` splits on `;` while respecting strings, `E''` strings, dollar quotes, quoted identifiers, line and block comments, and `BEGIN ATOMIC` bodies. It's unit-tested heavily because it's the riskiest parser in the project.
- `Jobs/RunScript`, with two modes:
  - **transaction:** one `BEGIN`, then the statements, then `COMMIT`, with a rollback on the first error.
  - **step:** each statement auto-commits, and the run stops on the first error.
- Per-statement status, duration and rowcount are stored in `meta.statements`. The audit log records the whole script plus per-statement outcomes.
- Requires the editor role, or admin if any statement is classified `ddl`. That check is UX only, since DB grants enforce it.
- This is where `CREATE INDEX CONCURRENTLY` from M2.4 runs, in step mode.
- Tests: splitter tests, rollback in transaction mode, partial commit in step mode, cancel between statements.

**M3 exit:** S16, S20 (stubbed until M4), S21 tested; a 10M-row export is checked manually and memory stays flat.

---

## 7. M4 — Automation & ops

### M4.1 Saved queries
- `SavedQuery` with `name`, `sql`, `params` (json: name, type `string|int|date|enum`, enum options), `visibility` (`private|shared`), `tags` and `user_id`.
- `:name` placeholders become positional `?` bindings, which reuses the splitter's string-aware tokenizer so `':name'` inside a literal is left alone. Values are cast and validated per type.
- Running a shared query requires the role that its classification needs.
- Tests: param binding, `:name` inside a string untouched, role check on shared queries.

### M4.2 Scheduled queries + notifications
- `Schedule`: `saved_query_id` (provenance only), a snapshot of the SQL and params with its `sql_hash`, `approved_by`/`approved_at`, `allow_writes`/`allow_ddl`, `cron`, `enabled`, `notify` (json: `on` = success|failure|threshold, `threshold_rows`, `mail` recipients), `user_id`, `last_run_at`. Semantics in PRD §7.9 and docs/security.md §6; `POST api/{label}/schedules/{id}/resync` re-approves the saved query's current SQL.
- `dbx:schedule:tick` is registered `->everyMinute()` through `callAfterResolving(Schedule::class)`. For each enabled schedule where `CronExpression::isDue()` is true, it takes `Cache::lock("dbx:schedule:{id}", timeout)` → dispatches `RunScheduledQuery`.
- **Run-as:** the job runs the snapshot as the approving admin, in a read-only transaction unless `allow_writes`. It re-resolves the approver's role; if they're no longer admin, or the snapshot doesn't match its hash, the schedule is disabled and audited.
- `Notifications/ScheduledQueryFinished` goes out by mail through `Notification::route('mail', …)`. For other channels, the host app can bind its own notifiable. No Slack dependency is added.
- Tests: due vs. not-due, overlap lock, creator demoted → disabled, threshold notification sent or not sent (`Notification::fake()`).

### M4.3 Backups (S20)
- `Jobs/Backup` runs `new Process([pg_dump_path, '--format=custom'|'plain', '--host', …, '--port', …, '--username', …, '--dbname', …, ...--schema/--table], null, ['PGPASSWORD' => $password])`. The connection's SSL settings go in `PGSSLMODE`/`PGSSLROOTCERT`/`PGSSLCERT`/`PGSSLKEY`. pg_dump writes straight onto the backup disk. Retention keeps the newest `backup.keep` per label.
- Preflight runs `pg_dump --version` and compares it with `SHOW server_version_num`. pg_dump must be at least the server's major version. `GET api/{label}/backups` reports the result, and `POST` answers 422 with it instead of queueing.
- Tests: the Process is built with an argument array and no password in the args (S20, asserted on the command line), retention, version-mismatch message. A real dump→`pg_restore` round-trip runs in the pgsql CI lane.
- `docs/security.md` + README: restore through the CLI only.

### M4.4 Active sessions
- `Drivers/Postgres/Sessions`: `pg_stat_activity WHERE datname = current_database()`, with `cancel(pid)` and `terminate(pid)`.
- Admin only. Terminate requires S5. Both are audited. Only sessions of the same login are signalled (`usename = current_user`), so no `pg_signal_backend` grant is needed.
- Tests: list, cancel a sleeping backend (pgsql), non-admin gets 403.

### M4.5 Audit log viewer
- `GET api/{label}/audit` (admin of `{label}`): paginated, filterable by user, label, action, classification, outcome and date, showing only rows from labels where the user is admin. A read-only page in the SPA (`/:label/audit`).

**M4 exit:** schedules survive overlap and failure, a backup round-trips through a CLI restore, every PRD §6 requirement has a passing test, and v1.0.0 is released.

---

## 8. v2 — MySQL (outline only)

1. Extract `Drivers\Contracts\{Introspector,QueryRunner,RowWriter,DdlBuilder,Sessions}` from the Postgres classes, now that a second implementation exists.
2. Driver selection by `DB::connection()->getDriverName()`.
3. MySQL equivalents and known gaps: no transactional DDL; read-only through `START TRANSACTION READ ONLY`; timeout through `max_execution_time` (SELECT only); cancel through `KILL QUERY`; `mysqldump`; multi-statement protection through `PDO::MYSQL_ATTR_MULTI_STATEMENTS=false`. Unbuffered cursors replace `DECLARE CURSOR`.
4. The classifier and splitter get a dialect flag (backticks, `#` comments, `DELIMITER`).

---

## 9. Security test map

| Req | Where tested | Milestone |
|---|---|---|
| S1–S4 | `Feature/AccessTest` | M0 |
| S5 | `Feature/PasswordConfirmationTest`, plus the 423 cases in `Pgsql/QueryWritesTest`, `Pgsql/RowWriterTest`, `Pgsql/DdlApiTest`, `Pgsql/SessionsTest`, `Feature/ScheduleTest` | M2 |
| S6 | `Feature/RateLimitTest` | M1/M2 |
| S7 | `Feature/ConnectionAllowlistTest`, `Feature/MeTest` (no config leak) | M0 |
| S8–S10 | `Pgsql/QueryRunnerTest`, `Pgsql/QueryApiTest` | M1 |
| S11 | `Pgsql/ClassifierEvasionTest`: the DB rejects what the classifier misses and the role's grants forbid | M2 |
| S12 | `Pgsql/RowsApiTest` injection cases, `Unit/RowsQueryTest`, `Unit/DdlBuilderTest`, `Pgsql/ExportJobTest` | M1 |
| S13 | `Pgsql/HiddenObjectsTest`, `Unit/HiddenObjectsTest` | M1 |
| S14 | `Unit/MaskerTest`, `Pgsql/MaskingTest`, export masking in `Pgsql/ExportJobTest` | M1/M3 |
| S15 | `Feature/ShellTest` (CSP, no `v-html`), `Pgsql/CellXssTest` (cell values as JSON strings), ESLint `vue/no-v-html` | M0/M1 |
| S16 | `Feature/DownloadTest` | M3 |
| S17–S19 | `Feature/AuditTest` | M0 |
| S20 | `Pgsql/BackupTest` | M4 |
| S21 | `Feature/RunsTest` payload assertion | M3 |
| S22 | `Feature/AuditChainTest`, `Pgsql/AuditChainConcurrencyTest` | M0 |

---

## 10. Risks specific to implementation

| Risk | Plan |
|---|---|
| Pest 1 lacks features newer tests want to use | Keep shared tests to plain `it()` + `expect()`. Arch and type coverage run only in the tooling lane. |
| Capturing Postgres `NOTICE` messages through PDO is awkward, since pdo_pgsql has no notice API before PHP 8.4's `Pdo\Pgsql::setNoticeCallback` | Confirmed that `Pdo\Pgsql::setNoticeCallback` exists on PHP 8.4 (TESTING.md §5.1). Spike older PHP in M1.6. If there's no clean path there, show notices only on PHP ≥ 8.4 and document it. |
| `ScriptSplitter` misparses an edge case and runs a half statement | Heavy table tests. In transaction mode a parse error fails safely, because the whole transaction rolls back. |
| The EXPLAIN-based hidden-object check adds latency | Planning-only EXPLAIN is cheap. Measure in M1.3 and make it skippable when `hidden` is empty. |
| The matrix (10 cells + Windows + assets) makes CI slow | Cancel in-progress runs per branch (already configured) and run `prefer-lowest` only on the lowest cell of each Laravel version. |
