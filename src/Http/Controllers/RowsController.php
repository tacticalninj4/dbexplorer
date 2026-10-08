<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\Introspector;
use TacticalNinj4\DbExplorer\Drivers\Postgres\RowsQuery;
use TacticalNinj4\DbExplorer\Exceptions\ObjectNotFoundException;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Security\Masker;

/**
 * A page of rows from a table, view, materialized view or foreign table
 * (PRD §7.2). Parameters are described on RowsQuery; anything it can't
 * match against the introspected columns is a 422 before any SQL runs (S12).
 * Masked columns can't be sorted or filtered by, so their values can't be
 * probed through the grid (S14).
 *
 * Response: `{data: rows, meta: {columns, pagination, key, limit, offset,
 * sort, direction, next_cursor, next_offset, count: {value, exact}}}`. `count` is the
 * reltuples estimate unless `exact=1`.
 */
class RowsController
{
    public function __construct(protected ConnectionResolverInterface $db, protected Masker $masker) {}

    /**
     * @throws ObjectNotFoundException
     * @throws ValidationException
     */
    public function __invoke(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        $source = (new Introspector($this->connection($request)))->rowSource($schema, $name);
        $role = (string) $request->attributes->get(ResolveRole::ROLE);

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

        $rows = $this->execute($request, $sql, $bindings);
        $more = count($rows) > $meta['limit'];
        $rows = array_slice($rows, 0, $meta['limit']);
        $last = $rows === [] ? [] : $rows[count($rows) - 1];

        $count = ['value' => $source['estimated_rows'], 'exact' => false];

        if ($meta['count'] !== null) {
            $result = $this->execute($request, $meta['count']['sql'], $meta['count']['bindings']);
            $count = ['value' => (int) ($result[0]['count'] ?? 0), 'exact' => true];
        }

        return new JsonResponse([
            'data' => $rows,
            'meta' => [
                'columns' => $source['columns'],
                'pagination' => $meta['pagination'],
                'key' => $key,
                'limit' => $meta['limit'],
                'offset' => $meta['offset'],
                'sort' => $meta['sort'],
                'direction' => $meta['direction'],
                'next_cursor' => $more && $meta['pagination'] === 'keyset' ? RowsQuery::encodeCursor(
                    array_map(fn (string $column): mixed => $last[$column] ?? null, $meta['cursor_columns']),
                    $meta['sort'],
                    $meta['direction'],
                ) : null,
                'next_offset' => $more && $meta['pagination'] === 'offset' ? (int) $meta['offset'] + $meta['limit'] : null,
                'count' => $count,
            ],
        ]);
    }

    /**
     * Run a read on the role's connection.
     *
     * Row browsing goes through the QueryRunner (M1.6: read-only transaction,
     * statement timeout, masking, audit). Until it is wired in, this answers
     * 501 so no rows are ever read around those guards.
     *
     * @param  list<string|int>  $bindings
     * @return list<array<string, mixed>>
     */
    private function execute(Request $request, string $sql, array $bindings): array
    {
        abort(501, 'Row browsing is not available yet.');
    }

    private function connection(Request $request): ConnectionInterface
    {
        return $this->db->connection((string) $request->attributes->get(ResolveRole::CONNECTION));
    }
}
