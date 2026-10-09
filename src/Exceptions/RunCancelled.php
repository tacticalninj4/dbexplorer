<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Exceptions;

use RuntimeException;

/**
 * Thrown by a run's job to stop because its cancellation was requested.
 * The run is then marked cancelled, not failed.
 */
class RunCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The run was cancelled.');
    }
}
