<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Sql;

use Generator;
use TacticalNinj4\DbExplorer\Exceptions\ScriptParseException;

/**
 * Splits a SQL script into statements on top-level semicolons.
 *
 * This is the project's only statement splitter: the Classifier uses its lenient
 * mode, the script runner its strict one (`split()`). Semicolons inside strings
 * (`'…'`, `E'…'`, …), dollar quotes, quoted identifiers, comments and
 * `CREATE … BEGIN ATOMIC … END` bodies never split. Each statement is an exact
 * slice of the input from its first non-whitespace token (leading comments
 * included) to its last one before the `;` (the `;` excluded). Statements that
 * are empty or only comments are skipped.
 *
 * Strict mode refuses, with a ScriptParseException, anything it can't split with
 * certainty, so a script never runs half a statement:
 * - an unterminated string, quoted identifier, dollar quote or block comment;
 * - an unbalanced parenthesis: a `)` with no `(`, a `(` still open at the end,
 *   or a `;` while a `(` is open. In PostgreSQL a `;` is never legal inside
 *   parentheses: function bodies that contain one are dollar-quoted or
 *   `BEGIN ATOMIC` blocks, and an ATOMIC body's statements sit at depth 0;
 * - a `BEGIN ATOMIC` body with no closing `END`;
 * - psql meta-commands (any `\` outside strings, e.g. `\set`, `\i`, `\copy`);
 * - `COPY … FROM STDIN`, whose inline data would otherwise be read as SQL.
 *
 * Lines are counted by `\n`, so `\r\n` endings work; a lone `\r` does not end a line.
 *
 * @phpstan-import-type Token from Lexer
 */
final class ScriptSplitter
{
    private const UNTERMINATED = [
        Lexer::STRING => 'string literal',
        Lexer::QUOTED_IDENTIFIER => 'quoted identifier',
        Lexer::DOLLAR_STRING => 'dollar-quoted string',
        Lexer::BLOCK_COMMENT => 'block comment',
    ];

    /**
     * Split a script for the script runner, or refuse it.
     *
     * @return list<array{sql: string, offset: int, line: int}> `offset` is the 0-based byte offset and `line` the 1-based line where `sql` starts
     *
     * @throws ScriptParseException when the script can't be split with certainty
     */
    public static function split(string $sql): array
    {
        $statements = [];

        foreach (self::scan($sql, true) as $statement) {
            $statements[] = ['sql' => $statement['sql'], 'offset' => $statement['offset'], 'line' => $statement['line']];
        }

        return $statements;
    }

    /**
     * Yield each statement with its significant (non-whitespace, non-comment)
     * tokens. Lenient mode never throws: a `;` inside open parentheses doesn't
     * split, a stray `)` is ignored, and unterminated input runs to the end.
     *
     * @internal Shared with the Classifier; use split() to run scripts.
     *
     * @return Generator<int, array{sql: string, offset: int, line: int, tokens: list<Token>}, mixed, void>
     *
     * @throws ScriptParseException in strict mode, see the class notes
     */
    public static function scan(string $sql, bool $strict = false): Generator
    {
        $tokens = [];
        $start = null;
        $end = 0;
        $parens = [];
        $atomic = 0;
        $atomicOffset = 0;
        $first = '';
        $previous = '';
        $previousText = '';
        $last = null;
        $line = 1;
        $lineOffset = 0;

        foreach (Lexer::tokens($sql) as $token) {
            if ($token['type'] === Lexer::WHITESPACE) {
                continue;
            }

            $last = $token;

            if ($token['type'] === Lexer::SEMICOLON && $parens !== [] && $strict) {
                throw ScriptParseException::at('Unbalanced parentheses: this ( is still open at the ; on line '.self::line($sql, $token['offset']), $sql, (int) end($parens));
            }

            if ($token['type'] === Lexer::SEMICOLON && $parens === [] && $atomic === 0) {
                if ($tokens !== [] && $start !== null) {
                    $line += substr_count($sql, "\n", $lineOffset, $start - $lineOffset);
                    $lineOffset = $start;

                    yield ['sql' => substr($sql, $start, $end - $start), 'offset' => $start, 'line' => $line, 'tokens' => $tokens];
                }

                $tokens = [];
                $start = null;
                $first = '';
                $previous = '';
                $previousText = '';

                continue;
            }

            $start = $start ?? $token['offset'];
            $end = $token['offset'] + strlen($token['text']);

            if (Lexer::isTrivia($token)) {
                continue;
            }

            // A word right after `.` or `AS` is a name, not a keyword.
            $keyword = $token['type'] === Lexer::WORD && $previousText !== '.' && $previous !== 'AS' ? strtoupper($token['text']) : '';

            if ($token['type'] === Lexer::OPEN_PAREN) {
                $parens[] = $token['offset'];
            } elseif ($token['type'] === Lexer::CLOSE_PAREN && $parens !== []) {
                array_pop($parens);
            } elseif ($token['type'] === Lexer::CLOSE_PAREN && $strict) {
                throw ScriptParseException::at('Unbalanced parentheses: this ) closes nothing', $sql, $token['offset']);
            } elseif ($keyword === 'ATOMIC' && $previous === 'BEGIN' && $first === 'CREATE') {
                $atomic = 1;
                $atomicOffset = $token['offset'];
            } elseif ($atomic > 0 && $keyword === 'CASE') {
                $atomic++;
            } elseif ($atomic > 0 && $keyword === 'END') {
                $atomic--;
            } elseif ($strict && $token['text'] === '\\') {
                throw ScriptParseException::at('psql meta-commands such as '.substr($sql, $token['offset'], min(20, strcspn($sql, " \t\r\n", $token['offset']))).' are not supported', $sql, $token['offset']);
            } elseif ($strict && $keyword === 'STDIN' && $previous === 'FROM' && $first === 'COPY' && $parens === []) {
                throw ScriptParseException::at('COPY … FROM STDIN with inline data is not supported', $sql, $token['offset']);
            }

            $first = $tokens === [] ? $keyword : $first;
            $tokens[] = $token;
            $previous = $keyword;
            $previousText = $token['text'];
        }

        if ($strict && $last !== null) {
            self::assertComplete($sql, $last, $parens, $atomic > 0 ? $atomicOffset : null);
        }

        if ($tokens !== [] && $start !== null) {
            $line += substr_count($sql, "\n", $lineOffset, $start - $lineOffset);

            yield ['sql' => substr($sql, $start, $end - $start), 'offset' => $start, 'line' => $line, 'tokens' => $tokens];
        }
    }

    /**
     * Refuse a script whose end leaves a token, a parenthesis or an ATOMIC body open.
     *
     * @param  Token  $last  the last non-whitespace token
     * @param  list<int>  $parens  offsets of the parentheses still open
     */
    private static function assertComplete(string $sql, array $last, array $parens, ?int $atomic): void
    {
        $kind = self::UNTERMINATED[$last['type']] ?? null;

        // Only the last token can be unterminated, since an unterminated one runs to
        // the end. Lexing it again with a newline after it shows whether it ended:
        // a closed token stops before the newline, an open one swallows it.
        if ($kind !== null) {
            foreach (Lexer::tokens($last['text']."\n") as $token) {
                if (strlen($token['text']) > strlen($last['text'])) {
                    throw ScriptParseException::at('Unterminated '.$kind, $sql, $last['offset']);
                }

                break;
            }
        }

        if ($parens !== []) {
            throw ScriptParseException::at('Unbalanced parentheses: this ( is never closed', $sql, $parens[count($parens) - 1]);
        }

        if ($atomic !== null) {
            throw ScriptParseException::at('This BEGIN ATOMIC body has no closing END', $sql, $atomic);
        }
    }

    private static function line(string $sql, int $offset): int
    {
        return substr_count($sql, "\n", 0, $offset) + 1;
    }
}
