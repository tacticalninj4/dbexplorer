<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\RunScheduledQuery;

/**
 * A scheduled query (PRD §7.9), admin-only: a cron expression, the
 * notifications to send, and when it last ran. `dbx:schedule:tick` decides
 * whether it is due and dispatches {@see RunScheduledQuery}
 * under a cache lock, so overlapping runs are impossible.
 *
 * A schedule runs its own snapshot of the SQL (`sql`, `params`, `sql_hash`),
 * and names itself by its own copy of the saved query's `name`, both
 * copied from the saved query when an admin approved it (`approved_by`,
 * `approved_at`), never the saved query's current text: `saved_query_id` is
 * provenance only. It runs as the approver, read-only unless the approver
 * also allowed writes (`allow_writes`) or DDL (`allow_ddl`).
 *
 * @property int $id
 * @property int $saved_query_id
 * @property string $user_id
 * @property string|null $name
 * @property string|null $sql
 * @property array<int, mixed>|null $params
 * @property string|null $sql_hash
 * @property bool $allow_writes
 * @property bool $allow_ddl
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string $cron
 * @property string|null $timezone
 * @property bool $enabled
 * @property array<string, mixed>|null $notify
 * @property Carbon|null $last_run_at
 * @property int|null $last_run_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Schedule extends Model
{
    protected $table = 'dbx_schedules';

    /**
     * @var array<int, string>
     */
    protected $guarded = ['id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'saved_query_id' => 'integer',
        'enabled' => 'boolean',
        'params' => 'array',
        'allow_writes' => 'boolean',
        'allow_ddl' => 'boolean',
        'approved_at' => 'datetime',
        'notify' => 'array',
        'last_run_at' => 'datetime',
        'last_run_id' => 'integer',
    ];

    public function getConnectionName(): ?string
    {
        $connection = config('dbexplorer.storage.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    /**
     * @return BelongsTo<SavedQuery, $this>
     */
    public function savedQuery(): BelongsTo
    {
        return $this->belongsTo(SavedQuery::class);
    }

    /**
     * Copy the saved query's name, SQL and parameters into the schedule and record
     * `$userId` as the admin who approved them, with the write and DDL
     * permissions they granted. Unsaved: the caller saves.
     */
    public function approve(SavedQuery $saved, string $userId, bool $allowWrites, bool $allowDdl): self
    {
        return $this->fill([
            'saved_query_id' => $saved->id,
            'name' => $saved->name,
            'sql' => $saved->sql,
            'params' => $saved->params,
            'sql_hash' => hash('sha256', $saved->sql),
            'allow_writes' => $allowWrites || $allowDdl,
            'allow_ddl' => $allowDdl,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);
    }

    /**
     * Whether the schedule holds an approved snapshot that wasn't changed
     * since: the only state in which it may run.
     */
    public function isApproved(): bool
    {
        return $this->approved_by !== null && $this->approved_at !== null && $this->sql !== null
            && is_string($this->sql_hash) && hash_equals($this->sql_hash, hash('sha256', $this->sql));
    }

    /**
     * Disable the schedule and audit why. The label is the saved query's.
     */
    public function disable(string $reason, ?int $runId = null): void
    {
        $this->update(['enabled' => false]);

        DbExplorer::audit([
            'action' => 'schedule.disabled',
            'user_id' => $this->approved_by ?? $this->user_id,
            'label' => $this->savedQuery?->label,
            'outcome' => 'error',
            'error' => $reason,
            'meta' => array_filter(['schedule_id' => $this->id, 'saved_query_id' => $this->saved_query_id, 'run_id' => $runId]),
        ]);
    }

    /**
     * The notification settings, normalized: `on` (success, failure,
     * threshold), `threshold_rows` and `mail` recipients.
     *
     * @return array{on: list<string>, threshold_rows: int|null, mail: list<string>}
     */
    public function notifySettings(): array
    {
        $notify = is_array($this->notify) ? $this->notify : [];

        return [
            'on' => array_values(array_intersect((array) ($notify['on'] ?? []), ['success', 'failure', 'threshold'])),
            'threshold_rows' => is_numeric($notify['threshold_rows'] ?? null) ? (int) $notify['threshold_rows'] : null,
            'mail' => array_values(array_filter((array) ($notify['mail'] ?? []), fn (mixed $address): bool => is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false)),
        ];
    }
}
