<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A named query (PRD §7.8): SQL with typed `:parameters`, a description and
 * tags. Private to its user, or shared with everyone who can use its label;
 * whoever runs a shared query needs the role its classification requires.
 *
 * @property int $id
 * @property string $user_id
 * @property string|null $label
 * @property string $name
 * @property string|null $description
 * @property string $sql
 * @property array<int, array<string, mixed>>|null $params
 * @property string $visibility
 * @property array<int, string>|null $tags
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SavedQuery extends Model
{
    public const PRIVATE = 'private';

    public const SHARED = 'shared';

    public const VISIBILITIES = [self::PRIVATE, self::SHARED];

    protected $table = 'dbx_saved_queries';

    /**
     * @var array<int, string>
     */
    protected $guarded = ['id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'params' => 'array',
        'tags' => 'array',
    ];

    public function getConnectionName(): ?string
    {
        $connection = config('dbexplorer.storage.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    /**
     * The saved queries the request's user can run on the label: their own,
     * and the shared ones (PRD §7.8).
     *
     * @param  Builder<SavedQuery>  $query
     * @return Builder<SavedQuery>
     */
    public function scopeUsableBy(Builder $query, string $userId, string $label): Builder
    {
        return $query
            ->where(function (Builder $query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('visibility', self::SHARED);
            })
            ->where('label', $label)
            ->orderBy('name');
    }

    /**
     * Only the owner changes or deletes their saved query, shared or not.
     */
    public function isOwnedBy(string $userId): bool
    {
        return $this->user_id === $userId;
    }
}
