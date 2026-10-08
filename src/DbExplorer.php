<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer;

use TacticalNinj4\DbExplorer\Audit\AuditLog;
use TacticalNinj4\DbExplorer\Models\AuditEntry;

class DbExplorer
{
    /**
     * Append an entry to the hash-chained audit log (S17, S22).
     *
     * @param  array<string, mixed>  $entry
     */
    public function audit(array $entry): AuditEntry
    {
        return app(AuditLog::class)->record($entry);
    }
}
