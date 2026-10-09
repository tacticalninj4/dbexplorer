<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Events;

/**
 * Fired when a background run ends, once its audit row is written
 * (IMPLEMENTATION_PLAN §3a).
 *
 * Carries the run's id, type, final status and duration only: never SQL,
 * error text, result paths or connection config. Load the run for more.
 */
final class RunFinished
{
    /**
     * @param  string  $status  'succeeded', 'failed' or 'cancelled'
     */
    public function __construct(
        public int $auditId,
        public int $runId,
        public string $type,
        public string $status,
        public int $durationMs,
    ) {
        //
    }
}
