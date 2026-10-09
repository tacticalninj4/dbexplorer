<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Exceptions;

use RuntimeException;

/**
 * Thrown by a run's job for an expected failure whose message is already
 * safe to show the run's user (redacted: no SQL values, no connection
 * details). The run is failed with the message as its error, and the
 * exception isn't reported or rethrown.
 */
class RunFailed extends RuntimeException
{
    //
}
