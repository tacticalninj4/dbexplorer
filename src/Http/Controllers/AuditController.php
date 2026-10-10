<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TacticalNinj4\DbExplorer\DbExplorer;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Role;

/**
 * The audit log viewer API (PRD §7.12, M4.5): admins only, read-only and
 * paginated, filterable by user, label, action, classification and date.
 * The entries are what the append-only log already holds (S17–S19); SQL is
 * masked text, and meta carries counts, never bound values.
 *
 * Being admin on the URL's label opens the viewer; the rows are limited to
 * the labels the user is admin on (DbExplorer::roleFor). Rows without a
 * label (e.g. password confirmations) only show to a user who is admin on
 * every configured label. A `label` filter outside that set matches
 * nothing: an empty page, the same answer as a label with no entries.
 */
class AuditController
{
    public const PER_PAGE = 50;

    public function __invoke(Request $request, DbExplorer $explorer): JsonResponse
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

        $user = $request->user();
        $labels = $explorer->labels();
        $adminLabels = array_values(array_filter($labels, fn (string $label): bool => $explorer->roleFor($user, $label) === Role::ADMIN));

        $entries = AuditEntry::query()->where(function (Builder $query) use ($labels, $adminLabels): void {
            $query->whereIn('label', $adminLabels);

            if (count($adminLabels) === count($labels)) {
                $query->orWhereNull('label');
            }
        });

        foreach ([
            ['user_id', 'where', 'user_id'],
            ['label', 'where', 'label'],
            ['action', 'like', 'action'],
            ['classification', 'where', 'classification'],
            ['outcome', 'where', 'outcome'],
        ] as [$key, $mode, $column]) {
            if (isset($input[$key])) {
                $value = (string) $input[$key];
                $entries->where($column, $mode === 'like' ? 'like' : '=', $mode === 'like' ? str_replace('*', '%', $value) : $value);
            }
        }

        foreach ([['from', '>='], ['to', '<=']] as [$key, $operator]) {
            if (isset($input[$key])) {
                $entries->where('created_at', $operator, (string) $input[$key]);
            }
        }

        $entries = $entries
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
