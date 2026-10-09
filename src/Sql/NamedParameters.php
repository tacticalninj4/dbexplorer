<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Sql;

use InvalidArgumentException;

/**
 * A saved query's `:name` placeholders become positional `?` bindings (PRD
 * §7.8), through the same lexer as the classifier and splitter, so a
 * `':name'` inside a string literal, a comment or a quoted identifier is
 * never touched, and the `::` cast operator is not a placeholder either.
 *
 * Each parameter spec is `{name, type: string|int|date|enum, options? (for
 * enum), required?}`; values are cast to the type or rejected.
 */
final class NamedParameters
{
    /**
     * Rewrite the placeholders and build the ordered bindings.
     *
     * @param  array<int, mixed>  $params  parameter specs, straight from the saved query's JSON column
     * @param  array<string, mixed>  $values
     * @return array{0: string, 1: list<scalar|null>}
     *
     * @throws InvalidArgumentException for an unknown placeholder, a missing value or a value that does not fit its type
     */
    public static function bind(string $sql, array $params, array $values): array
    {
        $specs = [];

        foreach ($params as $param) {
            if (is_array($param) && is_string($param['name'] ?? null) && $param['name'] !== '') {
                $specs[$param['name']] = $param;
            }
        }

        $names = [];
        $out = '';
        $last = 0;

        foreach (Lexer::tokens($sql) as $token) {
            if ($token['type'] !== Lexer::NAMED_PARAMETER) {
                continue;
            }

            $name = substr($token['text'], 1);

            if (! isset($specs[$name])) {
                throw new InvalidArgumentException("The query has no :{$name} parameter.");
            }

            $names[] = $name;
            $out .= substr($sql, $last, $token['offset'] - $last).'?';
            $last = $token['offset'] + strlen($token['text']);
        }

        $out .= substr($sql, $last);

        $bindings = [];

        foreach ($names as $name) {
            $spec = $specs[$name];
            $value = $values[$name] ?? null;

            if ($value === null) {
                if (($spec['required'] ?? true)) {
                    throw new InvalidArgumentException("Parameter :{$name} is required.");
                }

                $bindings[] = null;

                continue;
            }

            /** @var list<string> $options */
            $options = array_values(array_filter((array) ($spec['options'] ?? []), fn (mixed $option): bool => is_string($option)));

            $bindings[] = self::cast($name, (string) ($spec['type'] ?? 'string'), $options, $value);
        }

        return [$out, $bindings];
    }

    /**
     * @param  list<string>  $options
     *
     * @throws InvalidArgumentException when the value does not fit the type
     */
    private static function cast(string $name, string $type, array $options, mixed $value): int|string
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException("Parameter :{$name} must be a single value.");
        }

        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        if ($type === 'int') {
            if (! preg_match('/^-?\d+$/', $text)) {
                throw new InvalidArgumentException("Parameter :{$name} must be a whole number.");
            }

            return (int) $text;
        }

        if ($type === 'date') {
            $time = strtotime($text);

            if ($time === false) {
                throw new InvalidArgumentException("Parameter :{$name} must be a date.");
            }

            return date('Y-m-d', $time);
        }

        if ($type === 'enum') {
            if (! in_array($text, $options, true)) {
                throw new InvalidArgumentException("Parameter :{$name} must be one of: ".implode(', ', $options).'.');
            }

            return $text;
        }

        return $text;
    }
}
