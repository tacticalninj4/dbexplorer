# DbExplorer — Testing Strategy

| | |
|---|---|
| Applies to | [PRD.md](PRD.md), [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) |
| Status | Draft v0.1 |
| Last updated | 2026-10-09 |

## 1. Principles

1. **Test against real PostgreSQL.** SQLite can't prove anything about read-only transactions, `statement_timeout`, `pg_catalog`, cursors, or database privileges. Every claim in the PRD about query safety is checked against a real server.
2. **The database is the security boundary, and tests prove it.** For each security rule we test the app-level check *and* show that the database rejects the attack when that check is bypassed (§5).
3. **Write the test first.** Each task starts with a failing test that describes the behavior. Then write the smallest change that makes it pass (see the `package-testing` skill).
4. **Test behavior, not implementation.** Prefer HTTP and command-level assertions over calls to internal methods. Unit tests are only for pure logic: parsers, builders and matchers.
5. **Name tests after the requirement they cover.** `it('S8 rejects multi-statement queries')`. Running `vendor/bin/pest --filter=S8` gives the proof for one requirement.

## 2. Test layers

| Layer | Location | Needs | Runs on |
|---|---|---|---|
| Unit | `tests/Unit/` | nothing | every CI lane, including PHP 8.0 and Windows |
| Feature | `tests/Feature/` | Testbench, SQLite `:memory:` for metadata | every CI lane |
| Postgres integration | `tests/Pgsql/` (`->group('pgsql')`) | Postgres + `DB_PGSQL_*` env | Linux CI lanes, and locally |
| Arch / type coverage | `tests/ArchTest.php`, `--type-coverage` | Pest ≥ 2 | tooling lane only |
| Frontend static | ESLint, `vue-tsc`, build-diff | Node | `assets` CI job |
| Browser smoke | manual or Claude in Chrome | `composer serve` | once per milestone |

### 2.1 Unit: pure logic
These are table-driven, with each case as one dataset row. Parsers get adversarial input.

| Subject | Cases include |
|---|---|
| `Sql/Classifier` | leading comments (`/* */ DROP`), `--` hiding keywords, `$$`/`$tag$` bodies, `E'\''` strings, quoted identifiers named `"drop"`, CTEs that write (`WITH x AS (DELETE …) SELECT`), `SELECT … FOR UPDATE`, UPDATE/DELETE with a WHERE only inside a subquery, mixed case and whitespace |
| `Sql/ScriptSplitter` | `;` inside strings, dollar quotes, comments and identifiers; nested `$a$ $b$ $b$ $a$`; `BEGIN ATOMIC … END`; trailing statements without `;`; empty statements |
| `Drivers/Postgres/DdlBuilder` | one expected-SQL string per operation; identifiers that need quoting (`"we""ird"`), reserved words |
| `Security/HiddenObjects`, `Security/Masker` | glob precedence, schema-qualified vs. bare patterns, `NULL` preserved, expression columns |
| Saved query params | `:name` → `?` binding; `':name'` inside a literal stays untouched; `::cast` is not a parameter |
| `Role` | hierarchy, unknown roles |

### 2.2 Feature: wiring and HTTP
These use Testbench with `actingAs()` on the workbench `User` and need no Postgres.
- **Access (S1–S4):**
  - disabled → 404 and routes not registered
  - production with no gate → 403; local → 200; a host-defined gate is respected
  - custom prefix, domain and middleware
  - IP allowlist allows and denies
- **Leaks (S7, S15):** `/api/me` and the SPA shell contain no host, port, username or password from any connection config. CSP header present. The asset route serves only files listed in the manifest (`../`, absolute paths and unknown names → 404).
- **Audit (S17–S19):** rows are written, `update()` and `delete()` on the model throw, the configured log channel receives entries, and the separate `storage.connection` is honoured.
- **Package surface:** config merge and override, every publish tag, `dbx:install`, `dbx:prune`, scheduler registration of `dbx:schedule:tick` and `dbx:prune`.
- **Password confirmation (S5):** 423 when stale → confirm → retry succeeds; wrong password; throttling; timeout expiry.
- **Downloads (S16):** expired signature, another user → 403, an admin can download.

### 2.3 Postgres integration: the core
The full setup is in §3. What this layer proves:
- **Introspector:** every fixture object type is listed and described correctly, and hidden objects are absent.
- **QueryRunner (S8–S10):**
  - multi-statement SQL is rejected and the table still exists afterwards
  - a viewer `UPDATE` fails even on a connection whose login has write grants
  - `pg_sleep` beyond the role limit is cancelled
  - the row cap sets `truncated`
  - an error rolls back
  - `EXPLAIN ANALYZE` of a DELETE leaves rows intact
- **Rows API (S12):** keyset pagination is correct across page boundaries. Sort and filter injection attempts (`id; drop`, `id desc, (select …)`, unknown operators) get 422.
- **Writes:** an optimistic-lock conflict returns 409, a table with no PK returns 422, and `to_regtype` rejects `int; DROP TABLE x`.
- **Jobs:** see §4.
- **Backups (S20):** real `pg_dump` → `pg_restore` into a scratch database, then row counts are compared.
- **Sessions:** list, cancel a sleeping backend, non-admin → 403.

### 2.4 Frontend
- ESLint with `vue/no-v-html` set to **error**, so cells can never render HTML (S15).
- `vue-tsc --noEmit` for type checking.
- **Build-diff:** CI runs `npm ci && npm run build && git diff --exit-code dist/`. Committed assets always match the source.
- No component or e2e tests in v1. Revisit if UI logic grows beyond rendering API responses.

### 2.5 Browser smoke (per milestone)
Run against `composer serve` with a Postgres-backed workbench, using Claude in Chrome with a GIF recording attached to the milestone PR. The checklist grows with each milestone:
- M0: login → shell loads → connection switcher shows only allowlisted labels
- M1: browse the tree, open a table, paginate, sort and filter, run a SELECT, view EXPLAIN, view history
- M2: edit a row, trigger a conflict, run a DELETE without WHERE → password modal → confirm
- M3: start an export → progress → download; cancel a long query
- M4: create a saved query with params, schedule it, take a backup, kill a session

## 3. PostgreSQL test environment

### 3.1 Local
Docker, using port **54320** so it doesn't clash with Postgres.app on 5432:

```bash
docker run -d --name dbx-pg -e POSTGRES_PASSWORD=secret -p 54320:5432 postgres:18
# oldest supported version, when checking compatibility:
docker run -d --name dbx-pg12 -e POSTGRES_PASSWORD=secret -p 54312:5432 postgres:12
```

Environment (put it in `phpunit.xml` locally or export it):

```
DB_PGSQL_HOST=127.0.0.1
DB_PGSQL_PORT=54320
DB_PGSQL_USERNAME=postgres
DB_PGSQL_PASSWORD=secret
```

Without `DB_PGSQL_HOST`, the `pgsql` group is **skipped, not failed**, so contributors without Docker can still run everything else. CI always sets it, so skips can't hide failures there.

### 3.2 What the test bootstrap creates
It connects as the superuser and does the following, idempotently:
1. Creates **cluster-wide login roles** (`CREATE ROLE … IF NOT EXISTS` through a `DO` block): `dbx_viewer`, `dbx_editor` and `dbx_admin`.
2. Creates a **database per parallel worker**, `dbx_test_{TEST_TOKEN}` (`dbx_test` when not parallel), with `dbx_admin` as owner.
3. Loads the **fixture** (`tests/fixtures/pgsql.sql`, shared with the workbench seeder) into schema `fixture`:
   - tables with and without a PK
   - a composite PK
   - a view, a materialized view, a partitioned table, a sequence
   - an enum, a domain, a composite type
   - a SQL function and a plpgsql function, including `sneaky()`, which writes when called
   - a trigger
   - jsonb, array, bytea, timestamptz and long-text columns
   - `fixture.big`, with 1M rows from `generate_series`
   - hidden-object targets (`fixture.secrets`) and masking targets (`users.password`, `api_token`)
4. Grants: `dbx_viewer` gets SELECT on `fixture`. `dbx_editor` gets SELECT/INSERT/UPDATE/DELETE with no DDL. `dbx_admin` owns everything.
5. Registers three Laravel connections, `pgsql_viewer`, `pgsql_editor` and `pgsql_admin`, plus a `pgsql_super` used only by test setup and assertions. They're mapped through `dbexplorer.connections.main.{viewer,editor,admin}` exactly as an app would configure them.

The fixture is loaded **once per worker** and treated as read-only. Tests that change data or schema work in a **per-test scratch schema** (`t_<random>`), which is created in `beforeEach` and dropped in `afterEach`. We can't use `DatabaseTransactions` because QueryRunner manages its own transactions on the same PDO.

The metadata store (`dbx_*` tables) for pgsql tests lives in the worker database, schema `public`. A child process (the queue worker in §4) can see it there. SQLite `:memory:` couldn't be shared with a child process.

### 3.3 CI
- Linux lanes run a `postgres` service container: **12** on the Laravel 9 lanes (oldest supported) and **18** on the rest.
- `pdo_pgsql` and `pgsql` are added to the setup-php extensions. The `postgresql-client` package is installed so `pg_dump` and `pg_restore` are available, matching the server's major version.
- The Windows lane has no Postgres. It runs unit and feature tests to catch path-separator and `Process` quoting issues.

## 4. Testing background jobs

| What | How |
|---|---|
| Dispatch: the right job, only the run ID in the payload (S21), the right queue | `Queue::fake()` + `Queue::assertPushed()` with a payload assertion |
| Job behavior: status transitions, progress, result file, `tries = 1` | Call `handle()` directly against pgsql with `Storage::fake()` |
| User demoted after dispatch → job fails | Change the `roleUsing` callback between dispatch and `handle()` |
| **Cancel mid-query** | End to end: use the `database` queue driver and start a child process `vendor/bin/testbench queue:work --once` (Symfony `Process`) running a background query with `pg_sleep(30)`. Poll `dbx_runs` until `pid` is set, call `POST /api/runs/{id}/cancel`, wait for the child to exit, then assert run = `cancelled` and the backend is gone from `pg_stat_activity`. Timeout 20s. |
| Export memory stays flat | In-process export of `fixture.big`, then assert `memory_get_peak_usage(true)` grows by less than 64 MB |
| Import modes | All-or-nothing rolls back fully on one bad row. Skip-bad-rows imports the good rows and reports the bad row numbers. |
| Script runner | Transaction mode rolls back everything, step mode keeps earlier statements, and cancel between statements works |
| Scheduler | Freeze time with `$this->travelTo()`, run `dbx:schedule:tick`, assert due and not-due dispatches. Overlap is checked with a held `Cache::lock`. Notifications use `Notification::fake()`. |

## 5. Security test suite

Each requirement has a positive test (a legitimate action works) and an adversarial test (the attack fails). The **bypass tests** in the last group skip the app-level check entirely, by calling the runner with a forced classification or using a classifier-evasion payload, and show that the **database** rejects the attack.

| Req | Adversarial cases | File |
|---|---|---|
| S1–S4 | disabled routes, production with no gate, wrong IP, unauthenticated | `Feature/AccessTest` |
| S5 | destructive request without confirmation, stale confirmation, brute-force confirm | `Feature/PasswordConfirmationTest` |
| S6 | burst over the limit → 429 | `Feature/RateLimitTest` |
| S7 | label not in the allowlist, a raw connection name passed as label, config leak in any JSON response | `Feature/ConnectionAllowlistTest` |
| S8 | `SELECT 1; DROP …`, `SELECT 1;--\nDROP`, a statement after a comment | `Pgsql/QueryRunnerTest` |
| S9 | viewer UPDATE/DELETE/INSERT, `SELECT sneaky()`, `SET TRANSACTION READ WRITE` after the first query, `COMMIT` then a write | `Pgsql/QueryRunnerTest` |
| S10 | `pg_sleep` over the limit, a huge cross join with the row cap | `Pgsql/QueryRunnerTest` |
| S11 | classifier-evasion payloads run as viewer and as editor: `DO $$ … $$`, a function with side effects, a CTE write, DDL inside a function. The database must reject every one. | `Pgsql/ClassifierEvasionTest` |
| S12 | sort and filter injection, unknown columns, unknown operators | `Pgsql/RowsApiTest` |
| S13 | a hidden table queried directly, through a view, a CTE or a subquery → rejected. Through a function → **documented gap** (the test asserts current behavior). | `Pgsql/HiddenObjectsTest` |
| S14 | a masked column in grid, query and export. `SELECT password AS x` → **documented gap** (pdo_pgsql keeps the table name but not the source column, as verified by the spike below). The test asserts current behavior, and the docs point to column-level `REVOKE`. | `Pgsql/MaskingTest` |
| S15 | a cell value `<img src=x onerror=…>` comes back as a JSON string, CSP header present, `no-v-html` lint | `Feature/ShellTest` + ESLint |
| S16 | expired URL, another user's URL, tampered signature | `Feature/DownloadTest` |
| S17–S19 | every write path leaves an audit row; update or delete of an audit row throws | `Feature/AuditTest`, plus an audit assertion in each write test |
| S22 | audit chain: a tampered field, a deleted middle row, a reordered row and a wrong key are each detected; post-prune verification passes; concurrent inserts on pgsql don't fork the chain; tail truncation → `GAP:` test | `Feature/AuditChainTest`, `Pgsql/AuditChainConcurrencyTest` |
| Events | each event fires after its audit row is written; its payload has no unmasked values, bindings or connection config | `Event::fake()` assertions in each trigger's test |
| S20 | the `Process` command line contains no password, and arguments are passed as an array, not a shell string | `Pgsql/BackupTest` |
| S21 | serialized job payloads contain no credentials | `Feature/RunJobTest` |

**Documented-gap tests** use the prefix `GAP:` in the test name, so they're easy to find and can't change silently.

### 5.1 Spike results (2026-10-09, PHP 8.4.25, PostgreSQL 18.6)
These were verified before the plan was written. Each one becomes a regression test in M1.

| Assumption | Result |
|---|---|
| A native prepare rejects multi-statement SQL | ✓ `cannot insert multiple commands into a prepared statement`, and the table survives |
| `DECLARE CURSOR` accepts only SELECT-like statements | ✓ `syntax error at or near "update"` |
| A read-only transaction blocks writes made through a function with side effects | ✓ `cannot execute UPDATE in a read-only transaction` |
| A read-only transaction can't be switched to read-write after the first query | ✓ `transaction read-write mode must be set before any query` |
| `SET LOCAL statement_timeout` cancels | ✓ SQLSTATE 57014 |
| A SELECT-only login is blocked even in a `DO` block | ✓ `permission denied for table t` |
| `getColumnMeta` exposes the table | ✓ `table`, `pgsql:table_oid`, kept under an alias. **The source column name is lost under an alias**, which confirms the S14 gap. |
| NOTICE capture on PHP 8.4 | ✓ `Pdo\Pgsql::setNoticeCallback` exists. Older PHP still needs the M1.6 spike. |
| `copyFromArray` | Both `PDO::pgsqlCopyFromArray` and `Pdo\Pgsql::copyFromArray` exist on 8.4 |

## 6. Compatibility testing

- **CI matrix:** see IMPLEMENTATION_PLAN §3 (M0.2). That covers Laravel 9–13, PHP 8.0–8.5 and Testbench 7–11, plus Pest 1–5 in compatibility lanes and full tooling on L13.
- **Pest 1 constraint:** shared tests use only `it()`, `test()`, `expect()`, `beforeEach()`, `afterEach()` and datasets. No `describe()`, `arch()`, `->todo()` or `covers()`. `ArchTest.php` returns early when `arch()` doesn't exist.
- **Static check for PHP 8.0 syntax:** PHPStan with `phpVersion: 80000` in the tooling lane.
- **Local spot checks** on older PHP use Herd's PHP binaries. Install the dependencies for that lane in a throwaway copy of the repo, so the main `composer.lock` isn't touched.

## 7. Performance checks

These are manual, because timing assertions in shared CI runners are flaky. Each check is run on the 1M-row fixture, and the numbers go into the milestone PR.

| Check | Target |
|---|---|
| Rows API, keyset page 1 and page 5000 | < 500 ms |
| Object tree for a schema with 500 tables | < 300 ms |
| EXPLAIN-based hidden-object check overhead | < 20 ms median |
| Export memory (automated, §4) | < 64 MB growth |

## 8. Commands

```bash
composer test                                  # tooling lane: phpstan + pint + type coverage + pest
composer test:unit                             # pest, parallel
vendor/bin/pest --group=pgsql                  # Postgres integration only
vendor/bin/pest --exclude-group=pgsql          # everything that needs no Docker
vendor/bin/pest --filter='S\d+'                # the security suite
vendor/bin/pest --filter=GAP                   # documented gaps
npm run lint && npm run typecheck && npm run build
```

## 9. Definition of done (per task)

- [ ] A failing test was written first and now passes
- [ ] `composer test` is green locally
- [ ] `vendor/bin/pest --group=pgsql` is green locally if the task touches the database
- [ ] Any new S# requirement has both a positive and an adversarial test
- [ ] Any new write path asserts an audit row
- [ ] `dist/` has been rebuilt if frontend source changed
- [ ] The browser smoke checklist has been updated if the task adds a user-facing flow
