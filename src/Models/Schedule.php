<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use TacticalNinj4\DbExplorer\Jobs\RunScheduledQuery;

/**
 * A scheduled saved query (PRD §7.9), admin-only: a cron expression, the
 * notifications to send, and when it last ran. `dbx:schedule:tick` decides
 * whether it is due and dispatches {@see RunScheduledQuery}
 * under a cache lock, so overlapping runs are impossible.
 *
 * @property int $id
 * @property int $saved_query_id
 * @property string $user_id
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
