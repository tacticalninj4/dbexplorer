<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Exceptions;

use TacticalNinj4\DbExplorer\Sql\Lexer;

/**
 * A query was rejected because its plan reads or writes a hidden relation (S13).
 *
 * It looks exactly like PostgreSQL's own "relation does not exist" error
 * (SQLSTATE 42P01, same message and position), so the response doesn't confirm
 * that the hidden object exists. Only the audit log records the real reason.
 */
class HiddenObjectReferenced extends QueryFailed
{
    /** The hidden relation, as `schema.name`. For the audit log only. */
    public string $relation = '';

    public static function relation(string $schema, string $name, string $sql): self
    {
        [$written, $position, $line] = self::reference($sql, $schema, $name);

        $e = new self('relation "'.($written ?? $schema.'.'.$name).'" does not exist', '42P01', $position, $line);
        $e->relation = $schema.'.'.$name;

        return $e;
    }

    /**
     * Where the SQL names the relation, the way PostgreSQL reports a missing
     * one: the folded name (qualified when written qualified), and the 1-based
     * character position and line of its first token. Nulls when the SQL doesn't
     * name it, e.g. when it's read through a view.
     *
     * @return array{0: string|null, 1: int|null, 2: int|null}
     */
    private static function reference(string $sql, string $schema, string $name): array
    {
        $previous = [];
        $fallback = [null, null, null];

        foreach (Lexer::tokens($sql) as $token) {
            if (Lexer::isTrivia($token)) {
                continue;
            }

            if (self::identifier($token) === $name) {
                [$dot, $qualifier] = [$previous[1] ?? null, $previous[0] ?? null];

                if ($dot !== null && $dot['text'] === '.' && $qualifier !== null && self::identifier($qualifier) === $schema) {
                    return [$schema.'.'.$name, ...self::locate($sql, $qualifier['offset'])];
                }

                $fallback = $fallback[0] === null ? [$name, ...self::locate($sql, $token['offset'])] : $fallback;
            }

            $previous = [$previous[1] ?? null, $token];
        }

        return $fallback;
    }

    /**
     * The identifier a token names: unquoted words fold to lower case.
     *
     * @param  array{type: string, text: string, offset: int}  $token
     */
    private static function identifier(array $token): ?string
    {
        if ($token['type'] === Lexer::WORD) {
            return strtolower($token['text']);
        }

        if ($token['type'] === Lexer::QUOTED_IDENTIFIER && str_starts_with($token['text'], '"')) {
            return str_replace('""', '"', substr($token['text'], 1, -1));
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function locate(string $sql, int $offset): array
    {
        $before = substr($sql, 0, $offset);

        return [mb_strlen($before) + 1, substr_count($before, "\n") + 1];
    }
}
