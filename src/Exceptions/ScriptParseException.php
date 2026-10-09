<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Exceptions;

/**
 * The ScriptSplitter could not split a script into statements with certainty,
 * so the script runner must refuse to run any of it (fail safe: never run half
 * a statement). It renders like a PostgreSQL syntax error (SQLSTATE 42601),
 * pointing at the offending spot.
 */
class ScriptParseException extends QueryFailed
{
    /** 0-based byte offset of the offending spot in the script. */
    public int $offset = 0;

    /**
     * @param  int  $offset  0-based byte offset into `$sql`
     */
    public static function at(string $message, string $sql, int $offset): self
    {
        $before = substr($sql, 0, $offset);
        $line = substr_count($before, "\n") + 1;

        $e = new self($message.' (line '.$line.')', '42601', mb_strlen($before) + 1, $line);
        $e->offset = $offset;

        return $e;
    }
}
