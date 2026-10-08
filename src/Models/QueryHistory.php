<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One interactive query a user ran. Stores the SQL text only, never bound values.
 *
 * @property int $id
 * @property string|null $user_id
 * @property string $label
 * @property string|null $role
 * @property string $sql
 * @property string|null $classification
 * @property int|null $duration_ms
 * @property int|null $row_count
 * @property string $outcome
 * @property string|null $error
 * @property Carbon $created_at
 */
class QueryHistory extends Model
{
    protected $table = 'dbx_query_history';

    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $guarded = ['id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'duration_ms' => 'integer',
        'row_count' => 'integer',
        'created_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        $connection = config('dbexplorer.storage.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }
}
