<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Events;

/**
 * Fired after an inline row insert, update or delete commits, once its audit
 * row is written (IMPLEMENTATION_PLAN §3a).
 *
 * Carries the primary key values that identify the row, never other column
 * values, bound parameter values or connection config.
 */
final class RowChanged
{
    /**
     * @param  string  $operation  'insert', 'update' or 'delete'
     * @param  array<string, scalar|null>  $key  primary key column => value: the new row's for an insert, the edited row's otherwise
     */
    public function __construct(
        public int $auditId,
        public ?string $userId,
        public ?string $label,
        public string $role,
        public string $schema,
        public string $table,
        public string $operation,
        public array $key,
    ) {
        //
    }
}
