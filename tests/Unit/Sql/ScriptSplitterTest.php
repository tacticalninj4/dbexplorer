<?php

declare(strict_types=1);

use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Exceptions\ScriptParseException;
use TacticalNinj4\DbExplorer\Sql\ScriptSplitter;

/**
 * The statements' SQL only.
 *
 * @return list<string>
 */
function splitSql(string $sql): array
{
    return array_column(ScriptSplitter::split($sql), 'sql');
}

/**
 * The ScriptParseException split() throws for $sql.
 */
function splitError(string $sql): ScriptParseException
{
    try {
        ScriptSplitter::split($sql);
    } catch (ScriptParseException $e) {
        return $e;
    }

    throw new RuntimeException('split() did not throw for: '.$sql);
}

it('splits on top-level semicolons only', function (string $sql, array $expected) {
    expect(splitSql($sql))->toBe($expected);
})->with([
    'plain' => ['SELECT 1; SELECT 2;', ['SELECT 1', 'SELECT 2']],
    'no trailing semicolon' => ['SELECT 1; SELECT 2', ['SELECT 1', 'SELECT 2']],
    'single without semicolon' => ['SELECT 1', ['SELECT 1']],
    'string' => ["SELECT ';'; SELECT 2", ["SELECT ';'", 'SELECT 2']],
    'doubled quote' => ["SELECT 'it''s; fine'; SELECT 2", ["SELECT 'it''s; fine'", 'SELECT 2']],
    'E string escaped quote' => ["SELECT E'\\';'; SELECT 2", ["SELECT E'\\';'", 'SELECT 2']],
    'E string escaped backslash' => ["SELECT E'\\\\'; SELECT 2", ["SELECT E'\\\\'", 'SELECT 2']],
    'standard string backslash is literal' => ["SELECT '\\'; SELECT 2", ["SELECT '\\'", 'SELECT 2']],
    'U& string' => ["SELECT U&'d;\\0061'; SELECT 2", ["SELECT U&'d;\\0061'", 'SELECT 2']],
    'bit and hex strings' => ["SELECT B'1;0', X'1;F'; SELECT 2", ["SELECT B'1;0', X'1;F'", 'SELECT 2']],
    'dollar quote' => ['SELECT $$a;b$$; SELECT 2', ['SELECT $$a;b$$', 'SELECT 2']],
    'tagged dollar quote' => ['SELECT $fn$ ; $$ ; $fn$; SELECT 2', ['SELECT $fn$ ; $$ ; $fn$', 'SELECT 2']],
    'nested dollar tags' => ['SELECT $tag$ $other$ ; $other$ $tag$; SELECT 2', ['SELECT $tag$ $other$ ; $other$ $tag$', 'SELECT 2']],
    'positional params are not dollar quotes' => ['SELECT $1; SELECT $2', ['SELECT $1', 'SELECT $2']],
    'quoted identifier' => ['SELECT 1 AS "semi;colon"; SELECT 2', ['SELECT 1 AS "semi;colon"', 'SELECT 2']],
    'quoted identifier with doubled quote' => ['SELECT 1 AS "a"";b"; SELECT 2', ['SELECT 1 AS "a"";b"', 'SELECT 2']],
    'line comment' => ["SELECT 1 -- not ; here\n; SELECT 2", ['SELECT 1 -- not ; here', 'SELECT 2']],
    'block comment' => ['SELECT 1 /* ; */; SELECT 2', ['SELECT 1 /* ; */', 'SELECT 2']],
    'nested block comment' => ['SELECT 1 /* a /* ; */ ; */; SELECT 2', ['SELECT 1 /* a /* ; */ ; */', 'SELECT 2']],
    'comment marker inside string' => ["SELECT '--'; SELECT '/*'; SELECT 3", ["SELECT '--'", "SELECT '/*'", 'SELECT 3']],
    'string marker inside comment' => ["SELECT 1 -- it's\n; SELECT 2", ["SELECT 1 -- it's", 'SELECT 2']],
    'parens' => ['INSERT INTO t VALUES (1, (2)); SELECT 2', ['INSERT INTO t VALUES (1, (2))', 'SELECT 2']],
    'cast is not a parameter' => ['SELECT 1::text; SELECT 2', ['SELECT 1::text', 'SELECT 2']],
    'empty statements' => [';;SELECT 1;;; ;SELECT 2;;', ['SELECT 1', 'SELECT 2']],
    'comment-only statements' => ["-- a\n; /* b */ ; SELECT 1; -- end\n", ['SELECT 1']],
]);

it('keeps BEGIN ATOMIC bodies together', function (string $sql, array $expected) {
    expect(splitSql($sql))->toBe($expected);
})->with([
    'function' => [
        'CREATE FUNCTION f() RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT 1; SELECT 2; END; SELECT 3',
        ['CREATE FUNCTION f() RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT 1; SELECT 2; END', 'SELECT 3'],
    ],
    'procedure with CASE … END inside' => [
        'CREATE OR REPLACE PROCEDURE p() LANGUAGE sql BEGIN ATOMIC INSERT INTO t VALUES (CASE WHEN true THEN 1 END); END; SELECT 2',
        ['CREATE OR REPLACE PROCEDURE p() LANGUAGE sql BEGIN ATOMIC INSERT INTO t VALUES (CASE WHEN true THEN 1 END); END', 'SELECT 2'],
    ],
    'lower case' => [
        "create function f() returns int language sql\nbegin atomic\n  select 1;\nend;\nselect 2;",
        ["create function f() returns int language sql\nbegin atomic\n  select 1;\nend", 'select 2'],
    ],
    'a column named end is not END' => [
        'CREATE FUNCTION f() RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT t.end FROM t; SELECT 1 AS end; END; SELECT 2',
        ['CREATE FUNCTION f() RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT t.end FROM t; SELECT 1 AS end; END', 'SELECT 2'],
    ],
    'transaction BEGIN is not ATOMIC' => [
        'BEGIN; SELECT 1; COMMIT;',
        ['BEGIN', 'SELECT 1', 'COMMIT'],
    ],
]);

it('keeps dollar-quoted function bodies together', function () {
    $function = <<<'SQL'
CREATE FUNCTION bump(n int) RETURNS int AS $$
BEGIN
    UPDATE counters SET value = value + n; -- ;
    RETURN n;
END;
$$ LANGUAGE plpgsql
SQL;
    $do = 'DO $body$ BEGIN PERFORM 1; RAISE NOTICE $n$;$n$; END $body$';

    expect(splitSql($function.";\n".$do.";\nSELECT bump(1);"))->toBe([$function, $do, 'SELECT bump(1)']);
});

it('returns exact slices with byte offsets and starting lines', function () {
    $sql = "-- lead\nSELECT 1;\n\n  DELETE FROM t /* all */ ;; -- trailing\nSELECT\n  'a\nb';\nSELECT 4";

    expect(ScriptSplitter::split($sql))->toBe([
        ['sql' => "-- lead\nSELECT 1", 'offset' => 0, 'line' => 1],
        ['sql' => 'DELETE FROM t /* all */', 'offset' => 21, 'line' => 4],
        ['sql' => "-- trailing\nSELECT\n  'a\nb'", 'offset' => 48, 'line' => 4],
        ['sql' => 'SELECT 4', 'offset' => 76, 'line' => 8],
    ]);

    foreach (ScriptSplitter::split($sql) as $statement) {
        expect(substr($sql, $statement['offset'], strlen($statement['sql'])))->toBe($statement['sql']);
    }
});

it('counts lines with CRLF line endings', function () {
    $sql = "SELECT 1;\r\n\r\nSELECT\r\n 2;\r\nSELECT 3\r\n";

    expect(ScriptSplitter::split($sql))->toBe([
        ['sql' => 'SELECT 1', 'offset' => 0, 'line' => 1],
        ['sql' => "SELECT\r\n 2", 'offset' => 13, 'line' => 3],
        ['sql' => 'SELECT 3', 'offset' => 26, 'line' => 5],
    ]);
});

it('counts byte offsets after multibyte text', function () {
    $sql = "SELECT 'é;ü';\nSELECT 2";

    expect(ScriptSplitter::split($sql)[1])->toBe(['sql' => 'SELECT 2', 'offset' => 16, 'line' => 2]);
});

it('returns no statements for empty, blank or comment-only scripts', function (string $sql) {
    expect(ScriptSplitter::split($sql))->toBe([]);
})->with([
    'empty' => [''],
    'blank' => ["  \n\t\r\n"],
    'semicolons' => [';;;'],
    'comments' => ["-- a\n/* b /* c */ */ ;\n-- d"],
]);

it('refuses scripts it cannot split with certainty, pointing at the offending spot', function (string $sql, string $message, int $offset, int $line) {
    $e = splitError($sql);

    expect($e)->toBeInstanceOf(QueryFailed::class)
        ->and($e->getMessage())->toContain($message)
        ->and($e->getMessage())->toContain('(line '.$line.')')
        ->and($e->offset)->toBe($offset)
        ->and($e->lineNumber)->toBe($line)
        ->and($e->sqlState)->toBe('42601');
})->with([
    'unterminated string' => ["SELECT 1;\nSELECT 'oops; DROP TABLE t;", 'Unterminated string literal', 17, 2],
    'string ending in a doubled quote' => ["SELECT 'it''", 'Unterminated string literal', 7, 1],
    'E string ending in an escaped quote' => ["SELECT E'\\'", 'Unterminated string literal', 7, 1],
    'E string ending in a backslash' => ["SELECT E'abc\\", 'Unterminated string literal', 7, 1],
    'unterminated quoted identifier' => ['SELECT "semi;colon; DROP TABLE t', 'Unterminated quoted identifier', 7, 1],
    'unterminated $$' => ["SELECT 1;\n\nDO \$\$ BEGIN PERFORM 1; END;\nSELECT 2;", 'Unterminated dollar-quoted string', 14, 3],
    'lone $$' => ['SELECT $$', 'Unterminated dollar-quoted string', 7, 1],
    'mismatched dollar tags' => ['SELECT $a$ x $b$; SELECT 2', 'Unterminated dollar-quoted string', 7, 1],
    'unterminated block comment' => ["SELECT 1; /* a /* b */\nDROP TABLE t;", 'Unterminated block comment', 10, 1],
    'unclosed paren at end' => ["SELECT 1;\nINSERT INTO t VALUES ((1, 2)", 'this ( is never closed', 31, 2],
    'semicolon inside parens' => ["SELECT (1;\nDROP TABLE t; SELECT 2)", 'still open at the ; on line 1', 7, 1],
    'semicolon inside nested parens' => ["SELECT 1;\nSELECT f((1,\n2;\n))", 'still open at the ; on line 3', 19, 2],
    'stray close paren' => ['SELECT 1); DROP TABLE t', 'this ) closes nothing', 8, 1],
    'ATOMIC without END' => ["CREATE FUNCTION f() RETURNS int LANGUAGE sql\nBEGIN ATOMIC SELECT 1; SELECT 2;", 'BEGIN ATOMIC body has no closing END', 51, 2],
    'psql \\set' => ["\\set id 1\nSELECT :id;", 'psql meta-commands such as \\set', 0, 1],
    'psql \\i' => ["SELECT 1;\n\\i other.sql\n", 'psql meta-commands such as \\i', 10, 2],
    'psql \\copy' => ["\\copy t FROM 'data.csv' CSV", 'psql meta-commands such as \\copy', 0, 1],
    'psql \\gset after a query' => ['SELECT 1 AS one \\gset', 'psql meta-commands such as \\gset', 16, 1],
    'COPY FROM STDIN with data' => ["COPY t (a, b) FROM STDIN;\n1\tx;y\n\\.\nSELECT 2;", 'COPY … FROM STDIN', 19, 1],
    'COPY FROM stdin lower case' => ["SELECT 1;\ncopy t from stdin with (format csv);\n1,O'Brien\n\\.", 'COPY … FROM STDIN', 22, 2],
]);

it('still splits COPY that does not read inline data', function () {
    expect(splitSql("COPY t TO STDOUT; COPY t FROM '/tmp/x'; SELECT 2"))
        ->toBe(['COPY t TO STDOUT', "COPY t FROM '/tmp/x'", 'SELECT 2']);
});

it('accepts a backslash inside strings, identifiers and comments', function () {
    expect(splitSql("SELECT '\\set', E'\\\\', \"a\\b\" -- \\i x\n; /* \\copy */ SELECT 2"))
        ->toBe(["SELECT '\\set', E'\\\\', \"a\\b\" -- \\i x", '/* \\copy */ SELECT 2']);
});

it('reports multibyte positions as characters and offsets as bytes', function () {
    $e = splitError("SELECT 'éé'; SELECT (");

    expect($e->offset)->toBe(22)
        ->and($e->position)->toBe(21);
});

it('splits about 1 MB of SQL in under a second', function () {
    $sql = str_repeat("INSERT INTO t (a, b) VALUES (1, 'x''y;'), (2, E'z\\';q'); -- note;\nSELECT \$\$a;b\$\$, \"c;d\" FROM t /* ; */ WHERE a = \$1;\n", 9000)
        .'DELETE FROM t';

    $started = microtime(true);
    $statements = ScriptSplitter::split($sql);

    expect(strlen($sql))->toBeGreaterThan(1_000_000)
        ->and($statements)->toHaveCount(18001)
        ->and($statements[18000])->toBe(['sql' => 'DELETE FROM t', 'offset' => strlen($sql) - 13, 'line' => 18001])
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});
