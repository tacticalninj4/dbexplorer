<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An inline update or delete matched no row: the row changed, or was
 * deleted, since the client read it. Nothing was written.
 */
class RowConflict extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $current  the row as it is now, masked, or null when it is gone
     */
    public function __construct(public ?array $current, public ?int $auditId = null)
    {
        parent::__construct('The row was changed or deleted since it was read. Nothing was written.');
    }

    /**
     * 409 `{error: {message}, current}`.
     */
    public function render(): JsonResponse
    {
        return new JsonResponse(['error' => ['message' => $this->getMessage()], 'current' => $this->current], 409);
    }

    /**
     * Audited by RowWriter; not an app error.
     */
    public function report(): bool
    {
        return true;
    }
}
