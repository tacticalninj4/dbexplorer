<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TacticalNinj4\DbExplorer\Drivers\Postgres\RowWriter;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Role;
use TacticalNinj4\DbExplorer\Security\PasswordConfirmation;

/**
 * Inline row editing (PRD §7.2), editors and admins only; RowWriter has the
 * rules. Every action takes `preview` (returns `{data: {sql}}`, runs
 * nothing) and `unmask` (admins only, audited), in the query or the body.
 *
 * - insert: `{values: {column: value}}` → 201 `{data: {row}}`. At least one
 *   value is required; `{defaults: true}` (with no values) inserts a row of
 *   column defaults instead, and no values without it is a 422.
 * - update: `{key: {pk: value}, original: {column: value}, changes: {column: value}}` → `{data: {row}}`.
 *   `overwrite: true` marks a deliberate re-save after a 409 with the
 *   conflict's `current` row as `original`; it's still conflict-checked, and
 *   only adds `meta.overwrite` to the audit entry.
 * - delete: `{key, original}` → `{data: {deleted: true}}`; destructive, so it
 *   needs a recent password confirmation (S5, 423) unless previewing.
 *
 * A changed or deleted row is a 409 `{error: {message}, current}`; missing or
 * hidden tables are a 404; a viewer gets 403; bad input and tables without a
 * primary key get 422.
 */
class RowWriteController
{
    public function __construct(protected RowWriter $writer) {}

    public function insert(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        [$connection, $role, $options] = $this->context($request);

        $request->validate(['defaults' => ['sometimes', 'boolean']]);

        $result = $this->writer->insert($connection, $role, $schema, $name, $request->input('values', []), $options + ['defaults' => $request->boolean('defaults')]);

        return isset($result['sql'])
            ? new JsonResponse(['data' => ['sql' => $result['sql']]])
            : new JsonResponse(['data' => ['row' => $result['row']]], 201);
    }

    public function update(Request $request, string $label, string $schema, string $name): JsonResponse
    {
        [$connection, $role, $options] = $this->context($request);
        $request->validate(['overwrite' => ['sometimes', 'boolean']]);

        $result = $this->writer->update(
            $connection, $role, $schema, $name,
            $request->input('key'), $request->input('original', []), $request->input('changes'), $options + ['overwrite' => $request->boolean('overwrite')],
        );

        return new JsonResponse(['data' => isset($result['sql']) ? ['sql' => $result['sql']] : ['row' => $result['row']]]);
    }

    public function delete(Request $request, PasswordConfirmation $confirmation, string $label, string $schema, string $name): JsonResponse
    {
        [$connection, $role, $options] = $this->context($request);

        if (! $options['preview']) {
            $confirmation->ensureConfirmed($request);
        }

        $result = $this->writer->delete($connection, $role, $schema, $name, $request->input('key'), $request->input('original', []), $options);

        return new JsonResponse(['data' => isset($result['sql']) ? ['sql' => $result['sql']] : ['deleted' => true]]);
    }

    /**
     * @return array{0: string, 1: string, 2: array{preview: bool, unmask: bool}}
     */
    protected function context(Request $request): array
    {
        $role = (string) $request->attributes->get(ResolveRole::ROLE);

        abort_unless(Role::atLeast($role, Role::EDITOR), 403, 'Editing rows needs the editor role.');

        $request->validate([
            'preview' => ['sometimes', 'boolean'],
            'unmask' => ['sometimes', 'boolean'],
        ]);

        $options = ['preview' => $request->boolean('preview'), 'unmask' => $request->boolean('unmask')];

        abort_if($options['unmask'] && $role !== Role::ADMIN, 403, 'Only admins can unmask columns.');

        return [(string) $request->attributes->get(ResolveRole::CONNECTION), $role, $options];
    }
}
