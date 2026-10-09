<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\Introspector;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Drivers\Postgres\RowsQuery;
use TacticalNinj4\DbExplorer\Exceptions\ObjectNotFoundException;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Security\Masker;

/**
 * A page of rows from a table, view, materialized view or foreign table
 * (PRD §7.2). Parameters are described on RowsQuery; anything it can't
 * match against the introspected columns is a 422 before any SQL runs (S12).
 * Masked columns can't be sorted or filtered by, so their values can't be
 * probed through the grid (S14).
 *
 * The page and the optional exact count run through the QueryRunner: a
 * read-only transaction for viewers, the role's timeout and row cap,
 * masking and an audit entry each (S9, S10, S14, S17), but no query history row.
 *
 * Response: `{data: {columns: [{name, type, masked}], rows, pagination,
 * next_cursor, next_offset, truncated, count: {value, exact}}}`. Rows are
 * positional, in column order. `truncated` means there is a next page.
 * `count` is the reltuples estimate unless `exact=1`.
 */
class RowsController
{
    public function __construct(
        protected ConnectionResolverInterface $db,
        protected Masker $masker,
        protected QueryRunner $runner,
    ) {}

    /**
     * @throws ObjectNotFoundException
     * @throws ValidationException
     * @throws QueryFailed
     */
    public function __invoke(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        $connection = (string) $request->attributes->get(ResolveRole::CONNECTION);
        $role = (string) $request->attributes->get(ResolveRole::ROLE);
        $source = (new Introspector($this->db->connection($connection)))->rowSource($schema, $name);

        $masked = [];

        foreach ($source['columns'] as $column) {
            if ($this->masker->masksColumn($schema, $name, $column['name'])) {
                $masked[] = $column['name'];
            }
        }

        // A masked key would put its values in the cursor: page by offset instead.
        $key = $source['key'] !== null && array_intersect($source['key'], $masked) === [] ? $source['key'] : null;

        $query = new RowsQuery($schema, $name, $source['columns'], $key, $source['ctid'], $masked);
        [$sql, $bindings, $meta] = $query->build($request->query(), (int) config("dbexplorer.limits.{$role}.rows", 1000));

        // Grid fetches are audited but stay out of the user's query history.
        $result = $this->runner->run($connection, $sql, $bindings, $role, ['history' => false]);

        // The runner drops the row over its cap; RowsQuery asked for one over the page.
        $more = $result->truncated || count($result->rows) > $meta['limit'];
        $rows = array_slice($result->rows, 0, $meta['limit']);
        $nextCursor = null;

        if ($more && $meta['pagination'] === 'keyset' && $rows !== []) {
            $positions = array_flip(array_column($source['columns'], 'name'));
            $last = $rows[count($rows) - 1];

            $nextCursor = RowsQuery::encodeCursor(
                array_map(fn (string $column): mixed => $last[$positions[$column]] ?? null, $meta['cursor_columns']),
                $meta['sort'],
                $meta['direction'],
            );
        }

        $count = ['value' => $source['estimated_rows'], 'exact' => false];

        if ($meta['count'] !== null) {
            $counted = $this->runner->run($connection, $meta['count']['sql'], $meta['count']['bindings'], $role, ['history' => false]);
            $count = ['value' => (int) ($counted->rows[0][0] ?? 0), 'exact' => true];
        }

        return new JsonResponse(['data' => [
            'columns' => $result->columns,
            'rows' => $rows,
            'pagination' => $meta['pagination'],
            'next_cursor' => $nextCursor,
            'next_offset' => $more && $meta['pagination'] === 'offset' ? (int) $meta['offset'] + $meta['limit'] : null,
            'truncated' => $more,
            'count' => $count,
        ]]);
    }
}
