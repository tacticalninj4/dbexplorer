<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Security;

use Illuminate\Support\Str;

/**
 * Matches schemas and objects against the `dbexplorer.hidden` globs (S13).
 *
 * - A pattern without a dot matches schemas: `pg_toast*` hides every schema
 *   starting with pg_toast and everything in it.
 * - `schema.name` matches objects, each half as its own glob, split at the
 *   first dot: `*.dbx_*` hides dbx_audit_log in any schema.
 * - `schema.*` also hides the schema itself, since nothing in it would show.
 *
 * Matching is case-sensitive, like quoted PostgreSQL identifiers.
 *
 * System schemas (pg_catalog, information_schema, pg_toast*, pg_temp_*) don't
 * need patterns: the introspector never lists them.
 */
class HiddenObjects
{
    /**
     * @var list<string>
     */
    protected array $patterns;

    /**
     * @param  array<mixed>|null  $patterns  the globs, or null for `dbexplorer.hidden`
     */
    public function __construct(?array $patterns = null)
    {
        $patterns ??= (array) config('dbexplorer.hidden', []);

        $this->patterns = array_values(array_filter($patterns, fn (mixed $pattern): bool => is_string($pattern) && $pattern !== ''));
    }

    /**
     * Whether the schema (when $name is null) or the object $schema.$name is hidden.
     */
    public function isHidden(string $schema, ?string $name = null): bool
    {
        foreach ($this->patterns as $pattern) {
            if (! str_contains($pattern, '.')) {
                if (Str::is($pattern, $schema)) {
                    return true;
                }

                continue;
            }

            [$schemaPattern, $namePattern] = explode('.', $pattern, 2);

            if (! Str::is($schemaPattern, $schema)) {
                continue;
            }

            if ($name === null ? $namePattern === '*' : Str::is($namePattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
