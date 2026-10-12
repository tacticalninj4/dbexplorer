# DbExplorer — Fix List

| | |
|---|---|
| Verified commit | `2c53133` (main, 2026-10-10) |
| Sources | Release verifier (test matrix, traceability, docs), independent security audit, end-to-end browser test |
| Status | 23 fixes **applied** (batches 1–3) + F25 (all) · F24, F27, F33–F35 **applied** · F36–F40 **applied** on branch `fix-f36-f40` (dist `801d351`), awaiting merge · **deferred to v1.1:** F28–F32 · final re-verification: step 1 ✅, step 2 found F36–F40 (now fixed), step 3 pending |

## How to read this

Each finding has an ID, its severity, the evidence for it, the fix, the **reasoning** behind that fix, and a status:

- **APPLIED**: merged into `main`, with the commit listed. Batch 1 was applied and merged before the instruction to write fixes up first. Any of those commits can be undone on its own with `git revert <sha>`.
- **DONE ON BRANCH**: committed and verified on a fix branch, but **not merged into `main`** yet.
- **IN PROGRESS**: work exists only as uncommitted changes in a worktree under `../dbexplorer-worktrees/`. Nothing of it is on `main`.
- **NOT STARTED**: proposal only.

Severity runs from **High** to **Info**. A **High** finding is exploitable or breaks a core promise.

---

## 1. Security and correctness (Batch 1)

### F1 — HIGH — Viewer-authored SQL runs as admin through schedules · APPLIED `523d722`
**Evidence.** The audit's proof of concept ran these steps:
1. A viewer shares the saved query `SELECT 1`.
2. An admin schedules it.
3. The viewer edits the saved query to `DROP TABLE …`.
4. The next run of `dbx:schedule:tick` executes the edited SQL as `admin` on the admin connection, and the table is gone.

`RunScheduledQuery` re-read the saved query's current SQL on every run. It applied no classification gate. `creatorIsAdmin` checked who created the schedule, not who wrote the SQL.

**Fix.**
- **Snapshot and approve:**
  - A schedule now stores its own snapshot of the SQL, typed params and `sql_hash`, plus `approved_by` and `approved_at`. These are added by migration `000006`.
  - Later edits to the saved query don't change the schedule. To pick up a newer version, an admin explicitly re-approves it with `POST schedules/{id}/resync`, which is audited.
  - Each run executes as the **approver**. If the approver is no longer an admin, the schedule is disabled, the event is audited, and `ScheduleFailed` fires.
- **Writes and DDL:**
  - Scheduled runs are **read-only** by default.
  - DML needs `allow_writes` and DDL needs `allow_ddl`. An admin sets these at approval time, and granting either one requires password re-confirmation (S5).
- **Existing rows:** existing schedules are backfilled from their saved query but left **disabled and unapproved**.

**Reasoning.**
- **The snapshot closes the hole.** The bug was a live link from privileged execution to SQL that a less privileged user can still edit. You chose the snapshot over "own queries only" because "own queries only" keeps the live link.
- **The approver is who signed off on the SQL.** Running as the approver ties the privilege to that person, rather than to whoever happened to create the row.
- **Read-only by default** means even a mistakenly approved schedule can't write unless someone explicitly allowed it. At run time there's no user present to re-confirm a password, so the S5 check moves to approval time.
- **Disabling backfilled rows fails safe.** Silently trusting SQL that nobody approved under the new rules would reopen the hole.

**Tests.** The exact proof of concept, now failing to drop anything. Plus: a query made private after scheduling, resync permissions and audit, writes rejected without `allow_writes`, the 423 on approving writes, a demoted approver, and backfilled rows staying disabled.

### F2 — HIGH — Cancel didn't stop background queries or exports · APPLIED `2633dcd`
**Evidence.** In the browser test, cancelling `SELECT pg_sleep(60)` from the Runs page left the run "running". The backend kept sleeping in `FETCH … FROM dbx_cur` and the run only showed "cancelled" after 60 s. The run's `pid` was `NULL`: `RunQuery` and the export path never called `trackBackend()`.

**Fix.**
- **Export path:** `QueryRunner::stream()` takes an `$onBegin` hook, and `ExportsToDisk` uses it to record the pid **before** the cursor opens.
- **Every job type:** `TracksRun` stores the pid before `process()`, which also covers scheduled queries.
- **Tests:** new end-to-end cancel tests for a background query and for an export. Each uses a real `queue:work` child process and asserts the backend is gone within 20 s.

**Reasoning.**
- **Recording the pid first is the only way to cancel mid-statement.** `pg_cancel_backend` needs the backend's pid. If the pid is stored after the long statement starts, or never stored, nothing interrupts it, and a cancel button that doesn't cancel is worse than none.
- **The fix belongs in the shared concern.** Putting it in the concern rather than in each job keeps any future job type from repeating the bug.

**Why the old tests passed.**
- The runs test used a test-only `RunStatements` job, which called `trackBackend()` itself.
- The export test cancelled between chunks through the flag, never through `pg_cancel_backend`.
- So the bug lived exactly in the paths those tests didn't use. The new tests drive the real jobs.

### F3 — MEDIUM — Admin on one label could read every label's audit log · APPLIED `523d722`
**Evidence.** A user who is admin on `main` but has no role on `prod` read `prod`'s audit rows, including a literal secret in the SQL text. `AuditController` checked the role on the URL's label, but the query had no label filter, and `?label=` accepted any label.

**Fix.**
- Rows are limited to labels where the current user is admin.
- Rows without a label need admin on every label.
- `?label=` naming a label outside that set returns an empty page.

**Reasoning.**
- **Audit rows hold SQL text,** which can include literals, so reading them is as sensitive as reading the data itself.
- **Authorization follows the data.** It has to match each row's label, not the label in the URL.
- **An empty page, not a 403,** avoids confirming which other labels exist.

### F4 — MEDIUM — CSV import rejected on PHP 8.0 · APPLIED `798f442`
**Evidence.** On PHP 8.0, finfo reports `application/csv`. The rule `mimetypes:text/csv,text/plain` rejected it, so 5 import tests failed in the L9/PHP 8.0 lane.

**Fix.** Accept `text/csv`, `text/plain`, `application/csv` and `text/x-csv`. `application/vnd.ms-excel` is deliberately left out.

**Reasoning.**
- **These are the MIME strings real libmagic versions report for CSV.** The upload is still parsed as CSV and size-capped, so widening the type list adds no attack surface.
- **`vnd.ms-excel` stays out.** finfo only reports it for binary `.xls` files, which would fail parsing anyway.

### F5 — MEDIUM — Users without a password got a 500 on password confirmation · APPLIED `8becd54`
**Evidence.** On Laravel 10 / PHP 8.3: `RuntimeException: This password does not use the Bcrypt algorithm.` when the stored password is `null`, which is the normal case for SSO-only users. Access was still denied, but with a 500.

**Fix.** A `null` or empty stored password returns the generic 422 for a wrong password, is audited, and never reaches the hasher.

**Reasoning.**
- **No stored password means confirmation can't succeed,** so treating it as a failed confirmation is correct and stays fail-closed.
- **The 500 had two problems.** It's noisy for operators, and on some hasher versions an exception path is the kind of thing that eventually behaves inconsistently.
- **Documented consequence:** SSO and LDAP users can't do destructive actions unless the host sets `confirm_password=false`.

### F6 — MEDIUM — A backup was queued even when its preflight said it would fail · APPLIED `d80b6be`
**Evidence.**
- `GET backups` reported that pg_dump 17 is older than the server's 18, but `POST backups` still queued the job.
- The run then failed with "failed unexpectedly", and the real cause appeared only in the log.
- Separately, the preflight test faked a `pg_dump` 12.9 binary, which isn't older than a Postgres 12 server, so the test would fail in CI's Laravel 9 lanes.

**Fix.**
- `POST backups` returns 422 with the preflight message and queues nothing.
- A new `RunFailed` exception stores the real cause, with connection details redacted.
- The test's fake binary version is now the server's major version minus one.

**Reasoning.** Refusing upfront gives the operator the actionable reason ("pg_dump 17 < server 18") at the moment they ask for the backup, instead of a failed run to dig into later. Redacting the stored error keeps S7, since connection config never leaves the server.

### F7 — MEDIUM (UX) — Rows skipped during import were invisible · APPLIED `422f518`
**Evidence.** In skip mode, bad rows were recorded in `meta.errors`, but neither the runs API nor the Runs page showed them.

**Fix.**
- The runs API exposes `inserted`, `skipped`, and up to 100 `errors` as `{row, error}`. Cell values are never included.
- A transaction-mode failure now reads "Row N: …", with values redacted.

**Reasoning.**
- **A silent skip loses data.** Skip mode is only safe if users can see what was skipped.
- **Row number plus message is enough to act on.** It means the user can fix the source file.
- **No cell values.** Leaving them out means error output can never become a channel for masked or sensitive data.

### F8 — LOW (S12) — Export identifiers weren't escaped · APPLIED `3d3380e`
**Evidence.**
- `ExportController` built `"schema"."name"` without doubling embedded quotes.
- `ExportWriter` wrote table and column names raw into `.sql` INSERT lines.
- A viewer produced an export whose INSERT lines contained an extra statement they chose. Anyone restoring that file, including an admin who downloads label exports, would run it.

**Fix.**
- Both places use the shared `RowsQuery::quoteIdentifier`.
- The table is resolved through `Introspector::rowSource`, so hidden or missing tables get the same 404 as everywhere else.
- **Test:** export a table with hostile table and column names, restore the `.sql` file, and check that exactly the original rows come back.

**Reasoning.**
- **This gave no new read power.** The read-only transaction and hidden-object checks still applied.
- **But it turned an export into a stored-injection vector against whoever restores it.**
- **One quoting helper everywhere** is the S12 rule. This was the one place that didn't follow it.

### F9 — LOW — pg_dump ignored the connection's `sslmode` · APPLIED `955a903`
**Evidence.** A connection configured with `sslmode=require` or `verify-full` still dumped with libpq's default `prefer`, so the dump could travel unencrypted.

**Fix.** Pass `PGSSLMODE`, `PGSSLROOTCERT`, `PGSSLCERT` and `PGSSLKEY` from the connection config in the process **environment**. Nothing goes into the argument list.

**Reasoning.**
- **Transport parity.** A backup should be at least as protected in transit as the app's own connection.
- **Environment variables, not arguments.** Arguments are visible in the process list, which is the same S20 rule already applied to `PGPASSWORD`.

### F10 — Type coverage below 100% · APPLIED `116ed13`, `523d722`
**Evidence.** `composer test:types` reported 98.6%, below the required 100%. The gaps were in `ExportWriter`, `RunsController`, `ImportController` and `ScheduledQueryFinished`.

**Fix.** Native return and parameter types on those methods.

**Reasoning.** CI's tooling lane enforces 100%, so `main` was red. The types are also documentation the static analyser can enforce.

### F11 — `toBeList()` isn't available on Pest 1 and 2 · APPLIED `1b02355`
**Evidence.** The S20 backup test failed in the Laravel 9 and 10 lanes with `Call to a member function toBeList() on array`.

**Fix.** Replace `toBeList()` with an assertion that exists on Pest 1 through 5.

**Reasoning.** Shared tests must use the Pest 1 API subset (TESTING.md §6). The test itself was right; only the matcher wasn't portable.

### F12 — Parallel test workers ran out of memory at 128M · APPLIED `6e2e50e`
**Evidence.** After the batch 1 merges, one parallel worker crashed intermittently at PHP's default 128M.

**Fix.** Parallel workers get 512M.

**Reasoning.** The suite boots a full Laravel app per test plus the arch presets. 128M is too tight for 1,300+ tests, not a sign of a leak. **See F15:** this should become one mechanism shared with serial runs.

---

## 2. Getting CI green (Batch 2)

### F13 — `PruneTest` fails on Laravel 9–11 · APPLIED `c2c93a3`
**Evidence.** 5 tests fail with `Failed asserting that '' contains "pruned 2 expired result files"`, because `Artisan::output()` is empty on Laravel 9–11. They pass on Laravel 12 and 13. This is unchanged since `2c53133`.

**Proposed fix.**
1. Find the root cause first.
2. The leading suspect is the test reading `Artisan::output()` after `$this->artisan('migrate')` in `beforeEach`. In older Laravel versions, the console kernel's last-output buffer belongs to the most recent call, and it's empty when the test-facing `artisan()` mock is used.
3. Then assert through `$this->artisan('dbx:prune')->expectsOutputToContain(...)`, which works on every supported version. Alternatively, assert the side effects (files and rows deleted) and not the wording.

**Reasoning.**
- **Fix the test, not the command, if the command is fine.** The behaviour is correct on all versions; only how the test reads the output differs.
- **Asserting side effects is more robust anyway.** The output text is cosmetic.

**Applied.**
- **Root cause, confirmed:** on Laravel 9–11, a finished `$this->artisan('migrate')` leaves its mocked `OutputStyle` bound in the container. That makes the later `Artisan::output()` empty. Laravel 12 removes the binding, which is why only 12 and 13 passed.
- **Change:** `beforeEach` now migrates with `Artisan::call('migrate')`, as `AuditChainTest` already does.
- **No assertion was changed,** so the test is as strict as before and now true on every version.

### F14 — `AssetTest` charset case · APPLIED `b9d7f73`
**Evidence.** On Laravel 11 / PHP 8.4 the test expects `charset=UTF-8` and gets `charset=utf-8`.

**Proposed fix.** Compare the `Content-Type` case-insensitively, or parse the media type and charset separately.

**Reasoning.** Charset names are case-insensitive by RFC, and Symfony changed its casing between versions. The test should assert meaning, not casing.

**Applied.** All three `Content-Type` checks in `AssetTest` compare the lowercased header, with a comment citing RFC 9110.

### F15 — Serial test runs run out of memory at 128M · APPLIED `38c843e`
**Evidence.** Running `vendor/bin/pest` serially exhausts 128M. F12 fixed only parallel workers.

**Work so far.** `phpunit.xml.dist` gains `<php><ini name="memory_limit" value="512M"/></php>`. It's uncommitted.

**Remaining.**
- Confirm Pest 1–5 and `--parallel` honour the ini setting.
- Then remove the separate mechanism from F12, so there's **one** setting.

**Reasoning.**
- **One setting in `phpunit.xml.dist`** applies to contributors, CI and both run modes.
- **Two mechanisms drift apart.** Fixing parallel runs but not serial ones is exactly how the F12 bug happened.

**Applied.**
- **The setting:** `<ini name="memory_limit" value="512M"/>` is in `phpunit.xml.dist`.
- **F12 superseded:** the parallel-only `--passthru-php` flag from F12 is removed from `composer test:unit`.
- **Verified:** Pest 2–5 parallel workers report 512M. Pest 1 has no parallel mode.
- **Not a leak:** the arch presets take about 55 MB and the peak is about 145 MB. 128M was simply too tight.

### F16 — Arch test needs `intl` · APPLIED `5ca7809`, `bce95e4`
**Evidence.** On images without `intl`, the arch preset fails with `Spoofchecker not found`.

**Proposed fix.**
- Make sure every CI lane's extension list includes `intl`, `pdo_pgsql` and `pgsql`.
- Document `intl` as a dev requirement in TESTING.md.
- Don't change the test.

**Reasoning.** This is an environment requirement, not a code bug. Weakening the arch test to avoid it would lose real coverage.

**Applied.**
- The Linux lanes already had `intl`, `pdo_pgsql` and `pgsql`.
- The Windows lane gains `pdo_pgsql` and `pgsql`, plus `audit.block-insecure false`, matching the other lanes.
- `actionlint` is clean.
- Ubuntu 24.04's PGDG repository provides client 12 and 18, matching the service containers.
- **Docs part:** TESTING.md notes that `intl` is a dev requirement (`bce95e4`, batch 3).

### F17 — Backup preflight test breaks on Postgres 12 · APPLIED (part of `d80b6be`)
See F6. Listed separately because it would have turned CI's Laravel 9 lanes red.

### F26 — Password-timeout test flaked under load · APPLIED `4183940`
**Evidence.** Under heavy load, a second ticked over during `travel()`, so a boundary test in `PasswordConfirmationTest` saw an expired confirmation.

**Applied.** The boundary tests freeze time.

**Reasoning.** A time-boundary test has to control the clock, or it tests the machine's speed instead of the rule.

### Batch 2 verification (branch `fix-b2-ci-green`, 2026-10-11; fast-forwarded into `main` at `4183940`)
Results are passed / skipped / failed.

| Lane | Postgres | Result |
|---|---|---|
| L9 / PHP 8.0 (lowest) | 12 | 1386 / 0 / 0 |
| L9 / PHP 8.2 | 12 | 1386 / 0 / 0 |
| L10 / PHP 8.1 (lowest), L10 / PHP 8.3 | 18 | 1386 / 0 / 0 each |
| L11 / 8.2 (lowest), L11 / 8.4, L12 / 8.3 (lowest), L12 / 8.5, L13 / 8.4 (lowest), tooling lane in Docker | 18 | 1390 / 0 / 0 each, with PHPStan, Pint and 100% type coverage |
| `composer test`, local PHP 8.4 | 18 | 1390 passed |
| pgsql group, local PHP 8.4 | 12 | 494 passed |

Laravel 9 and 10 run 4 fewer tests because the arch tests need Pest 3+.

**Known, not fixed:**
- **Flaky timing tests:** two "1 MB in under 1 s" performance tests in the SQL lexer/splitter suites failed once at a machine load of 12. They're candidates for a looser bound, or for only measuring when the machine is idle.
- **Pest 2.0.1 exit code:** `--parallel` exits 1 even when every test passes. CI doesn't use `--parallel` on that lane, so it only affects local runs pinned to Pest 2.0.1.

---

## 3. Tests and docs (Batch 3)

### F18 — S15 has no cell-value XSS test · APPLIED `92e2131`
**Evidence.** TESTING.md §5 specifies a test with a cell value like `<img src=x onerror=…>`. It didn't exist; only CSP and user-name injection were tested.

**Work so far (uncommitted):**
- `tests/Pgsql/CellXssTest.php` puts payloads in text, jsonb and array columns. It asserts that the rows API and the query API return them byte-for-byte as JSON strings, and that the shell page never embeds row data.
- `ShellTest` gains an assertion that ESLint's `vue/no-v-html` is set to `error`.

**Remaining.** Run it in the pgsql and Laravel 9 lanes, then commit.

**Reasoning.**
- **Server side, the right property is preservation.** Values must survive exactly as JSON strings, not be "sanitised": stripping or escaping on the server would corrupt data and give false comfort.
- **Rendering safety belongs to the SPA.** Text interpolation does that job, and the lint rule enforces it. The test pins down both halves.
- **The browser test already confirmed it in practice.** The payload rendered as text.

**Applied.**
- `tests/Pgsql/CellXssTest.php` checks text, jsonb and array cells through the rows API and the query API.
- `ShellTest` asserts `vue/no-v-html: error`, and that `resources/js` contains no HTML sink (`v-html`, `innerHTML`).

### F19 — The backup test never restores the dump · APPLIED `bf7f580`
**Evidence.** The backup test only lists the dump's contents with `pg_restore --list`. The M4 exit criterion is a backup that round-trips through a restore.

**Work so far (uncommitted):**
- `BackupTest` gains a round-trip test: dump a scratch schema through the backup job, `pg_restore` it into a fresh database, and compare row counts.
- `tests/Pgsql/scripts/docker-pgrestore.sh` runs `pg_restore` inside the `dbx-pg` container. That lets the test run locally, where Postgres.app's tools are version 17 and the server is 18.

**Remaining.**
- The test must **skip cleanly**, with a reason, when the client tools' major version is older than the server's.
- CI installs a matching `postgresql-client`, so it runs there.

**Reasoning.**
- **A backup you can't restore isn't a backup.** Listing the contents proves the file is an archive, not that it restores.
- **The Docker wrapper is a test-only convenience.** It isn't shipped, since `tests/` is export-ignored. It mirrors an existing `docker-pgdump.sh` pattern, so local runs match CI.

**Applied.**
- **The round trip:** backup job → `pg_restore` into a fresh database → compare row counts, contents, the view and the sequences.
- **Where it runs:**
  - Locally on Postgres 18, using the container's tools.
  - In the L9 lane on Postgres 12, with client 13.
- **When it skips:** if the client is older than the server (client 13 against Postgres 18), it skips with the preflight's reason.

### F20 — `ScheduleFailed` had no no-leak test, and failed schedules weren't re-run · APPLIED `523d722`
**Evidence.**
- `ScheduleFailed` had no assertion that its payload is clean or that it fires after the audit row.
- Nothing checked that a schedule runs again on its next tick after a failure, and the job didn't release its lock on failure.

**Fix.**
- Payload and ordering tests.
- The job releases its lock, and a test shows a failed schedule runs on the next tick.

**Reasoning.**
- Every event promises the PRD's no-leak payload and must fire after its audit row. This was the only event not tested for that.
- A lock that isn't released turns one failure into a permanently dead schedule.

### F21 — Docs don't match the code · APPLIED `a1f63ea`
**Evidence (from the verifier):**
- **README:**
  - The "Pre-release … planned" banner contradicts the Roadmap's "Built".
  - Stale *(planned)* markers on the queue worker, `limits.background.timeout`, `hidden`, `masking`, `queue.*`, `storage.disk` and `storage.file_ttl_hours`.
  - `dbx:schedule:tick` is missing from the commands table.
  - `dbx:prune` is described as pruning audit rows only.
  - `limits.import.*` is undocumented.
- **docs/security.md:**
  - "Masking misses aliases *(planned, M1)*".
  - Active sessions are marked "planned".
- **Plan and TESTING.md:**
  - They reference `Feature/RunJobTest`; the actual file is `RunsTest`.
  - The plan lists `/api/runs…` and `/api/audit`; the actual routes are under `api/{label}/`.
  - The plan says another user's download gets 403; the code returns 404.
- **PRD and CHANGELOG** say PHP 8.0–8.4, but CI tests 8.5.
- **The batch 1 changes are undocumented:**
  - the schedule snapshot semantics
  - the audit label scope
  - `POST backups` returning 422
  - the `PGSSL*` environment variables
  - the import error fields
  - background queries using `limits.background.timeout`
  - users without a stored password being unable to confirm

**Proposed fix.**
1. **Generate the README tables from the code:** the config table from `config/dbexplorer.php`, and the commands table from `testbench list dbx`.
2. **Correct the other mismatches.**
3. **Put the batch 1 changes in CHANGELOG:**
   - 1.0.0 isn't tagged, so they go under `Unreleased`.
   - Nothing should be tagged until verification passes again.
4. **Update PRD §7.9 with the schedule semantics.**
5. **Mark the M3/M4 UI as "API available; UI coming"** until batch 4 lands. See F24.

**Reasoning.**
- **This is a security package, so the docs are part of the security model.** Hosts configure grants and hidden/masking rules based on what the docs say.
- **Claiming features are built when they're API-only misleads users.**
- **Generated tables can't drift.**

**Applied.**
- **README:**
  - The status banner is fixed.
  - A roadmap separates what has UI from what is API-only.
  - The config and commands tables match the code, enforced by the new `Feature/DocsTest`, which fails when they drift.
  - There are no stale *(planned)* markers.
- **CHANGELOG:** all changes are under `Unreleased`, and the PHP range is 8.0–8.5.
- **PRD:** §7.9–7.12 describe what was built, including the schedule snapshot semantics.
- **Plan and TESTING.md:**
  - Routes are under `api/{label}`.
  - Another user's download returns 404.
  - `RunsTest` is the correct file name.
  - The S# test map is updated.
- **security.md and the role recipe:**
  - Session cancel and terminate only signal the same login, so the recipe no longer suggests `pg_signal_backend`. It does suggest an optional `pg_read_all_stats`, so the sessions list can show other logins.
  - The `PGSSL*` variables and the backup 422 are documented.
- **Code comments:** PRD section numbers are corrected. These are comment-only changes.

**Unreconciled:**
- `DdlForm` has no constraint forms, so constraints are documented as API-only.
- The untagged `v0.1.0` CHANGELOG stub was left as it is.

### F22 — `dbx:prune` is scheduled twice · APPLIED `19c8e48`
**Evidence.** The service provider already schedules `dbx:prune` daily, and the README and `dbx:install` output also tell the host app to schedule it. Following the docs runs it twice a day.

**Proposed fix.**
- Remove the instruction from the README and from `InstallCommand`'s output, and update the test.
- The host only needs the standard `schedule:run` cron and a queue worker.
- Verify that the provider registers both `dbx:prune` and `dbx:schedule:tick`.

**Reasoning.** It's harmless today, since pruning is idempotent. But double runs can overlap on large audit tables. And the less the host has to wire up, the fewer ways to misconfigure it.

**Applied.**
- **`dbx:install` and the README** now say the provider schedules `dbx:prune` (daily) and `dbx:schedule:tick` (every minute). The host only needs the `schedule:run` cron and a queue worker. The daily `dbx:audit:verify` stays the host's choice.
- **Tests:** `ServiceProviderTest` pins down both scheduler entries, and `InstallCommandTest` checks the new output.

### F23 — The S11 wording in TESTING.md overstates the guarantee · APPLIED `315d963`
**Evidence.**
- As an editor, `SELECT fixture.sneaky()` is classified as a read and performs its write without any confirmation prompt.
- That's correct, because editors have write grants, and docs/security.md says the classifier is UX only.
- But TESTING.md's S11 wording implies the database rejects this case.

**Proposed fix.** Reword TESTING.md S11:
- The database rejects what the role's grants forbid: viewer writes, and editor DDL.
- The classifier only drives confirmation prompts, so a function with side effects called by a role allowed to write will run without a prompt.
- Point to the security.md section.

**Reasoning.** The behaviour is the intended design. Only the wording promises more than the system does, and overstated guarantees in a security doc are a defect in their own right.

**Applied.** S11 is reworded consistently in TESTING.md, the plan, and security.md §4.

### Batch 3 verification (fast-forwarded into `main` at `a1f63ea`, 2026-10-11)
| Run | Result |
|---|---|
| `composer test` on `main` (PHPStan, Pint, 100% type coverage, Pest) | 901 passed, 498 pgsql tests skipped (no database env) |
| pgsql group on `main`, Postgres 18 | 498 / 498 passed |
| L9 / PHP 8.0 (lowest), Postgres 12, on the branch | 1393 passed. The 2 dump tests skip because that image has no `pg_dump`. |

The full Laravel 9–13 matrix last ran at `4183940`, and batch 3 adds only tests, docs and comments. It runs again in the final verification (§7, step 4).

---

## 4. UI (Batch 4)

### F24 — HIGH (scope) — Most M3/M4 features have no screens · APPLIED (batch 4, 2026-10-12)
**Evidence.**
- The SPA has no screens for:
  - export
  - run in background
  - CSV import
  - scripts
  - saved queries
  - schedules, including the new approve/resync flow with `allow_writes`/`allow_ddl`
  - backups
  - active sessions
  - the audit viewer
  - the create-table and create-view forms
  - the add/drop constraint forms, which batch 3 found `DdlForm` doesn't have
- All of these work through the JSON API, which the browser test exercised from inside the logged-in page.
- The README and CHANGELOG say they're "Built", and the TESTING.md §2.5 M3/M4 browser checklist can't be followed in the UI.

**Proposed fix.**
- **Screens:** build them on the existing components (`DataGrid`, `ModalDialog`, `PasswordConfirmModal`, the Runs page). They also need to show F7's import errors and the script per-statement results on the Runs page.
- **Ordering:** your uncommitted UI motion polish gets reviewed (lint, typecheck, a byte-identical `dist/`) and committed first, so batch 4 builds on top of it. You chose this option, but it's held while the doc-first instruction stands.

**Reasoning.**
- **Most of the product's value is unreachable without screens.** A phpMyAdmin replacement isn't one if exports, imports, backups and sessions need curl.
- **Building after batches 1–3 means the screens target the corrected API.** That's the fixed schedule flow and the import error fields.

### F25 — LOW — Smaller UI and API issues · APPLIED (a, b, e in the UI polish; c, d in batch 4C, `b88e402`, `ce14223`)
| # | Issue | Proposed fix | Reasoning |
|---|---|---|---|
| a ✅ | The DDL form's "Queued as run #N" notice never appears, because the dialog closes first | Show it as a toast or banner that outlives the dialog, linking to the run | The user otherwise doesn't know a background job started (`CONCURRENTLY`) |
| b ✅ | After dropping a table, the page stays on the 404 and the tree still lists the table | After a successful DDL execute, invalidate the tree cache and navigate to the schema | Stale navigation makes a successful drop look failed |
| c ✅ | After a save conflict, the only option is "reload" | **Decided (2026-10-11): yes, explicit and audited.** Show a diff of your values against the current row, plus an "Overwrite with mine" button that re-issues the update with the current row as the new `original`. It's still conflict-checked. | Clobbering is legitimate when it's a deliberate choice. The 409 stays the default safety net. |
| d ✅ | `POST rows` with no values inserts a row of defaults (201) | Require at least one value or an explicit `defaults: true` (422 otherwise) | Inserting an all-defaults row by accident, for example from an empty form, is a surprising write |
| e ✅ | The editor shows "5 rows" for a `DELETE` with no result set | Show "N rows affected" for statements with no result set | Mixing up rows *returned* and rows *affected* is misleading after destructive SQL |

---

### Batch 4C — applied (`b88e402` … `86730e6`, dist `5bcf77e`, 2026-10-12)
- **Schema forms (admin only):**
  - Create table, view and materialized view. These open from the schema context menu in the tree (right-click, Menu key or Shift+F10), from the tree's "+" button, and from the schema rows on the connection overview.
  - Add and drop constraints on the Constraints tab. Drop is styled destructive and needs password re-confirmation.
  - A Refresh button on materialized views.
  - Every form previews the server-built SQL, with a classification badge per statement and a raw-expression warning. The client never builds SQL itself.
- **F25c:** a 409 shows each field as loaded, as yours, and as it is now.
  - "Reload row" starts again from the current row.
  - "Overwrite with mine" re-sends only your edits, using the conflict's `current` row as `original`, so it's still conflict-checked.
  - The audit entry gets `meta.overwrite: true`. That marker is informational: the client sets it, and it changes no check.
- **F25d:** `POST rows` with no values returns 422 unless `defaults: true` is sent. The UI only sends it from an explicit "Insert a row with all defaults" action.
- **Fixed along the way:** the constraints endpoint returns 404 for views, which threw an uncaught error on every view's Data tab. The UI now handles it.
- **Verification:**
  - lint, typecheck, build and `composer test` pass
  - the pgsql group passes 505/505
  - on Laravel 9 / PHP 8.0, the RowWriter and Feature suites pass
  - every flow passed in the browser, with no console errors or CSP violations
  - screenshots and GIF are in `~/Downloads/dbx-batch4/4c/`

### Batch 4B — applied (`7ec6379` … `8b42c38`, 2026-10-12)
- **New screens:**
  - **Saved queries** (`/:label/queries`, every role): typed `:param` editor, run with a typed form, and a "Save query" button in the SQL editor.
  - **Schedules** (admin): the snapshot SQL read-only, `allow_writes`/`allow_ddl` toggles that ask for the password, a resync diff before re-approving, approval of backfilled schedules, and a plain-language preview of the cron expression.
  - **Backups** (admin): Create is disabled with the preflight's reason when it fails. Restore is shown only as a CLI command to copy.
  - **Active sessions** (admin): cancel, and terminate with password re-confirmation.
  - **Audit viewer** (admin): filters and a detail drawer.
- **New shared components:** `SqlCodeEditor`, `ConfirmDialog`, `DrawerPanel`, plus cron and diff helpers.
- **No PHP changes.**
- **Verification:**
  - lint, typecheck, build and `composer test` pass
  - the pgsql group passes 505/505
  - every flow passed in the browser; the only console noise is an existing favicon 404
  - screenshots and GIF are in `~/Downloads/dbx-batch4/4b/`

### Batch 4A — applied (`da4b8c8` … `3e70b39`, 2026-10-12)
- **Export:** an export dialog from the Data tab and from SQL results, in CSV, JSON Lines or SQL, with masking applied and download through the signed URL.
- **Background runs:** "Run in background" in the SQL editor, with inline progress and cancel.
- **CSV import** (`/:label/import`, editor and up): a wizard covering upload, preview, column mapping and the import mode, then a result listing the skipped rows by row number and message.
- **Scripts** (`/:label/scripts`, editor and up): transaction and step modes, per-statement results, cancel, and the 409 and 423 flows. It uses the shared `SqlCodeEditor`.
- **Runs page:**
  - status and type filters, expandable import and script details, a `?run=N` deep link, cancel and download
  - polling now keeps going every 2 s; before, it stopped after the first tick
  - global run toasts
- **Shared code:** `composables/runs.ts`, `RunPanel`, `RunDetails`, `RunStatusBadge`, `RunToasts`, `ExportDialog`, and a `SqlConfirmDialog` built on 4B's `ConfirmDialog`.
- **No PHP changes.**
- **Verification:**
  - lint, typecheck, build and `composer test` (1406 tests) pass
  - the pgsql group passes 505/505
  - every flow passed in Chrome before and after merging `main`, with no console errors or CSP violations
  - **not covered in the browser:** editor and viewer role gating, because the workbench only grants admin. The final re-verification covers it.
  - screenshots and GIF are in `~/Downloads/dbx-batch4/4a/`

### F32 — LOW — API gaps the 4A screens work around · NOT STARTED
| # | Gap | Proposed fix | Reasoning |
|---|---|---|---|
| a | Exports can't apply the grid's filters or sort, and the dialog says so | Accept the same validated `sort` and `filters[]` as the rows API, reusing `RowsQuery` so the S12 allowlists apply | Users expect "export what I see". Reusing the rows builder means no second filter parser. |
| b | The import size limit isn't exposed, so the UI relies on the server's 422 | Expose `limits.import.max_kb` (no secrets) through `/api/me`, or the import preview endpoint | It lets the UI reject an oversized file before uploading it |
| c | Run timestamps are whole seconds, so short runs show "under 1 s" | Store `duration_ms` on runs, or use millisecond timestamps | It's cosmetic, but it makes timing of quick jobs legible |
| env | Every worktree shares one `jobs` table, so one worktree's queue worker took another's exports | Dev only: give each workbench its own queue table or database. It can't happen in a real install. | It confused parallel agent testing |
| — | 4B's `BackupsView` polls runs itself instead of using `RunPanel` | Move it onto `RunPanel` | One polling implementation, not two |

### F28–F31 — LOW — API gaps the 4B screens work around · NOT STARTED
| # | Gap | Proposed fix | Reasoning |
|---|---|---|---|
| F28 | Backups take no format or schema/table options, although PRD §7.10 promises them. The screen shows "custom, whole database". | Accept a `format` (`custom`/`plain`) and `schemas[]`/`tables[]`, all validated, and pass them as an argument array: each table name becomes a separate `--table=` value, quoted, never interpolated into a string (S20) | Partial dumps are a common need. Passing names as array arguments keeps the S20 argument-injection protection. |
| F29 | Schedules have no next-run field (the UI estimates it, assuming UTC when no timezone is set), and the runs API can't filter by schedule | Return `next_run_at` computed server-side with `CronExpression`, and add `?schedule=` to the runs API | The server's scheduler is the source of truth. A browser estimate can disagree across timezones. |
| F30 | The audit API doesn't return `hash` or `prev_hash` | Add both to the admin-only audit response | Admins can then spot-check the chain against `dbx:audit:verify` output. The hashes aren't secret, since they're HMACs of rows the admin can already read. |
| F31 | `/api/me` has no user id, so the schedule approver shows as "user N" | Add `user.id` and resolve approver names server-side (`approved_by_name`) | The approver is the person whose rights the job runs with, so the UI should name them clearly |

### F27 — MEDIUM — Materialized view refresh ran inside the HTTP request · APPLIED `a2367c9`, `7d3d4b5`
**Evidence.** Batch 4C found the refresh runs synchronously, although `DdlController`'s comment says it's handed to a background run. Views are always created with `CREATE OR REPLACE`, so there's no toggle for it.

**Proposed fix.** Send `REFRESH … CONCURRENTLY`, which can't run inside a transaction, through the same background-run path as `CREATE INDEX CONCURRENTLY`. The UI's queued-run notice is already wired for it. Then fix the comment.

**Reasoning.**
- A large materialized view can take minutes to refresh. Inside a request it hits PHP and proxy timeouts and holds a worker.
- This is exactly what the PRD's "long-running work goes to the backend" principle (§7.5) forbids.

**Correction (found while fixing).** The original premise was wrong. `REFRESH … CONCURRENTLY` *can* run inside a transaction; this was verified on Postgres 18. The real bug was routing:
- `DdlBuilder` marked refreshes as `transactional`.
- The controller only sent *non*-transactional statements to the background.
- So every refresh, plain or concurrent, ran inside the request.

**Applied.**
- **The routing:**
  - `DdlBuilder` statements carry a new `background` flag. It's set for non-transactional statements (`CREATE`/`DROP INDEX CONCURRENTLY`) and for **every** materialized view refresh.
  - `DdlController` queues any operation that contains a `background` statement as a script run and returns 202 with the run, the same path as `CREATE INDEX CONCURRENTLY`.
  - Refreshes run in transaction mode; non-transactional index statements run in step mode.
- **What the queued run keeps:** admin-only access, the audit entry, `DdlExecuted` on success, the pid tracked before the statement so cancel works, and `limits.background.timeout`.
- **Plain refresh goes to the background too.** It re-runs the whole query and holds an `ACCESS EXCLUSIVE` lock that blocks every reader while it runs.
- **UI:** the preview badge and the notice wording follow the `background` flag, and the notice links to the run.
- **Tests:** the queued 202 (nothing refreshed in the request), the job refreshing the view with audit and `DdlExecuted`, a 403 for viewer and editor, and cancel mid-refresh with a real worker. They pass in `composer test`, the pgsql group (1414) and the touched suites on L9/PHP 8.0.

### F33 — HIGH (CI) — A fresh install pulls an incompatible `paratest` · APPLIED `5476e74`
**Evidence.** While fixing F27, `composer test` in a fresh worktree failed after `composer install` resolved a newer, incompatible `brianium/paratest`, which Pest pulls in for parallel runs. It passed only after copying `main`'s `composer.lock` in. `composer.lock` is gitignored, as is normal for a package, and CI runs `composer update`, so **CI's tooling lane, and any contributor's fresh clone, may now fail.**

**Proposed fix.**
1. Reproduce from a clean clone and identify the paratest/Pest/PHPUnit combination that breaks.
2. Add the narrowest dev constraint that excludes the bad version, or bump Pest and plugins to versions that support it.
3. Re-run every lane from a clean install, with no lock file.

**Reasoning.**
- A library's tests have to pass from a fresh resolve, because that's exactly what CI and contributors get.
- A dev-only constraint fixes it without touching what host apps install.
- It's in the final verification's scope either way, because the matrix has to run from clean installs.

**Root cause (final verification step 1).** `paratest` 7.26.1, released 2026-10-10, added a fifth `WrapperWorker` constructor argument that Pest 5.3.1 doesn't pass. That breaks `--parallel` on PHP 8.4+ (local `composer test`). CI lanes run serially and weren't affected.

**Applied.** `require-dev` gains `"brianium/paratest": "^6.0 || ^7.0 <7.26.1"`. The `^6.0` alternative stops the prefer-lowest Pest 1 lane from resolving `paratest` v0.1.0, a 2013 release.

### F34 — MEDIUM (CI) — Timing tests flaked under load · APPLIED `f79bba2`
**Evidence.** In clean-install lanes, the lexer/splitter "1 MB in under 1 s" tests took 2.7 s on a loaded machine, against about 0.3 s idle. An S10 statement-timeout test failed once.

**Applied.**
- **Performance tests:** a `linearScaling()` helper times the work on 1 MB and on 100 KB, best of three, using process CPU time (wall time on Windows). It asserts the ratio is under 30, where linear scaling is about 10 and quadratic about 100, and keeps a 5 s backstop.
- **Statement-timeout tests:** they sleep 30 s against the 1 s limit and assert SQLSTATE 57014 within 20 s.

**Reasoning.**
- Wall-clock bounds measure the machine, not the algorithm.
- The ratio check is *stricter* than the old bound: a deliberately quadratic tokenizer that passed the old 1 s check scores 56–108 here. The real lexer scored 9.3–12.2 at load averages of 30–60.
- Ten loops under deliberate load all passed.

### F35 — LOW — Backup round-trip failed instead of skipping on mismatched clients · APPLIED `c729420`
**Evidence.** `pg_restore` 17 and later emits `SET transaction_timeout`, which servers before 17 reject. Testing locally with a client 18 image against Postgres 12 failed the test instead of skipping it.

**Applied.** The test skips with a stated reason when the client's major version is 17 or later and the server's is earlier than 17. Matching combinations still run, for example client 12 against Postgres 12 and anything against Postgres 18.

**Reasoning.** A test environment's tool mismatch isn't a product failure. The restore path stays covered wherever the versions are compatible, which includes CI.

### Final verification — step 1 (test matrix) ✅
- **Clean installs, no lock file:**
  - L9 / PHP 8.0 lowest on Postgres 12: 1410 passed
  - L10 / PHP 8.3 on Postgres 18: 1410 passed
  - tooling lane L13 / PHP 8.5 on Postgres 18: PHPStan, Pint and 100% type coverage pass, with 1414 passed both serially and in parallel
- **Rest of the matrix:** it ran green before F33–F35. Its only failures were the load-related ones and a test-setup issue, both now fixed.
- **Frontend:** checks pass, with a byte-identical `dist/`.
- **Coverage:** every S1–S22 has its tests, all five events have no-leak tests, and F25c, F25d and F27 are tested.
- **Docs:** they describe the batch 4 screens as built (`649369f`).

## 4b. Final verification — step 2 (security audit, `115ee7a`, 2026-10-12)
No critical or high findings.

**Previous fixes:**
- **F1** holds against a saved-query edit, a non-admin resync, IDs from another label, approver demotion, allow flags set without a password, and backfilled rows. The one bypass is the race in F36.
- **F2** holds for queries, exports, imports, scripts and scheduled runs. Backups aren't covered; see F39.
- **F3–F8** hold.
- **F9** holds, apart from F40a.

**Verified solid:**
- authorization on every route
- `overwrite` and `defaults` don't bypass any check
- destructive DDL hits the 423 before anything is queued
- no `v-html` or `innerHTML`, a nonce-only CSP, CSRF on every call
- every job resets its session and re-checks the role
- no leaks in any response

### F36 — MEDIUM — A schedule approval isn't tied to the SQL the admin reviewed (F1 variant) · APPLIED `f8aa170`
**Evidence.**
- `ScheduleController::store` and `resync` copy the saved query's SQL as it is *at request time*.
- The UI's create dialog and resync diff show the copy loaded with the page.
- If the owner, a viewer or editor, edits the saved query between page load and approval, the admin approves SQL they never saw. That's possible with `allow_writes` too, and the query then runs as the admin.
- **Proof of concept:** the admin reviewed `DELETE FROM fixture.posts WHERE id = -2`. The schedule approved `DELETE FROM dbx_audit_log WHERE true`.

**Proposed fix.**
- The client sends the `sql_hash` it showed with create and with resync.
- The server compares it with the saved query's current hash under the same transaction, and returns 409 "the saved query changed; review again" when they differ.
- The UI re-fetches the query and shows the new diff.

**Reasoning.**
- Approval only means something if it binds to exactly what was reviewed. That's the same principle as F1: an approver signs off on specific SQL.
- A hash from the client is the standard optimistic check, the same pattern as the row editor's 409.

**Applied.**
- `POST schedules` and `POST schedules/{id}/resync` **require** `sql_hash` (a lowercase sha256), so a client can't skip the check: missing or malformed is a 422.
- In one transaction the server locks the saved query's row (`lockForUpdate`), compares the hash with its current SQL, and answers 409 "the saved query changed" with nothing approved or audited. The lock keeps an edit from landing between the check and the copy. The hash check runs before the classification checks, which would otherwise describe SQL the admin never saw.
- The saved queries API returns `sql_hash`. The create dialog and the re-sync diff send it; on 409 they reload the saved queries and show the current SQL, or the new diff, to review again.
- Tests: the proof of concept for create and re-sync (409, no schedule, snapshot unchanged, no audit row), approving the new hash afterwards, and missing, empty, malformed, upper-case and non-string hashes.

### F37 — MEDIUM — Any viewer can flood the background queue, including the host app's queue · APPLIED `d09f761`
**Evidence.**
- There's no limit on queued or running runs per user. The only brake is the 60-a-minute rate limit.
- `queue.connection` defaults to the host's default queue.
- Each run can hold a worker for up to `background.timeout` (3600 s).
- **Proof of concept:** one viewer queued 50 `pg_sleep` runs. That starves the host app's own jobs, and big exports can fill the disk.

**Proposed fix.**
- A per-user, per-label cap on active runs (queued plus running). Default 3, configurable as `limits.background.max_active_per_user`. Above it, return 429 with a clear message.
- Optionally, a global cap per label.
- Document a **dedicated queue** as the recommended setup (`queue.queue = dbexplorer`, with its own worker).

**Reasoning.**
- A database explorer must not be able to take down the host application. The host's queue is a shared resource.
- A cap per user stops one account from monopolising it.
- A dedicated queue isolates the blast radius even when the caps allow work.

**Applied.**
- `limits.background.max_active_per_user`, default 3, with `0` turning it off. It's enforced in `Run::queueFor`, which every user-initiated run goes through: background queries and saved-query runs, exports, imports, scripts, background DDL and backups. Above the cap the request gets a 429 whose message names the cap. Scheduled runs are created by the tick and don't count. No global per-label cap.
- The count and the insert run under a cache lock per user and label, the same mechanism as the schedule tick's lock, so a burst of parallel requests can't all pass the count. It works on any storage connection, where a row or advisory lock would be driver-specific. If the lock can't be had within 5 s, that's a 429 too.
- README (config table and worker section) and docs/security.md §2 document the cap and recommend `queue.queue = 'dbexplorer'` with its own worker. The SQL editor and Scripts screens title a 429 "Limit reached" and show the server's message.
- Tests: three endpoints up to the cap then a 429 with nothing queued; which runs count (other types do; finished runs, scheduled runs, other users and other labels don't); a finished run frees a slot; the config value and `0`; a backup past the cap.

### F38 — LOW — A viewer can put a phishing link in schedule emails · APPLIED `5376b35`
**Evidence.**
- `ScheduledQueryFinished` puts the saved query's *live* name into the email subject and a Markdown line.
- Any owner can rename the query to `[Reset password](https://evil)` without triggering a resync.
- **Proof of concept:** the rendered email contains `href="https://evil.example/reset"`.

**Proposed fix.**
- Snapshot the name with the SQL at approval time, as in F1/F36.
- Escape Markdown in every user-supplied string in notifications, or render those strings as plain text.

**Reasoning.**
- An email arriving from the company's own system is trusted.
- User-controlled text in it must not become a link, and must not change after an admin approved the schedule.

**Applied.**
- Migration `000007` adds `dbx_schedules.name` and fills it from each saved query's current name. `Schedule::approve()` copies the name along with the SQL on create and re-sync, and the job and the schedules API use that copy.
- `ScheduledQueryFinished` Markdown-escapes the name and the error and folds them onto one line. The mail template already escapes HTML.
- Tests: a rename to `[Reset password](https://evil.example/reset)` after approval is mailed under the approved name; the rendered mail for a malicious name and error has no `href` and shows the text literally (this test fails without the escaping); the backfill.

### F39 — LOW — Cancel doesn't stop a backup (F2 gap) · APPLIED `9e4624c`
**Evidence.**
- `Jobs/Backup.php` waits on the `pg_dump` process without ever checking for cancel.
- `pg_cancel_backend` hits PHP's idle connection, not `pg_dump`, so the dump runs on and the run can end "succeeded".

**Proposed fix.**
- Run the process with a loop that checks for cancel.
- On cancel, call `$process->stop()`, which ends `pg_dump` and its backend, delete the partial file, and mark the run cancelled.

**Reasoning.** Cancel has to work on every run type, or users stop trusting it. A partial dump left behind would also look like a valid backup.

**Applied.**
- `BackupTool::start()` starts `pg_dump` without waiting. The job polls every 0.5 s for the cancel flag and the process timeout.
- On cancel it calls `$process->stop(5)`, sending SIGTERM and then SIGKILL, and throws `RunCancelled`. A cancelled or timed-out dump's file is deleted.
- Tests: a real queue worker runs a fake `pg_dump` that writes a partial file and hangs. A cancel through the API ends the run cancelled within seconds, with the file gone, the process gone and `run.cancel` + `run.cancelled` audited. This test fails without the fix.

### F40 — LOW — Two smaller issues · APPLIED `606d640` (a), `c576621` (b)
| # | Issue | Proposed fix | Reasoning |
|---|---|---|---|
| a | `BackupTool::command` drops a port configured as an integer, so `pg_dump` falls back to 5432. On a host with several clusters that's a **different server**, and it receives the admin password. | Cast scalar config values to strings, and add a test with an integer port | The admin password should never reach a server other than the one that was configured |
| b | SQL editor and Scripts tabs are saved in `localStorage` under keys with the label but not the user, and they survive logout. On a shared browser, the next user sees the SQL text, including any literals. | Include the user id in the key, and clear DbExplorer's keys when a 401/419 sends the user back to login | SQL can contain secrets as literals, and browser storage outlives the session |

**Applied.**
- **a:** `command()` passes `host`, `port` and `username` when they're strings or integers. Tested with an integer port and a string port.
- **b:** The shell passes `user.storageKey`, the first 16 hex digits of an HMAC of the user id under the app key. That way the raw id never appears in the page or in key names. Keys are now `dbexplorer:u:<storageKey>:…`. A 401/419 clears every `dbexplorer:` key before the reload, and blocks a debounced save that is still pending. Label-only keys from before are deleted on load. The SPA has no logout of its own: the host's logout ends the session, and the next API call answers 401/419. Tested: the key is in the shell config and differs per user.

**Verification (branch `fix-f36-f40`).**
- `composer test` (L13 / PHP 8.4 / Pest 5, Postgres 18): PHPStan, Pint, 100% type coverage, 1436 passed.
- pgsql group, run serially on Postgres 18: 517 passed.
- L9 / PHP 8.0 prefer-lowest (Laravel 9.47.0, Pest 1) on Postgres 12.22, clean install with no lock file: full suite 1430 passed, 2 skipped. The skips are the real dump and restore tests, because the image has no `pg_dump`.
- Frontend: lint and typecheck pass, and `dist/` is rebuilt byte-identical.

## 5. Environment notes (no code fix)

| Note | Action |
|---|---|
| The Chrome extension wasn't connected during the browser test, so headless Chrome was used instead | None. The GIFs and screenshots are in `/tmp/wt-e2e-tools/`. |
| Local `pg_dump` is 17 (Postgres.app) and the server is 18, so a successful backup couldn't be tested end to end | Covered by F19's container wrapper. Alternatively, upgrade Postgres.app. |
| The workbench doesn't hide `fixture.secrets` the way the test config does | Optional: mirror the test's `hidden` and `masking` config in `WorkbenchServiceProvider`, so manual testing matches |
| The workbench's `failed_jobs` table lives on SQLite | Optional: put it on the Postgres workbench database |
| Docker images `dbx-verify-php80`…`85` are kept for re-running lanes | Remove them when they're no longer needed |

## 6. Accepted known gaps (documented, not fixes)

The verifier confirmed these are pinned by `GAP:` tests and documented in docs/security.md:
- **S13:** a function that reads a hidden table isn't caught.
- **S13:** statements that EXPLAIN rejects, such as `CREATE VIEW`, aren't checked for hidden tables.
- **S13:** view definitions name the hidden tables they read.
- **S14:** an aliased masked column isn't masked. The PHP Postgres driver doesn't expose the source column, so column-level `REVOKE` is the real control.
- **S22:** deleting the newest audit rows goes undetected if `dbx_audit_head` is rewritten too. Forwarding to a log channel (S19) closes that.

## 7. Next steps (awaiting your go-ahead)
1. ~~Batch 2 (F13–F16, F26)~~: merged into `main` at `4183940`. Next: finish F18 and F19 from `wt-b3`.
2. ~~F18, F19, F21–F23 (batch 3)~~: merged into `main` at `a1f63ea`.
3. ~~UI polish and batch 4 (F24, F25)~~: merged into `main`.
3. Commit your UI work, then do F24 and F25 (screens).
4. Run the full verification again (the same three checks) before any tag.
