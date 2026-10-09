<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. While false, DbExplorer registers no routes at all, so
    | every URL under the path answers 404. Keep it off in environments that
    | don't need it.
    |
    */

    'enabled' => env('DBEXPLORER_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Path and domain
    |--------------------------------------------------------------------------
    |
    | The URI prefix the explorer is served under, and an optional domain to
    | restrict its routes to (e.g. 'db.example.com'). Null means any domain.
    |
    */

    'path' => 'dbexplorer',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to every DbExplorer route. The IP allowlist runs
    | before these, and the `viewDbExplorer` gate and role resolution run
    | after them. Keep an authentication middleware in this list.
    |
    */

    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | IP allowlist
    |--------------------------------------------------------------------------
    |
    | IPs or CIDR ranges (IPv4 and IPv6) allowed to reach DbExplorer, e.g.
    | ['10.0.0.0/8', '203.0.113.7']. An empty list allows any IP. Behind a
    | proxy, configure the host app's TrustProxies so the client IP is right.
    |
    */

    'ip_allowlist' => [],

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | The only databases DbExplorer can reach. Each entry maps an explorer
    | label to the Laravel connection (from config/database.php) used for
    | each role. Point each role at a different database login with matching
    | privileges, e.g. a SELECT-only user for viewers: the database grants
    | are the real security boundary. A role without a mapping can't use
    | that label. Connection details are never sent to the browser.
    |
    */

    'connections' => [
        'main' => [
            'viewer' => 'pgsql_readonly',
            'editor' => 'pgsql_editor',
            'admin' => 'pgsql',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Per-role limits for interactive queries: `timeout` is the statement
    | timeout in seconds and `rows` the maximum rows returned. `background`
    | holds the timeout in seconds for queued jobs (exports, imports, scripts).
    | `queries_per_minute` feeds the `dbexplorer` rate limiter, applied per
    | user (or per IP for guests) to every per-connection API call.
    | `sql_length` caps the characters of SQL the editor may submit.
    |
    */

    'limits' => [
        'viewer' => ['timeout' => 30, 'rows' => 1000],
        'editor' => ['timeout' => 60, 'rows' => 5000],
        'admin' => ['timeout' => 120, 'rows' => 10000],
        'background' => ['timeout' => 3600],
        'queries_per_minute' => 60,
        'sql_length' => 100000,

        // CSV imports (PRD §7.6): the upload cap in kilobytes, and how many
        // rows each COPY batch streams before checking for cancellation.
        'import' => ['max_upload_kb' => 51200, 'batch' => 5000],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password confirmation
    |--------------------------------------------------------------------------
    |
    | Destructive actions (DROP, TRUNCATE, DELETE or UPDATE without WHERE,
    | deleting a row, ALTER ... DROP) need the user's password re-confirmed
    | within `auth.password_timeout` seconds (Laravel's setting, 3 hours by
    | default). The confirmation is shared with the host app's
    | `password.confirm` middleware. Users without a password, such as
    | SSO-only accounts, can't confirm and so can't run destructive actions.
    | Setting this to false skips confirmation entirely: anyone with a
    | session (including a stolen one) can then run them straight away.
    |
    */

    'confirm_password' => true,

    /*
    |--------------------------------------------------------------------------
    | Hidden objects
    |--------------------------------------------------------------------------
    |
    | Glob patterns for schemas and `schema.table` names to hide from
    | browsing. A pattern without a dot hides whole schemas; `schema.name`
    | hides objects, and `schema.*` hides the schema too. Matching is
    | case-sensitive. System schemas (pg_catalog, information_schema, ...)
    | and objects the database login can't use are never shown anyway.
    | Queries that reference hidden objects are rejected on a best-effort
    | basis only; revoke access in the database for real enforcement.
    |
    | `*.dbx_*` and `dbexplorer.*` keep DbExplorer's own tables (audit log,
    | runs, ...) out of the UI, wherever the storage connection puts them.
    |
    */

    'hidden' => ['pg_toast*', '*.password_reset*', '*.personal_access_tokens', '*.dbx_*', 'dbexplorer.*'],

    /*
    |--------------------------------------------------------------------------
    | Column masking
    |--------------------------------------------------------------------------
    |
    | Rules for columns whose values are masked in grids, edit views and
    | exports. A string is a case-insensitive glob (`*` only) on
    | `schema.table.column`; with N dots it matches the last N+1 parts, so
    | `users.ssn` means that table in any schema and `ssn` any such column.
    | A callable `fn (string $schema, string $table, string $column): bool`
    | adds a custom rule; prefer `[Rules::class, 'method']`, as closures
    | break `config:cache`. Expression columns match by column name only.
    | Aliased columns (`password AS x`) are not masked: use a column REVOKE
    | (docs/security.md). Only admins can unmask, and every unmask is audited.
    |
    */

    'masking' => ['*.password', '*.*token*', '*.*secret*'],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The queue connection and queue name for background work. A null
    | connection uses the app's default queue connection.
    |
    */

    'queue' => ['connection' => null, 'queue' => 'default'],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | `pg_dump_path` is the pg_dump binary to run, `disk` the private
    | filesystem disk backups are written to, and `keep` how many backups to
    | retain per connection label.
    |
    */

    'backup' => ['pg_dump_path' => 'pg_dump', 'disk' => 'local', 'keep' => 7],

    /*
    |--------------------------------------------------------------------------
    | Metadata storage
    |--------------------------------------------------------------------------
    |
    | Where DbExplorer keeps its own tables (dbx_audit_log, dbx_runs, ...) and
    | the files it produces. Pointing "connection" at a separate database keeps
    | the audit log intact even if the explored database is damaged.
    |
    */

    'storage' => [
        // Laravel connection name for the dbx_* tables. null uses the app default.
        // Recommended: a connection whose search_path is a dedicated schema (docs/security.md).
        'connection' => env('DBEXPLORER_STORAGE_CONNECTION'),

        // Filesystem disk for exports and other generated files. Must be private.
        'disk' => 'local',

        // Generated files are pruned by dbx:prune after this many hours.
        'file_ttl_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | Every audit row is HMAC hash-chained to the previous one. Run
    | `php artisan dbx:audit:verify` to detect edited, deleted or reordered
    | rows.
    |
    */

    'audit' => [
        // dbx:prune deletes audit rows older than this many days. null keeps them forever.
        'retention_days' => 90,

        // Log channel that also receives every entry, including its hash. null disables forwarding.
        'log_channel' => null,

        // HMAC key for the hash chain. null falls back to APP_KEY. "base64:" values are decoded.
        'key' => env('DBEXPLORER_AUDIT_KEY'),
    ],

];
