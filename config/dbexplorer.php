<?php

declare(strict_types=1);

return [

    //

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
        'connection' => null,

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
