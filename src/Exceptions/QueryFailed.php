<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Exceptions;

use Illuminate\Http\JsonResponse;
use PDOException;
use RuntimeException;

/**
 * A query the database (or the QueryRunner) refused or that failed while running.
 *
 * Safe to show to the user who ran the query: it holds the SQLSTATE, the
 * server's message (with DETAIL and HINT lines) and the error position in the
 * submitted SQL, but never connection config and never the wrapped PDOException.
 */
class QueryFailed extends RuntimeException
{
    /**
     * @param  string|null  $sqlState  The five-character SQLSTATE, e.g. 42601, or null when there is none
     * @param  int|null  $position  1-based character offset of the error in the submitted SQL
     * @param  int|null  $lineNumber  1-based line of the error in the submitted SQL
     */
    final public function __construct(
        string $message,
        public ?string $sqlState = null,
        public ?int $position = null,
        public ?int $lineNumber = null,
        public ?int $auditId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The API answer: 422 with the message, SQLSTATE and position, nothing else.
     * A hidden relation (HiddenObjectReferenced) renders exactly like a missing one.
     */
    public function render(): JsonResponse
    {
        return new JsonResponse(['error' => [
            'message' => $this->getMessage(),
            'sqlstate' => $this->sqlState,
            'position' => $this->position,
        ]], 422);
    }

    /**
     * The QueryRunner already wrote it to the audit log; it isn't an app error.
     */
    public function report(): bool
    {
        return true;
    }

    /**
     * Build from a statement error. `$prefixLines` is how many lines the runner
     * put before the submitted SQL (e.g. `DECLARE … FOR\n`), so the server's
     * line numbers can be mapped back onto it.
     */
    public static function fromPdo(PDOException $e, string $sql, int $prefixLines = 0): static
    {
        $info = $e->errorInfo ?? [];
        $raw = is_string($info[2] ?? null) ? $info[2] : '';
        $state = is_string($info[0] ?? null) && strlen($info[0]) === 5 ? $info[0] : null;
        $line = null;
        $position = null;

        // "LINE 2: <snippet>\n        ^": the snippet may be cut to "...<part>..." on long lines.
        if (preg_match('/^LINE (\d+): (.*)\n( *)\^\n?/m', $raw, $match, PREG_OFFSET_CAPTURE) === 1) {
            $raw = substr($raw, 0, $match[0][1]).substr($raw, $match[0][1] + strlen($match[0][0]));
            $line = (int) $match[1][0] - $prefixLines;
            $position = self::position($sql, $line, $match[2][0], strlen($match[3][0]) - strlen("LINE {$match[1][0]}: "));
            $line = $line >= 1 ? $line : null;
        }

        $message = trim((string) preg_replace('/^(ERROR|FATAL|PANIC):  /', '', trim($raw)));

        if ($message === '') {
            $message = $state === 'HY000'
                ? 'The database could not run this statement through a prepared statement (COPY to or from the client, for example, is not supported).'
                : 'The database rejected the statement.';
        }

        return new static($message, $state, $position, $line);
    }

    /**
     * The connection couldn't be opened. The driver's message names the host and
     * user, so it is reported to the app log only and never carried here.
     */
    public static function connectionFailed(): static
    {
        return new static('Could not connect to the database.', '08006');
    }

    /**
     * Map the server's caret onto a 1-based character offset in `$sql`, or null.
     */
    private static function position(string $sql, int $line, string $snippet, int $caret): ?int
    {
        $lines = explode("\n", $sql);

        if ($line < 1 || ! isset($lines[$line - 1]) || $caret < 0) {
            return null;
        }

        // The server shows tabs as single spaces and counts characters, not bytes.
        $text = str_replace("\t", ' ', $lines[$line - 1]);
        $column = $caret;

        if (str_starts_with($snippet, '...')) {
            $part = substr($snippet, 3);
            $part = str_ends_with($part, '...') ? substr($part, 0, -3) : $part;
            $start = $part === '' ? false : mb_strpos($text, $part);

            if ($start === false) {
                return null;
            }

            $column = $start + $caret - 3;
        }

        if ($column > mb_strlen($text)) {
            return null;
        }

        $offset = 0;

        for ($i = 0; $i < $line - 1; $i++) {
            $offset += mb_strlen($lines[$i]) + 1;
        }

        return $offset + $column + 1;
    }
}
