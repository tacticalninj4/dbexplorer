<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Events;

/**
 * Fired when a scheduled query's run fails (IMPLEMENTATION_PLAN §3a),
 * after the run's own audit row was written. The error is the audit-style
 * text: SQLSTATE and the primary message, never bound values.
 */
final class ScheduleFailed
{
    public function __construct(
        public int $scheduleId,
        public int $runId,
        public string $error,
    ) {
        //
    }
}
