<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Role;

/**
 * The audit log viewer API (PRD §7.11, M4.5): admins only, read-only and
 * paginated, filterable by user, label, action, classification and date.
 * The entries are what the append-only log already holds (S17–S19); SQL is
 * masked text, and meta carries counts, never bound values.
 */
class AuditController
{
    public const PER_PAGE = 50;

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless((string) $request->attributes->get(ResolveRole::ROLE) === Role::ADMIN, 403, 'Only admins can read the audit log.');

        $input = $request->validate([
            'user_id' => ['sometimes', 'string', 'max:191'],
            'label' => ['sometimes', 'string', 'max:63'],
            'action' => ['sometimes', 'string', 'max:63'],
            'classification' => ['sometimes', 'string', 'max:31'],
            'outcome' => ['sometimes', 'string', 'max:31'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = (int) ($input['page'] ?? 1);

        $entries = AuditEntry::query()
            ->when(isset($input['user_id']), fn ($query) => $query->where('user_id', $input['user_id']))
            ->when(isset($input['label']), fn ($query) => $query->where('label', $input['label']))
            ->when(isset($input['action']), fn ($query) => $query->where('action', 'like', str_replace('*', '%', $input['action'])))
            ->when(isset($input['classification']), fn ($query) => $query->where('classification', $input['classification']))
            ->when(isset($input['outcome']), fn ($query) => $query->where('outcome', $input['outcome']))
            ->when(isset($input['from']), fn ($query) => $query->where('created_at', '>=', $input['from']))
            ->when(isset($input['to']), fn ($query) => $query->where('created_at', '<=', $input['to']))
            ->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get()
            ->map(fn (AuditEntry $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'user_id' => $entry->user_id,
                'label' => $entry->label,
                'role' => $entry->role,
                'classification' => $entry->classification,
                'outcome' => $entry->outcome,
                'error' => $entry->error,
                'duration_ms' => $entry->duration_ms,
                'row_count' => $entry->row_count,
                'sql' => $entry->sql,
                'meta' => $entry->meta,
                'created_at' => $entry->created_at->toIso8601String(),
            ])
            ->all();

        return new JsonResponse([
            'data' => array_slice($entries, 0, self::PER_PAGE),
            'meta' => ['page' => $page, 'per_page' => self::PER_PAGE, 'has_more' => count($entries) > self::PER_PAGE],
        ]);
    }
}
