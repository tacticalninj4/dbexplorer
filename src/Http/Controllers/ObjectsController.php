<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TacticalNinj4\DbExplorer\Drivers\Postgres\Introspector;
use TacticalNinj4\DbExplorer\Exceptions\ObjectNotFoundException;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;

/**
 * The object browser API (PRD §7.1). Every action reads the catalog through
 * the Introspector on the connection of the user's role, so it shows only
 * what that database login can use, minus hidden objects (S13).
 *
 * Responses are `{data: …}`. A missing, hidden or inaccessible object throws
 * ObjectNotFoundException, which answers the same 404 body in every case.
 */
class ObjectsController
{
    public function __construct(protected ConnectionResolverInterface $db) {}

    public function schemas(Request $request): JsonResponse
    {
        return self::data($this->introspector($request)->schemas());
    }

    public function extensions(Request $request): JsonResponse
    {
        return self::data($this->introspector($request)->extensions());
    }

    /**
     * Every visible object in a schema.
     *
     * @throws ObjectNotFoundException
     */
    public function index(Request $request, string $label, string $schema): JsonResponse
    {
        return self::data($this->introspector($request)->objects($schema));
    }

    /**
     * One relation or type, with its type.
     *
     * @throws ObjectNotFoundException
     */
    public function show(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        return self::data($this->introspector($request)->object($schema, $name));
    }

    /**
     * @throws ObjectNotFoundException
     */
    public function columns(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        return self::data($this->introspector($request)->columns($schema, $name));
    }

    /**
     * @throws ObjectNotFoundException
     */
    public function indexes(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        return self::data($this->introspector($request)->indexes($schema, $name));
    }

    /**
     * @throws ObjectNotFoundException
     */
    public function constraints(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        return self::data($this->introspector($request)->constraints($schema, $name));
    }

    /**
     * @throws ObjectNotFoundException
     */
    public function triggers(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        return self::data($this->introspector($request)->triggers($schema, $name));
    }

    /**
     * The generated CREATE TABLE of a table.
     *
     * @throws ObjectNotFoundException
     */
    public function ddl(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        return self::data(['ddl' => $this->introspector($request)->tableDdl($schema, $name)]);
    }

    /**
     * The SELECT behind a view or materialized view.
     *
     * @throws ObjectNotFoundException
     */
    public function definition(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        return self::data(['definition' => $this->introspector($request)->viewDefinition($schema, $name)]);
    }

    /**
     * @throws ObjectNotFoundException
     */
    public function stats(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        return self::data($this->introspector($request)->stats($schema, $name));
    }

    /**
     * A function or procedure by its signature, e.g. `post_count(bigint)`.
     *
     * @throws ObjectNotFoundException
     */
    public function showFunction(Request $request, string $label, string $schema, string $signature): JsonResponse
    {
        return self::data($this->introspector($request)->functionDefinition($schema, $signature));
    }

    /**
     * Objects whose name contains `q`, across every visible schema.
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);

        return self::data($this->introspector($request)->search((string) $query['q']));
    }

    protected function introspector(Request $request): Introspector
    {
        return new Introspector($this->db->connection((string) $request->attributes->get(ResolveRole::CONNECTION)));
    }

    protected static function data(mixed $data): JsonResponse
    {
        return new JsonResponse(['data' => $data]);
    }
}
