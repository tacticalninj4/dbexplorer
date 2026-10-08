<?php

declare(strict_types=1);

use TacticalNinj4\DbExplorer\Sql\Lexer;

/**
 * The input as [type, text] pairs, without whitespace.
 *
 * @return list<array{0: string, 1: string}>
 */
function lexPairs(string $sql): array
{
    $pairs = [];

    foreach (Lexer::tokenize($sql) as $token) {
        if ($token['type'] !== Lexer::WHITESPACE) {
            $pairs[] = [$token['type'], $token['text']];
        }
    }

    return $pairs;
}

it('reads a single token of each kind', function (string $sql, string $type) {
    expect(lexPairs($sql))->toBe([[$type, $sql]]);
})->with([
    ['-- comment ; DROP', Lexer::LINE_COMMENT],
    ['/* comment */', Lexer::BLOCK_COMMENT],
    ['/* a /* nested */ DROP TABLE t; */', Lexer::BLOCK_COMMENT],
    ['/* unterminated /* nested */ DROP', Lexer::BLOCK_COMMENT],
    ["'it''s'", Lexer::STRING],
    ["'unterminated; DROP", Lexer::STRING],
    ["E'\\';DROP'", Lexer::STRING],
    ["e'a\\\\'", Lexer::STRING],
    ["E'it''s'", Lexer::STRING],
    ["E'ends in backslash\\", Lexer::STRING],
    ["U&'d\\0061t'", Lexer::STRING],
    ["B'1010'", Lexer::STRING],
    ["X'1F'", Lexer::STRING],
    ["N'national'", Lexer::STRING],
    ['$$ ; DROP TABLE t; $$', Lexer::DOLLAR_STRING],
    ['$fn$ a $$ b $$ c $fn$', Lexer::DOLLAR_STRING],
    ['$a$ $b$ ; $b$ $a$', Lexer::DOLLAR_STRING],
    ['$_x1$ body $_x1$', Lexer::DOLLAR_STRING],
    ['$$ unterminated', Lexer::DOLLAR_STRING],
    ['"drop"', Lexer::QUOTED_IDENTIFIER],
    ['"we""ird"', Lexer::QUOTED_IDENTIFIER],
    ['U&"d\\0061t"', Lexer::QUOTED_IDENTIFIER],
    ['"unterminated', Lexer::QUOTED_IDENTIFIER],
    ['$1', Lexer::POSITIONAL_PARAMETER],
    ['$12', Lexer::POSITIONAL_PARAMETER],
    [':name', Lexer::NAMED_PARAMETER],
    [':user_id2', Lexer::NAMED_PARAMETER],
    ['SELECT', Lexer::WORD],
    ['a$b', Lexer::WORD],
    ['café', Lexer::WORD],
    ['_tmp', Lexer::WORD],
    ['42', Lexer::NUMBER],
    ['3.14', Lexer::NUMBER],
    ['.5', Lexer::NUMBER],
    ['1e-5', Lexer::NUMBER],
    ['0xFF', Lexer::NUMBER],
    ['1_000', Lexer::NUMBER],
    ['::', Lexer::OPERATOR],
    ['<>', Lexer::OPERATOR],
    ['@>', Lexer::OPERATOR],
    [';', Lexer::SEMICOLON],
    ['(', Lexer::OPEN_PAREN],
    [')', Lexer::CLOSE_PAREN],
    [',', Lexer::OTHER],
    ['.', Lexer::OTHER],
]);

it('tells a cast from a named parameter', function () {
    expect(lexPairs('a::text = :b::int'))->toBe([
        [Lexer::WORD, 'a'],
        [Lexer::OPERATOR, '::'],
        [Lexer::WORD, 'text'],
        [Lexer::OPERATOR, '='],
        [Lexer::NAMED_PARAMETER, ':b'],
        [Lexer::OPERATOR, '::'],
        [Lexer::WORD, 'int'],
    ]);
});

it('tells a positional parameter from a dollar quote', function () {
    expect(lexPairs('$1 $$x$$ $2$ $'))->toBe([
        [Lexer::POSITIONAL_PARAMETER, '$1'],
        [Lexer::DOLLAR_STRING, '$$x$$'],
        [Lexer::POSITIONAL_PARAMETER, '$2'],
        [Lexer::OTHER, '$'],
        [Lexer::OTHER, '$'],
    ]);
});

it('keeps a named parameter inside a string literal as part of the string', function () {
    expect(lexPairs("SELECT ':name', \":id\", \$\$:x\$\$"))->toBe([
        [Lexer::WORD, 'SELECT'],
        [Lexer::STRING, "':name'"],
        [Lexer::OTHER, ','],
        [Lexer::QUOTED_IDENTIFIER, '":id"'],
        [Lexer::OTHER, ','],
        [Lexer::DOLLAR_STRING, '$$:x$$'],
    ]);
});

it('starts a comment inside an operator run', function () {
    expect(lexPairs("a=--b\n*/*c*/1"))->toBe([
        [Lexer::WORD, 'a'],
        [Lexer::OPERATOR, '='],
        [Lexer::LINE_COMMENT, '--b'],
        [Lexer::OPERATOR, '*'],
        [Lexer::BLOCK_COMMENT, '/*c*/'],
        [Lexer::NUMBER, '1'],
    ]);
});

it('ends a line comment at the newline', function () {
    expect(lexPairs("SELECT 1 -- WHERE x\nWHERE y"))->toBe([
        [Lexer::WORD, 'SELECT'],
        [Lexer::NUMBER, '1'],
        [Lexer::LINE_COMMENT, '-- WHERE x'],
        [Lexer::WORD, 'WHERE'],
        [Lexer::WORD, 'y'],
    ]);
});

it('treats a prefix letter inside a longer word as part of the word', function () {
    expect(lexPairs("name'x'"))->toBe([
        [Lexer::WORD, 'name'],
        [Lexer::STRING, "'x'"],
    ]);
});

it('records offsets that reproduce the input exactly', function (string $sql) {
    $rebuilt = '';

    foreach (Lexer::tokenize($sql) as $token) {
        expect($token['offset'])->toBe(strlen($rebuilt));
        $rebuilt .= $token['text'];
    }

    expect($rebuilt)->toBe($sql);
})->with([
    "SELECT a, 'b''c', E'\\'', \$t\$x\$t\$, \"q\"\"i\" -- c\n/* /* n */ */ FROM t WHERE x::int = :p AND y = \$1;",
    "\t\r\n\f\v",
    "'unterminated",
    '/* unterminated',
    'ü€ ✓ 🙂',
]);

it('flags whitespace and comments as trivia', function () {
    $trivia = array_map(
        fn (array $token): bool => Lexer::isTrivia($token),
        Lexer::tokenize("SELECT -- c\n/* b */ 1"),
    );

    expect($trivia)->toBe([false, true, true, true, true, true, false]);
});

it('lexes about 1 MB in linear time', function () {
    $sql = str_repeat("SELECT 'a''b', \$\$x\$\$, \"c\" /* d */ -- e\n;", 26000);
    $count = 0;
    $started = microtime(true);

    foreach (Lexer::tokens($sql) as $token) {
        $count++;
    }

    expect(strlen($sql))->toBeGreaterThan(1_000_000)
        ->and($count)->toBe(26000 * 15)
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});
