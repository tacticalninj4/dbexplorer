<?php

declare(strict_types=1);

use TacticalNinj4\DbExplorer\Sql\Classifier;

it('classifies reads', function (string $sql) {
    expect(Classifier::classify($sql))->toBe(Classifier::READ);
})->with([
    'SELECT 1',
    'select * from users',
    'SeLeCt id FrOm users WHERE id = 1',
    "SELECT\n\tid\nFROM\tusers",
    '   SELECT 1   ',
    '(SELECT 1)',
    '((SELECT 1)) UNION (SELECT 2)',
    'VALUES (1), (2)',
    'TABLE users',
    'SHOW search_path',
    'EXPLAIN SELECT * FROM t',
    'EXPLAIN DELETE FROM t',
    'EXPLAIN VERBOSE DELETE FROM t',
    'EXPLAIN (FORMAT JSON) DROP TABLE t',
    'EXPLAIN (ANALYZE false) DELETE FROM t',
    'EXPLAIN (ANALYZE off, BUFFERS) DELETE FROM t',
    'EXPLAIN ANALYZE SELECT * FROM t',
    'WITH x AS (SELECT 1) SELECT * FROM x',
    'WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 5) SELECT * FROM r',
    'WITH a AS MATERIALIZED (SELECT 1), b AS NOT MATERIALIZED (VALUES (2)) SELECT * FROM a, b',
    'WITH RECURSIVE t(id) AS (SELECT 1) SEARCH DEPTH FIRST BY id SET ord SELECT * FROM t',
    '/* DROP TABLE t */ SELECT 1',
    '/* outer /* DROP TABLE t; */ still a comment */ SELECT 1',
    "-- DROP TABLE t\nSELECT 1",
    "SELECT 'DROP TABLE t; DELETE FROM u'",
    "SELECT E'\\';DROP TABLE t; --'",
    "SELECT 'it''s; DROP TABLE t'",
    'SELECT $$; DROP TABLE t; $$',
    'SELECT $body$ DELETE FROM t $inner$ x $inner$ $body$',
    'SELECT "drop", "delete" FROM t',
    'SELECT "where" FROM t',
    'SELECT * FROM t WHERE a = $1 AND b = $2',
    'SELECT id::text, :name FROM t WHERE created_at > :since::date',
    "SELECT U&'d\\0061t\\+000061'",
    'SELECT x INTO_NOT FROM t',
    'SELECT t.into FROM t',
    'SELECT 1 AS into',
    'COPY t TO STDOUT',
    'COPY (SELECT * FROM t) TO STDOUT WITH (FORMAT csv)',
    'SELECT substring(name FROM 1 FOR 3) FROM t',
    'SELECT 1;',
    'SELECT 1; SELECT 2;',
]);

it('classifies data changes as dml', function (string $sql) {
    expect(Classifier::classify($sql))->toBe(Classifier::DML);
})->with([
    'INSERT INTO t (a) VALUES (1)',
    'insert into t select * from u where false',
    'INSERT INTO t VALUES (1) ON CONFLICT (id) DO UPDATE SET a = 2',
    'UPDATE t SET a = 1 WHERE id = 2',
    "update t\nset a = 1\nwhere id = 2",
    'DELETE FROM t WHERE id = 1',
    'DELETE FROM t USING u WHERE t.id = u.id',
    'DELETE FROM t WHERE CURRENT OF c',
    'UPDATE t SET "where" = 1 WHERE id = 1',
    'MERGE INTO t USING s ON t.id = s.id WHEN MATCHED THEN DELETE',
    'CALL do_things(1)',
    'COPY t FROM STDIN',
    'SELECT * FROM t FOR UPDATE',
    'SELECT * FROM t FOR NO KEY UPDATE SKIP LOCKED',
    'select * from t for share',
    'SELECT * FROM t FOR KEY SHARE',
    'SELECT * FROM (SELECT * FROM t FOR UPDATE) s',
    'WITH d AS (DELETE FROM t WHERE x) SELECT 1',
    'WITH i AS (INSERT INTO t VALUES (1) RETURNING id) SELECT * FROM i',
    'WITH u AS (UPDATE t SET a = 1 WHERE b RETURNING *) SELECT * FROM u',
    'WITH x AS (SELECT id FROM t) UPDATE t SET a = 1 WHERE id IN (SELECT id FROM x)',
    'WITH x AS (SELECT 1) INSERT INTO t SELECT * FROM x',
    'EXPLAIN ANALYZE INSERT INTO t VALUES (1)',
    'EXPLAIN (ANALYZE) UPDATE t SET a = 1 WHERE id = 1',
    'EXPLAIN ANALYSE VERBOSE DELETE FROM t WHERE id = 1',
    'SELECT 1; INSERT INTO t VALUES (1)',
]);

it('classifies schema and admin changes as ddl', function (string $sql) {
    expect(Classifier::classify($sql))->toBe(Classifier::DDL);
})->with([
    'CREATE TABLE t (id int)',
    'create index concurrently i on t (a)',
    'CREATE TABLE t AS SELECT * FROM u',
    'ALTER TABLE t ADD COLUMN c int',
    'ALTER TABLE t RENAME COLUMN drop_me TO kept',
    'ALTER TABLE t ADD COLUMN "drop" int',
    "COMMENT ON TABLE t IS 'drop me'",
    'GRANT SELECT ON t TO r',
    'REVOKE ALL ON t FROM r',
    'REINDEX TABLE t',
    'CLUSTER t USING i',
    'REFRESH MATERIALIZED VIEW v',
    'VACUUM ANALYZE t',
    'ANALYZE t',
    "SECURITY LABEL ON TABLE t IS 'secret'",
    'DO $$ BEGIN DELETE FROM t; END $$',
    'SET ROLE admin',
    'SET LOCAL ROLE admin',
    'SET SESSION AUTHORIZATION postgres',
    'RESET ROLE',
    'SELECT * INTO new_table FROM t',
    'WITH x AS (SELECT 1) SELECT * INTO y FROM x',
    "COPY t FROM '/etc/passwd'",
    "COPY t TO PROGRAM 'curl example.com'",
    'CREATE FUNCTION f() RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT CASE WHEN true THEN 1 END; SELECT 2; END',
    'SELECT 1; CREATE TABLE t (id int)',
]);

it('classifies drops, truncates and unbounded writes as destructive', function (string $sql) {
    expect(Classifier::classify($sql))->toBe(Classifier::DESTRUCTIVE);
})->with([
    'DROP TABLE t',
    'drop table if exists t cascade',
    'TRUNCATE t',
    'ALTER TABLE t DROP COLUMN c',
    'ALTER TABLE t ALTER COLUMN c DROP DEFAULT',
    'DELETE FROM t',
    'delete from t;',
    'UPDATE t SET a = 1',
    'DELETE FROM t -- WHERE id = 1',
    'DELETE FROM t /* WHERE id = 1 */',
    "DELETE FROM t RETURNING 'WHERE'",
    'DELETE FROM "where"',
    'UPDATE t SET a = (SELECT b FROM u WHERE u.id = 1)',
    'DELETE FROM t WHERE_NOT',
    'DELETE FROM t USING u',
    'DELETE FROM t RETURNING t.where',
    '/* SELECT */ DROP TABLE t',
    "/* a /* b */ c */\n\tDrOp TaBlE t",
    'WITH d AS (DELETE FROM t) SELECT 1',
    'WITH d AS (UPDATE t SET a = 1 RETURNING *) SELECT * FROM d',
    'WITH x AS (SELECT * FROM u WHERE y) DELETE FROM t',
    'WITH a AS (SELECT 1), d AS (DELETE FROM t) SELECT * FROM a',
    'EXPLAIN ANALYZE DELETE FROM t',
    'EXPLAIN (ANALYZE, BUFFERS) UPDATE t SET a = 1',
    'EXPLAIN (ANALYZE true) TRUNCATE t',
    'SELECT 1; DROP TABLE t',
    'SELECT 1; DROP TABLE t;',
    "SELECT 'x'; -- comment\nTRUNCATE t",
    'BEGIN; DELETE FROM t; COMMIT',
    'SELECT $1; DROP TABLE t',
    'SELECT $a$ x $a$; DROP TABLE t',
    'CREATE FUNCTION f() RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT 1; END; DROP TABLE t',
]);

it('classifies transaction control, unrecognised and empty input as unknown', function (string $sql) {
    expect(Classifier::classify($sql))->toBe(Classifier::UNKNOWN);
})->with([
    '',
    '   ',
    ';',
    ' ; ; ',
    '-- only a comment',
    '/* only a comment */',
    '/* unterminated comment DROP TABLE t',
    'BEGIN',
    'COMMIT',
    'ROLLBACK',
    'SAVEPOINT s',
    'START TRANSACTION',
    'SET statement_timeout = 0',
    'LISTEN channel',
    'PREPARE p AS DELETE FROM t',
    '"drop" TABLE t',
    "'DROP TABLE t'",
    '$$DROP TABLE t$$',
    '42',
    'WITH x SELECT 1',
    'WITH x AS SELECT 1',
    'BEGIN; SELECT 1; COMMIT',
    'SELECT 1; LISTEN c; INSERT INTO t VALUES (1)',
]);

it('never crashes on unterminated input', function (string $sql, string $expected) {
    expect(Classifier::classify($sql))->toBe($expected);
})->with([
    ["SELECT 'unterminated; DROP TABLE t", Classifier::READ],
    ["SELECT E'unterminated\\", Classifier::READ],
    ['SELECT "unterminated; DROP TABLE t', Classifier::READ],
    ['SELECT $$ unterminated; DROP TABLE t', Classifier::READ],
    ['DELETE FROM t WHERE (a = 1', Classifier::DML],
    ['SELECT (1; DROP TABLE t', Classifier::READ],
    ['SELECT 1); DROP TABLE t', Classifier::DESTRUCTIVE],
    ['WITH d AS (DELETE FROM t', Classifier::DESTRUCTIVE],
    ['EXPLAIN (ANALYZE', Classifier::UNKNOWN],
]);

it('tells DDL, including destructive DDL, from destructive DML', function (string $sql, bool $ddl) {
    expect(Classifier::isDdl($sql))->toBe($ddl);
})->with([
    ['CREATE TABLE t (id int)', true],
    ['DROP TABLE t', true],
    ['(DROP TABLE t)', true],
    ['truncate t', true],
    ['ALTER TABLE t DROP COLUMN c', true],
    ['ALTER TABLE t ADD COLUMN c int', true],
    ['/* x */ DROP TABLE t', true],
    ['SELECT 1; DROP TABLE t', true],
    ['DELETE FROM t', false],
    ['UPDATE t SET a = 1', false],
    ['WITH d AS (DELETE FROM t RETURNING *) SELECT 1', false],
    ['DELETE FROM t WHERE id = 1', false],
    ['SELECT 1', false],
    ['LOCK TABLE t', false],
    ['', false],
]);

it('returns each statement with its offset, text and class', function () {
    $sql = "-- lead\nSELECT 1;\n\n  DELETE FROM t /* all */ ;; -- trailing\n";

    expect(Classifier::statements($sql))->toBe([
        ['sql' => "-- lead\nSELECT 1", 'offset' => 0, 'class' => Classifier::READ],
        ['sql' => 'DELETE FROM t /* all */', 'offset' => 21, 'class' => Classifier::DESTRUCTIVE],
    ]);
});

it('does not split inside parentheses, strings or BEGIN ATOMIC bodies', function () {
    $sql = "SELECT ';'; CREATE PROCEDURE p() LANGUAGE sql BEGIN ATOMIC INSERT INTO t VALUES (1); SELECT CASE WHEN true THEN 1 END; END; DELETE FROM t WHERE id = 1";

    expect(array_column(Classifier::statements($sql), 'class'))
        ->toBe([Classifier::READ, Classifier::DDL, Classifier::DML]);
});

it('returns no statements for empty or comment-only input', function () {
    expect(Classifier::statements(''))->toBe([])
        ->and(Classifier::statements("-- a\n/* b */ ;"))->toBe([]);
});

it('classifies about 1 MB of SQL in under a second', function () {
    $sql = str_repeat("INSERT INTO t (a, b) VALUES (1, 'x''y'), (2, E'z\\'q'); -- note\nSELECT * FROM t WHERE a = \$1 AND b::text = :name /* c */;\n", 9000)
        .'DELETE FROM t';

    $started = microtime(true);
    $class = Classifier::classify($sql);

    expect(strlen($sql))->toBeGreaterThan(1_000_000)
        ->and($class)->toBe(Classifier::DESTRUCTIVE)
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});
