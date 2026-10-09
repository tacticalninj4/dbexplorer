<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A database object is missing, hidden (S13) or not accessible to the
 * connection's login. The message is the same in every case, so callers can
 * answer 404 without revealing whether a hidden object exists.
 */
class ObjectNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The database object does not exist.');
    }

    /**
     * Answer every case with the same 404 JSON body.
     */
    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], 404);
    }

    /**
     * A lookup that finds nothing is not an error worth logging.
     */
    public function report(): bool
    {
        return true;
    }
}
