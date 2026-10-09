<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Sql;

/**
 * Sorts SQL into read, dml, ddl, destructive or unknown, so the UI can pick the
 * right role hint and confirmation prompt.
 *
 * **UX only (PRD S11).** This is a best-effort keyword reading of the text, never a
 * permission check. Nothing may grant or deny access based on it: enforcement comes
 * from database privileges and the transaction mode the QueryRunner uses. Functions
 * with side effects (`SELECT nextval(…)`, `SELECT pg_terminate_backend(…)`) still
 * classify as read, and `DELETE … WHERE true` still classifies as dml.
 *
 * Rules, applied to keywords outside comments, strings and quoted identifiers:
 * - read: SELECT, VALUES, TABLE, SHOW, EXPLAIN without ANALYZE, COPY … TO STDOUT
 * - dml: INSERT, MERGE, CALL, UPDATE or DELETE with a top-level WHERE,
 *   SELECT … FOR UPDATE|SHARE, COPY … FROM STDIN
 * - ddl: CREATE, ALTER, COMMENT, GRANT, REVOKE, REINDEX, CLUSTER, REFRESH, VACUUM,
 *   ANALYZE, SECURITY LABEL, IMPORT FOREIGN SCHEMA, REASSIGN OWNED, DO,
 *   SET|RESET ROLE and SESSION AUTHORIZATION, SELECT … INTO, COPY to or from a
 *   server file or program
 * - destructive: DROP, TRUNCATE, ALTER … DROP, UPDATE or DELETE without a top-level WHERE
 * - unknown: anything else, including transaction control and plain SET
 *
 * `EXPLAIN ANALYZE` runs its statement, so it takes that statement's class. `WITH`
 * takes the most severe class of its CTE bodies and main statement.
 */
final class Classifier
{
    public const READ = 'read';

    public const DML = 'dml';

    public const DDL = 'ddl';

    public const DESTRUCTIVE = 'destructive';

    public const UNKNOWN = 'unknown';

    private const DDL_KEYWORDS = [
        'CREATE', 'COMMENT', 'GRANT', 'REVOKE', 'REINDEX', 'CLUSTER', 'REFRESH', 'VACUUM',
        'ANALYZE', 'ANALYSE', 'SECURITY', 'IMPORT', 'REASSIGN', 'DO',
    ];

    /**
     * Classify SQL that may hold several statements. Mixed input gets the most
     * severe class: destructive wins, then unknown, then ddl, dml and read.
     * Empty input, or input that is only comments, is unknown.
     */
    public static function classify(string $sql): string
    {
        return self::combine(array_column(self::statements($sql), 'class'));
    }

    /**
     * Split SQL on top-level semicolons and classify each statement. Splitting is
     * ScriptSplitter's lenient mode: semicolons inside parentheses, strings,
     * comments, dollar quotes and `BEGIN ATOMIC … END` bodies do not split, and
     * statements that are empty or only comments are skipped. It never throws.
     *
     * @return list<array{sql: string, offset: int, class: string}>
     */
    public static function statements(string $sql): array
    {
        $statements = [];

        foreach (ScriptSplitter::scan($sql) as $statement) {
            $words = [];
            $previous = '';

            foreach ($statement['tokens'] as $token) {
                $previous = $words[] = self::compact($token, $previous);
            }

            $statements[] = ['sql' => $statement['sql'], 'offset' => $statement['offset'], 'class' => self::classifyRange($words, 0, count($words))];
        }

        return $statements;
    }

    /**
     * Reduce a token to what classification needs: an upper-cased keyword, a
     * paren, a comma or dot, or '' for anything else (literals, quoted
     * identifiers, operators). A word right after `.` or `AS` is a name, not a keyword.
     *
     * @param  array{type: string, text: string, offset: int}  $token
     */
    private static function compact(array $token, string $previous): string
    {
        if ($token['type'] === Lexer::WORD) {
            return $previous === '.' || $previous === 'AS' ? '' : strtoupper($token['text']);
        }

        if ($token['type'] === Lexer::OPEN_PAREN || $token['type'] === Lexer::CLOSE_PAREN) {
            return $token['text'];
        }

        return $token['text'] === ',' || $token['text'] === '.' ? $token['text'] : '';
    }

    /**
     * @param  list<string>  $words
     */
    private static function classifyRange(array $words, int $from, int $to): string
    {
        $base = 0;

        while ($from < $to && $words[$from] === '(') {
            $from++;
            $base++;
        }

        if ($from >= $to) {
            return self::UNKNOWN;
        }

        switch ($words[$from]) {
            case 'SELECT':
                if (self::find($words, $from, $to, $base, 'INTO') !== null) {
                    return self::DDL;
                }

                return self::locksRows($words, $from, $to) ? self::DML : self::READ;
            case 'VALUES':
            case 'TABLE':
            case 'SHOW':
                return self::READ;
            case 'EXPLAIN':
                return self::classifyExplain($words, $from + 1, $to);
            case 'WITH':
                return self::classifyWith($words, $from + 1, $to);
            case 'INSERT':
            case 'MERGE':
            case 'CALL':
                return self::DML;
            case 'UPDATE':
            case 'DELETE':
                return self::find($words, $from, $to, $base, 'WHERE') !== null ? self::DML : self::DESTRUCTIVE;
            case 'DROP':
            case 'TRUNCATE':
                return self::DESTRUCTIVE;
            case 'ALTER':
                return in_array('DROP', array_slice($words, $from, $to - $from), true) ? self::DESTRUCTIVE : self::DDL;
            case 'COPY':
                return self::classifyCopy($words, $from, $to, $base);
            case 'SET':
            case 'RESET':
                return self::changesRole($words, $from + 1, $to) ? self::DDL : self::UNKNOWN;
        }

        return in_array($words[$from], self::DDL_KEYWORDS, true) ? self::DDL : self::UNKNOWN;
    }

    /**
     * `EXPLAIN` only plans its statement, unless ANALYZE makes it run it.
     *
     * @param  list<string>  $words
     */
    private static function classifyExplain(array $words, int $from, int $to): string
    {
        $analyze = false;

        if ($from < $to && $words[$from] === '(') {
            $close = self::closingParen($words, $from, $to);

            for ($i = $from + 1; $i < $close; $i++) {
                if ($words[$i] === 'ANALYZE' || $words[$i] === 'ANALYSE') {
                    $value = $i + 1 < $close ? $words[$i + 1] : '';
                    $analyze = $value !== 'FALSE' && $value !== 'OFF';
                }
            }

            $from = $close + 1;
        } else {
            while ($from < $to && in_array($words[$from], ['ANALYZE', 'ANALYSE', 'VERBOSE'], true)) {
                $analyze = $analyze || $words[$from] !== 'VERBOSE';
                $from++;
            }
        }

        return $analyze ? self::classifyRange($words, $from, $to) : self::READ;
    }

    /**
     * `WITH [RECURSIVE] name [(cols)] AS [[NOT] MATERIALIZED] (body) [SEARCH …] [CYCLE …], … main`
     *
     * @param  list<string>  $words
     */
    private static function classifyWith(array $words, int $from, int $to): string
    {
        $classes = [];
        $i = $from < $to && $words[$from] === 'RECURSIVE' ? $from + 1 : $from;

        while (true) {
            $i++; // past the CTE name

            if ($i < $to && $words[$i] === '(') {
                $i = self::closingParen($words, $i, $to) + 1;
            }

            if ($i >= $to || $words[$i] !== 'AS') {
                return self::UNKNOWN;
            }

            $i++;

            while ($i < $to && in_array($words[$i], ['', 'NOT', 'MATERIALIZED'], true)) {
                $i++;
            }

            if ($i >= $to || $words[$i] !== '(') {
                return self::UNKNOWN;
            }

            $close = self::closingParen($words, $i, $to);
            $classes[] = self::classifyRange($words, $i + 1, $close);
            $i = $close + 1;

            while ($i < $to && ! in_array($words[$i], [',', '(', 'SELECT', 'VALUES', 'TABLE', 'INSERT', 'UPDATE', 'DELETE', 'MERGE'], true)) {
                $i++; // SEARCH … / CYCLE … clauses
            }

            if ($i >= $to || $words[$i] !== ',') {
                break;
            }

            $i++; // the comma
        }

        $classes[] = self::classifyRange($words, $i, $to);

        return self::combine($classes);
    }

    /**
     * Client-side COPY is read (TO STDOUT) or dml (FROM STDIN). COPY to or from a
     * server file or program touches the server, so it is ddl.
     *
     * @param  list<string>  $words
     */
    private static function classifyCopy(array $words, int $from, int $to, int $base): string
    {
        $source = self::find($words, $from, $to, $base, 'FROM');

        if ($source !== null) {
            return ($source + 1 < $to ? $words[$source + 1] : '') === 'STDIN' ? self::DML : self::DDL;
        }

        $target = self::find($words, $from, $to, $base, 'TO');

        if ($target !== null) {
            return ($target + 1 < $to ? $words[$target + 1] : '') === 'STDOUT' ? self::READ : self::DDL;
        }

        return self::UNKNOWN;
    }

    /**
     * `SET [SESSION|LOCAL] ROLE …`, `SET [SESSION|LOCAL] SESSION AUTHORIZATION …` and their RESETs.
     *
     * @param  list<string>  $words
     */
    private static function changesRole(array $words, int $from, int $to): bool
    {
        while ($from < $to && ($words[$from] === 'SESSION' || $words[$from] === 'LOCAL')) {
            $from++;
        }

        return $from < $to && ($words[$from] === 'ROLE' || $words[$from] === 'AUTHORIZATION');
    }

    /**
     * `FOR UPDATE`, `FOR NO KEY UPDATE`, `FOR SHARE` or `FOR KEY SHARE` at any depth.
     *
     * @param  list<string>  $words
     */
    private static function locksRows(array $words, int $from, int $to): bool
    {
        for ($i = $from; $i + 1 < $to; $i++) {
            if ($words[$i] === 'FOR' && in_array($words[$i + 1], ['UPDATE', 'SHARE', 'NO', 'KEY'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The position of `$keyword` outside any parentheses opened after `$from`, or null.
     *
     * @param  list<string>  $words
     */
    private static function find(array $words, int $from, int $to, int $base, string $keyword): ?int
    {
        $depth = $base;

        for ($i = $from; $i < $to; $i++) {
            if ($words[$i] === '(') {
                $depth++;
            } elseif ($words[$i] === ')') {
                $depth--;
            } elseif ($depth <= $base && $words[$i] === $keyword) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The position of the paren closing the one at `$open`, or `$to` when it never closes.
     *
     * @param  list<string>  $words
     */
    private static function closingParen(array $words, int $open, int $to): int
    {
        $depth = 0;

        for ($i = $open; $i < $to; $i++) {
            if ($words[$i] === '(') {
                $depth++;
            } elseif ($words[$i] === ')' && --$depth === 0) {
                return $i;
            }
        }

        return $to;
    }

    /**
     * @param  array<int, string>  $classes
     */
    private static function combine(array $classes): string
    {
        foreach ([self::DESTRUCTIVE, self::UNKNOWN, self::DDL, self::DML, self::READ] as $class) {
            if (in_array($class, $classes, true)) {
                return $class;
            }
        }

        return self::UNKNOWN;
    }
}
