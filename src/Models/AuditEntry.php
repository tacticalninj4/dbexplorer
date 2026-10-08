<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use TacticalNinj4\DbExplorer\Audit\AuditLog;

/**
 * One row of the append-only, hash-chained audit log (PRD S17, S18, S22).
 *
 * Rows are written only by {@see AuditLog::record()}. Creating, updating or
 * deleting through the model throws; dbx:prune deletes through the query builder.
 *
 * @property int $id
 * @property Carbon $created_at
 * @property string $action
 * @property string|null $user_id
 * @property string|null $role
 * @property string|null $label
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $sql
 * @property string|null $classification
 * @property int|null $duration_ms
 * @property int|null $row_count
 * @property string $outcome
 * @property string|null $error
 * @property array<string, mixed>|null $meta
 * @property string $prev_hash
 * @property string $hash
 */
class AuditEntry extends Model
{
    protected $table = 'dbx_audit_log';

    public $timestamps = false;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'created_at' => 'datetime',
        'duration_ms' => 'integer',
        'row_count' => 'integer',
        'meta' => 'array',
    ];

    public function getConnectionName(): ?string
    {
        $connection = config('dbexplorer.storage.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    protected static function booted(): void
    {
        static::creating(function (): void {
            throw new LogicException('Audit entries can only be written through DbExplorer::audit().');
        });

        static::updating(function (): void {
            throw new LogicException('Audit entries are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit entries are append-only and cannot be deleted; use dbx:prune.');
        });
    }
}
