<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Models;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use TacticalNinj4\DbExplorer\Events\RunFinished;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;

/**
 * One piece of background work (PRD §7.5): a background query, export, import,
 * script, backup or scheduled query, tracked from queued to finished.
 *
 * The job behind it carries only this row's id (S21); everything else, and
 * the user's identity, is read back from here when the job runs.
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property int|null $progress
 * @property array<string, mixed>|null $meta
 * @property string|null $result_disk
 * @property string|null $result_path
 * @property string|null $error
 * @property int|null $pid
 * @property bool $cancel_requested
 * @property string|null $user_id
 * @property string|null $label
 * @property string|null $role
 * @property int|null $schedule_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Run extends Model
{
    /** The meta key holding the auth provider the user was resolved from. */
    public const USER_PROVIDER = 'user_provider';

    protected $table = 'dbx_runs';

    /**
     * @var array<int, string>
     */
    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => RunStatus::QUEUED,
        'cancel_requested' => false,
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'progress' => 'integer',
        'meta' => 'array',
        'pid' => 'integer',
        'cancel_requested' => 'boolean',
        'schedule_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        $connection = config('dbexplorer.storage.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    /**
     * Create a queued run for the request's user, label and role (as set by
     * ResolveRole). The auth provider of the request's guard is kept in meta,
     * so the job can load the same user later and re-check their role.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function queueFor(Request $request, string $type, array $meta = []): self
    {
        $id = $request->user()?->getAuthIdentifier();
        $provider = config('auth.guards.'.app(AuthManager::class)->getDefaultDriver().'.provider');

        return static::query()->create([
            'type' => $type,
            'user_id' => is_scalar($id) ? (string) $id : null,
            'label' => $request->attributes->get(ResolveRole::LABEL),
            'role' => $request->attributes->get(ResolveRole::ROLE),
            'meta' => [self::USER_PROVIDER => is_string($provider) ? $provider : null] + $meta,
        ]);
    }

    /**
     * The user who started the run, loaded fresh through the auth provider
     * they signed in with, or null when they no longer exist.
     */
    public function owner(): ?Authenticatable
    {
        $provider = $this->meta[self::USER_PROVIDER] ?? null;

        if ($this->user_id === null || ! is_string($provider)) {
            return null;
        }

        return app(AuthManager::class)->createUserProvider($provider)?->retrieveById($this->user_id);
    }

    public function isFinished(): bool
    {
        return RunStatus::isFinished($this->status);
    }

    /**
     * Move a queued run to running. False when it isn't queued any more or its
     * cancellation was requested first: the job must not start it.
     */
    public function markRunning(): bool
    {
        $now = Date::now();

        $updated = static::query()
            ->whereKey($this->id)
            ->where('status', RunStatus::QUEUED)
            ->where('cancel_requested', false)
            ->update(['status' => RunStatus::RUNNING, 'started_at' => $now, 'updated_at' => $now]);

        $this->refresh();

        return $updated === 1;
    }

    /**
     * End an active run as succeeded, failed or cancelled, then write the
     * `run.<status>` audit entry and fire {@see RunFinished}. False, and
     * nothing written, when the run had already finished.
     *
     * `$error` is stored and shown to the user as is: redact it first.
     */
    public function finish(string $status, ?string $error = null): bool
    {
        if (! RunStatus::isFinished($status)) {
            throw new InvalidArgumentException("[{$status}] is not a final run status.");
        }

        $now = Date::now();
        $changes = ['status' => $status, 'error' => $error, 'pid' => null, 'finished_at' => $now, 'updated_at' => $now];

        if ($status === RunStatus::SUCCEEDED) {
            $changes['progress'] = 100;
        }

        $updated = static::query()->whereKey($this->id)->whereIn('status', RunStatus::ACTIVE)->update($changes);

        $this->refresh();

        if ($updated !== 1) {
            return false;
        }

        $started = $this->started_at;
        $durationMs = $started === null ? 0 : max(0, (int) round(($now->getPreciseTimestamp(3) - $started->getPreciseTimestamp(3))));

        $audit = DbExplorer::audit([
            'action' => 'run.'.$status,
            'user_id' => $this->user_id,
            'label' => $this->label,
            'role' => $this->role,
            'duration_ms' => $durationMs,
            'outcome' => [RunStatus::SUCCEEDED => 'success', RunStatus::FAILED => 'error'][$status] ?? $status,
            'error' => $error,
            'meta' => ['run_id' => $this->id, 'type' => $this->type],
        ]);

        event(new RunFinished($audit->id, $this->id, $this->type, $status, $durationMs));

        return true;
    }

    /**
     * Whether the run's result file can be downloaded.
     */
    public function hasResult(): bool
    {
        return $this->status === RunStatus::SUCCEEDED && $this->result_path !== null;
    }

    /**
     * The run as the API shows it. Never the result's disk or path, the
     * backend pid or meta (which can hold SQL and bound values) — except the
     * per-statement outcomes a script run keeps in `meta.statements`, reduced
     * to `{line, status, duration_ms, affected}`: no SQL text.
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        $api = [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'progress' => $this->progress,
            'label' => $this->label,
            'role' => $this->role,
            'user_id' => $this->user_id,
            'cancel_requested' => $this->cancel_requested,
            'error' => $this->error,
            'result_available' => $this->hasResult(),
            'created_at' => $this->created_at->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];

        foreach ((array) ($this->meta['statements'] ?? []) as $statement) {
            if (is_array($statement)) {
                $api['statements'][] = [
                    'line' => (int) ($statement['line'] ?? 0),
                    'status' => (string) ($statement['status'] ?? 'skipped'),
                    'duration_ms' => isset($statement['duration_ms']) && is_numeric($statement['duration_ms']) ? (float) $statement['duration_ms'] : null,
                    'affected' => isset($statement['affected']) && is_numeric($statement['affected']) ? (int) $statement['affected'] : null,
                ];
            }
        }

        return $api;
    }
}
