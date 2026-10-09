<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Models;

/**
 * The states of a {@see Run} (PRD §7.5). A run starts queued, becomes running
 * when a worker picks it up, and ends in exactly one of the final states.
 */
final class RunStatus
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const ALL = [self::QUEUED, self::RUNNING, self::SUCCEEDED, self::FAILED, self::CANCELLED];

    /** States a run can still leave. */
    public const ACTIVE = [self::QUEUED, self::RUNNING];

    /** States a run never leaves. */
    public const FINISHED = [self::SUCCEEDED, self::FAILED, self::CANCELLED];

    public static function isFinished(string $status): bool
    {
        return in_array($status, self::FINISHED, true);
    }
}
