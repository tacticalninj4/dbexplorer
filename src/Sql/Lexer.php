<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Sql;

use Generator;

/**
 * A small PostgreSQL-aware tokenizer.
 *
 * It knows exactly enough of the PostgreSQL lexical rules to tell code apart from
 * comments, string literals, dollar-quoted bodies and quoted identifiers. It is
 * shared by the statement classifier, the script splitter and the saved-query
 * `:name` binder. It never throws: an unterminated string, comment or identifier
 * runs to the end of the input as a single token. It runs in linear time, and the
 * concatenated token texts always reproduce the input exactly.
 *
 * Assumes `standard_conforming_strings = on` (the default since PostgreSQL 9.1),
 * so backslashes escape only inside `E'…'` strings.
 *
 * @phpstan-type Token array{type: string, text: string, offset: int}
 */
final class Lexer
{
    /** Spaces, tabs, newlines, form feeds and vertical tabs. */
    public const WHITESPACE = 'whitespace';

    /** `-- …` up to (not including) the end of the line. */
    public const LINE_COMMENT = 'line_comment';

    /** `/* … *\/`, which PostgreSQL lets nest. */
    public const BLOCK_COMMENT = 'block_comment';

    /** `'…'`, `E'…'`, `U&'…'`, `B'…'`, `X'…'` and `N'…'`, prefix included. */
    public const STRING = 'string';

    /** `$$…$$` or `$tag$…$tag$`. */
    public const DOLLAR_STRING = 'dollar_string';

    /** `"…"` or `U&"…"`. */
    public const QUOTED_IDENTIFIER = 'quoted_identifier';

    /** `$1`, `$2`, … */
    public const POSITIONAL_PARAMETER = 'positional_parameter';

    /** `:name` (but never the `::` cast operator). */
    public const NAMED_PARAMETER = 'named_parameter';

    public const SEMICOLON = 'semicolon';

    public const OPEN_PAREN = 'open_paren';

    public const CLOSE_PAREN = 'close_paren';

    /** Keywords and unquoted identifiers. */
    public const WORD = 'word';

    public const NUMBER = 'number';

    /** A run of operator characters, `::` or a lone `:`. */
    public const OPERATOR = 'operator';

    /** Any other single character, such as `,`, `.`, `[` or `]`. */
    public const OTHER = 'other';

    private const SPACE_CHARS = " \t\n\r\f\v";

    private const DIGITS = '0123456789';

    private const LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_';

    private const OPERATOR_CHARS = '+-*/<>=~!@#%^&|`?';

    /**
     * Tokenize the whole input at once.
     *
     * @return list<Token>
     */
    public static function tokenize(string $sql): array
    {
        $tokens = [];

        foreach (self::tokens($sql) as $token) {
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Yield tokens one at a time, so callers can scan large input in constant memory.
     *
     * @return Generator<int, Token, mixed, void>
     */
    public static function tokens(string $sql): Generator
    {
        $length = strlen($sql);
        $highBytes = implode('', array_map('chr', range(0x80, 0xFF)));
        $identStart = self::LETTERS.$highBytes;
        $identChars = $identStart.self::DIGITS.'$';
        $tagChars = $identStart.self::DIGITS;
        $offset = 0;

        while ($offset < $length) {
            $char = $sql[$offset];
            $next = $offset + 1 < $length ? $sql[$offset + 1] : '';

            if (strpos(self::SPACE_CHARS, $char) !== false) {
                $type = self::WHITESPACE;
                $end = $offset + strspn($sql, self::SPACE_CHARS, $offset);
            } elseif ($char === '-' && $next === '-') {
                $type = self::LINE_COMMENT;
                $end = $offset + strcspn($sql, "\r\n", $offset);
            } elseif ($char === '/' && $next === '*') {
                $type = self::BLOCK_COMMENT;
                $end = self::blockCommentEnd($sql, $offset);
            } elseif ($char === "'") {
                $type = self::STRING;
                $end = self::quotedEnd($sql, $offset + 1, "'", false);
            } elseif ($char === '"') {
                $type = self::QUOTED_IDENTIFIER;
                $end = self::quotedEnd($sql, $offset + 1, '"', false);
            } elseif (($char === 'E' || $char === 'e') && $next === "'") {
                $type = self::STRING;
                $end = self::quotedEnd($sql, $offset + 2, "'", true);
            } elseif (($char === 'U' || $char === 'u') && $next === '&' && ($offset + 2 < $length) && ($sql[$offset + 2] === "'" || $sql[$offset + 2] === '"')) {
                $type = $sql[$offset + 2] === "'" ? self::STRING : self::QUOTED_IDENTIFIER;
                $end = self::quotedEnd($sql, $offset + 3, $sql[$offset + 2], false);
            } elseif (strpos('BbXxNn', $char) !== false && $next === "'") {
                $type = self::STRING;
                $end = self::quotedEnd($sql, $offset + 2, "'", false);
            } elseif (strpos($identStart, $char) !== false) {
                $type = self::WORD;
                $end = $offset + 1 + strspn($sql, $identChars, $offset + 1);
            } elseif ($char === '$' && $next !== '' && strpos(self::DIGITS, $next) !== false) {
                $type = self::POSITIONAL_PARAMETER;
                $end = $offset + 1 + strspn($sql, self::DIGITS, $offset + 1);
            } elseif ($char === '$' && ($tagEnd = self::dollarTagEnd($sql, $offset, $identStart, $tagChars)) !== null) {
                $type = self::DOLLAR_STRING;
                $closer = strpos($sql, substr($sql, $offset, $tagEnd - $offset), $tagEnd);
                $end = $closer === false ? $length : $closer + $tagEnd - $offset;
            } elseif ($char === ':' && $next === ':') {
                $type = self::OPERATOR;
                $end = $offset + 2;
            } elseif ($char === ':' && $next !== '' && strpos($identStart, $next) !== false) {
                $type = self::NAMED_PARAMETER;
                $end = $offset + 2 + strspn($sql, $identChars, $offset + 2);
            } elseif ($char === ';') {
                $type = self::SEMICOLON;
                $end = $offset + 1;
            } elseif ($char === '(') {
                $type = self::OPEN_PAREN;
                $end = $offset + 1;
            } elseif ($char === ')') {
                $type = self::CLOSE_PAREN;
                $end = $offset + 1;
            } elseif (strpos(self::DIGITS, $char) !== false || ($char === '.' && $next !== '' && strpos(self::DIGITS, $next) !== false)) {
                $type = self::NUMBER;
                $end = self::numberEnd($sql, $offset);
            } elseif ($char === ':' || strpos(self::OPERATOR_CHARS, $char) !== false) {
                $type = self::OPERATOR;
                $end = $char === ':' ? $offset + 1 : self::operatorEnd($sql, $offset);
            } else {
                $type = self::OTHER;
                $end = $offset + 1;
            }

            yield ['type' => $type, 'text' => substr($sql, $offset, $end - $offset), 'offset' => $offset];

            $offset = $end;
        }
    }

    /**
     * Whether a token carries no meaning for the statement (whitespace or a comment).
     *
     * @param  Token  $token
     */
    public static function isTrivia(array $token): bool
    {
        return $token['type'] === self::WHITESPACE
            || $token['type'] === self::LINE_COMMENT
            || $token['type'] === self::BLOCK_COMMENT;
    }

    private static function blockCommentEnd(string $sql, int $offset): int
    {
        $length = strlen($sql);
        $depth = 1;
        $position = $offset + 2;

        while ($depth > 0) {
            $position += strcspn($sql, '/*', $position);

            if ($position + 1 >= $length) {
                return $length;
            }

            $pair = $sql[$position].$sql[$position + 1];

            if ($pair === '/*') {
                $depth++;
                $position += 2;
            } elseif ($pair === '*/') {
                $depth--;
                $position += 2;
            } else {
                $position++;
            }
        }

        return $position;
    }

    /**
     * The end of a quoted run that started just before `$position`. A doubled quote
     * is an escaped quote; with `$backslashes`, so is a backslash-escaped one.
     */
    private static function quotedEnd(string $sql, int $position, string $quote, bool $backslashes): int
    {
        $length = strlen($sql);
        $stops = $backslashes ? $quote.'\\' : $quote;

        while (true) {
            $position += strcspn($sql, $stops, $position);

            if ($position >= $length) {
                return $length;
            }

            if ($sql[$position] === '\\') {
                $position = min($position + 2, $length);
            } elseif ($position + 1 < $length && $sql[$position + 1] === $quote) {
                $position += 2;
            } else {
                return $position + 1;
            }
        }
    }

    /**
     * The end of the opening `$tag$` delimiter at `$offset`, or null when this `$`
     * does not open a dollar quote. A tag follows identifier rules minus `$` and
     * may not start with a digit.
     */
    private static function dollarTagEnd(string $sql, int $offset, string $identStart, string $tagChars): ?int
    {
        $position = $offset + 1;

        if ($position < strlen($sql) && strpos($identStart, $sql[$position]) !== false) {
            $position += strspn($sql, $tagChars, $position);
        }

        return $position < strlen($sql) && $sql[$position] === '$' ? $position + 1 : null;
    }

    private static function numberEnd(string $sql, int $offset): int
    {
        $length = strlen($sql);
        $position = $offset + strspn($sql, self::DIGITS.'_', $offset);

        if ($position < $length && $sql[$position] === '.' && ($position + 1 >= $length || $sql[$position + 1] !== '.')) {
            $position++;
            $position += strspn($sql, self::DIGITS.'_', $position);
        }

        if ($position + 2 < $length && ($sql[$position] === 'e' || $sql[$position] === 'E')
            && ($sql[$position + 1] === '+' || $sql[$position + 1] === '-')
            && strpos(self::DIGITS, $sql[$position + 2]) !== false) {
            $position += 2;
        }

        // Exponents, hex/octal/binary literals and trailing junk all stay in the number.
        return $position + strspn($sql, self::LETTERS.self::DIGITS, $position);
    }

    /**
     * Operator characters run together, except that `--` and `/*` always start a comment.
     */
    private static function operatorEnd(string $sql, int $offset): int
    {
        $length = strlen($sql);
        $position = $offset + 1;

        while ($position < $length && strpos(self::OPERATOR_CHARS, $sql[$position]) !== false) {
            $pair = substr($sql, $position, 2);

            if ($pair === '--' || $pair === '/*') {
                break;
            }

            $position++;
        }

        return $position;
    }
}
