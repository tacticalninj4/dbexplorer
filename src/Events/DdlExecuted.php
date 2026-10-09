<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Events;

/**
 * Fired after DDL or destructive SQL ran and committed, once its audit row is
 * written (IMPLEMENTATION_PLAN §3a): from the SQL editor and the schema editor.
 *
 * Never carries bound parameter values, result values or connection config.
 */
final class DdlExecuted
{
    /**
     * @param  string  $classification  'ddl' or 'destructive' (UX only, PRD S11)
     * @param  string  $sql  The SQL text that ran; several statements are joined with ";\n"
     */
    public function __construct(
        public int $auditId,
        public ?string $userId,
        public ?string $label,
        public string $role,
        public string $classification,
        public string $sql,
    ) {
        //
    }
}
