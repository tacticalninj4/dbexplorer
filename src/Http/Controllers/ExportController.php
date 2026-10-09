<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\Introspector;
use TacticalNinj4\DbExplorer\Drivers\Postgres\RowsQuery;
use TacticalNinj4\DbExplorer\Exceptions\ObjectNotFoundException;
use TacticalNinj4\DbExplorer\Export\ExportWriter;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Jobs\Export;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Sql\Classifier;

/**
 * The export API (PRD §7.6): queue a table, view or SELECT result as an
 * `export` run. Any role can export (D4); masking is applied in the file
 * (S14). Only reads export: a query the classifier can't vouch for is a 422,
 * and the hidden-object check runs again inside the job's cursor. A table is
 * resolved through the introspector first (missing or hidden: 404) and its
 * selectable columns named, every identifier quoted (S12).
 *
 * Nothing runs in the request; the answer is 202 with the queued run.
 */
class ExportController
{
    /**
     * Queue `table` (`{schema, name}`) or `sql` in `format`
     * ('csv', 'jsonl' or 'sql'; csv by default) as an export run. The sql
     * format needs a table to write INSERTs for. `bindings` follow the SQL
     * editor's rules.
     *
     * @throws ValidationException
     * @throws ObjectNotFoundException for a missing or hidden table (404)
     */
    public function __invoke(Request $request, DatabaseManager $db): JsonResponse
    {
        $input = $request->validate([
            'sql' => ['required_without:table', 'nullable', 'string', 'max:'.(int) config('dbexplorer.limits.sql_length', 100000)],
            'bindings' => ['sometimes', 'array', 'max:1000', function (string $attribute, mixed $value, Closure $fail): void {
                $keys = is_array($value) ? array_keys($value) : [];
                $named = count(array_filter($keys, 'is_string'));

                if ($named !== 0 && $named !== count($keys)) {
                    $fail('The bindings must be all positional or all named.');
                }
            }],
            'bindings.*' => [function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && ! is_scalar($value)) {
                    $fail('Each binding must be a string, number, boolean or null.');
                }
            }],
            'table' => ['required_without:sql', 'array'],
            'table.schema' => ['required_with:table', 'string', 'max:63'],
            'table.name' => ['required_with:table', 'string', 'max:63'],
            'format' => ['sometimes', 'in:'.implode(',', ExportWriter::FORMATS)],
        ]);

        $format = (string) ($input['format'] ?? ExportWriter::CSV);
        $table = null;
        $sql = (string) ($input['sql'] ?? '');

        if (isset($input['table']) && is_array($input['table'])) {
            $table = ['schema' => (string) $input['table']['schema'], 'name' => (string) $input['table']['name']];

            // A real, visible table (S12, S13): missing and hidden are the same 404.
            $source = (new Introspector($db->connection((string) $request->attributes->get(ResolveRole::CONNECTION))))->rowSource($table['schema'], $table['name']);
            $columns = array_column($source['columns'], 'name');

            $sql = 'SELECT '.($columns === [] ? '*' : implode(', ', array_map([RowsQuery::class, 'quoteIdentifier'], $columns)))
                .' FROM '.RowsQuery::quoteIdentifier($table['schema']).'.'.RowsQuery::quoteIdentifier($table['name']);
        }

        if ($sql === '') {
            throw ValidationException::withMessages(['sql' => ['There is nothing to export.']]);
        }

        if (Classifier::classify($sql) !== Classifier::READ) {
            throw ValidationException::withMessages(['sql' => ['Only SELECT-style queries can be exported.']]);
        }

        if ($format === ExportWriter::SQL && $table === null) {
            throw ValidationException::withMessages(['format' => ['The sql format exports a table\'s rows as INSERTs, so it needs a table.']]);
        }

        /** @var array<int|string, scalar|null> $bindings */
        $bindings = $input['bindings'] ?? [];

        $run = Run::queueFor($request, Export::TYPE, [
            'sql' => $sql,
            'bindings' => $bindings,
            'format' => $format,
        ] + ($table === null ? [] : ['table' => $table]));

        Export::dispatch($run->id);

        return new JsonResponse(['data' => $run->toApi()], 202);
    }
}
