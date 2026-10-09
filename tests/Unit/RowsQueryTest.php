<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\RowsQuery;

/**
 * A posts-like table: key `id`, nullable `published_at`, masked `secret`.
 *
 * @param  list<string>|null  $key
 */
function postsQuery(?array $key = ['id'], bool $ctid = true): RowsQuery
{
    return new RowsQuery('fixture', 'posts', [
        ['name' => 'id', 'nullable' => false],
        ['name' => 'title', 'nullable' => false],
        ['name' => 'price', 'nullable' => false],
        ['name' => 'published_at', 'nullable' => true],
        ['name' => 'we"ird', 'nullable' => true],
        ['name' => 'secret', 'nullable' => true],
    ], $key, $ctid, ['secret']);
}

function tagsQuery(): RowsQuery
{
    return new RowsQuery('fixture', 'post_tags', [
        ['name' => 'post_id', 'nullable' => false],
        ['name' => 'tag_id', 'nullable' => false],
    ], ['post_id', 'tag_id']);
}

/**
 * The validation errors $callback throws, keyed by field; fails when it doesn't throw.
 *
 * @return array<string, array<int, string>>
 */
function rowsErrors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        expect($e->status)->toBe(422);

        return $e->errors();
    }

    throw new RuntimeException('Expected a ValidationException.');
}

const ROWS_POSTS_SELECT = 'SELECT "id", "title", "price", "published_at", "we""ird", "secret" FROM "fixture"."posts"';

it('S12 selects every introspected column, quoted, and pages by key by default', function () {
    [$sql, $bindings, $meta] = postsQuery()->build([], 1000);

    expect($sql)->toBe(ROWS_POSTS_SELECT.' ORDER BY "id" LIMIT ?')
        ->and($bindings)->toBe([101])
        ->and($meta)->toBe([
            'pagination' => 'keyset',
            'limit' => 100,
            'offset' => null,
            'sort' => null,
            'direction' => 'asc',
            'cursor_columns' => ['id'],
            'count' => null,
        ]);
});

it('caps the default page size at the role row limit', function () {
    [, $bindings, $meta] = postsQuery()->build([], 40);

    expect($bindings)->toBe([41])->and($meta['limit'])->toBe(40);
});

it('takes a page size up to the role row limit', function (mixed $limit, int $expected) {
    [, $bindings, $meta] = postsQuery()->build(['limit' => $limit], 1000);

    expect($meta['limit'])->toBe($expected)->and($bindings)->toBe([$expected + 1]);
})->with([
    'one' => ['1', 1],
    'the cap' => ['1000', 1000],
    'an int' => [25, 25],
    'empty means default' => ['', 100],
]);

it('rejects a page size outside 1 to the role row limit', function (mixed $limit) {
    expect(rowsErrors(fn () => postsQuery()->build(['limit' => $limit], 1000)))->toHaveKey('limit');
})->with([
    'zero' => '0',
    'negative' => '-1',
    'over the cap' => '1001',
    'not a number' => 'abc',
    'decimal' => '1.5',
    'exponent' => '1e3',
    'padded' => ' 5',
    'array' => [['10']],
    'huge' => '99999999999999999999',
]);

it('sorts by the key column in either direction', function () {
    [$sql] = postsQuery()->build(['sort' => 'id', 'direction' => 'desc'], 1000);

    expect($sql)->toBe(ROWS_POSTS_SELECT.' ORDER BY "id" DESC LIMIT ?');
});

it('breaks ties on a non-key sort column with the key', function () {
    [$sql, , $meta] = postsQuery()->build(['sort' => 'title'], 1000);

    expect($sql)->toBe(ROWS_POSTS_SELECT.' ORDER BY "title", "id" LIMIT ?')
        ->and($meta['cursor_columns'])->toBe(['title', 'id'])
        ->and($meta['sort'])->toBe('title')
        ->and($meta['direction'])->toBe('asc');
});

it('puts NULLs last in both directions when the sort column is nullable', function (string $direction, string $order) {
    [$sql] = postsQuery()->build(['sort' => 'published_at', 'direction' => $direction], 1000);

    expect($sql)->toBe(ROWS_POSTS_SELECT." ORDER BY {$order} LIMIT ?");
})->with([
    ['asc', '"published_at" NULLS LAST, "id"'],
    ['desc', '"published_at" DESC NULLS LAST, "id" DESC'],
]);

it('quotes a column name holding a double quote through the one quoting helper', function () {
    [$sql] = postsQuery()->build(['sort' => 'we"ird'], 1000);

    expect(RowsQuery::quoteIdentifier('we"ird'))->toBe('"we""ird"')
        ->and(RowsQuery::quoteIdentifier('a"; DROP TABLE x; --'))->toBe('"a""; DROP TABLE x; --"')
        ->and($sql)->toEndWith('ORDER BY "we""ird" NULLS LAST, "id" LIMIT ?');
});

it('continues after the cursor with a row comparison on the key', function (string $direction, string $comparison) {
    $cursor = RowsQuery::encodeCursor([42], null, $direction);

    [$sql, $bindings] = postsQuery()->build(['cursor' => $cursor, 'direction' => $direction], 1000);

    expect($sql)->toContain(" WHERE ((\"id\") {$comparison} (?)) ORDER BY")
        ->and($bindings)->toBe(['42', 101]);
})->with([['asc', '>'], ['desc', '<']]);

it('continues a sort on a NOT NULL column with (sort, key) > (?, ?)', function () {
    $cursor = RowsQuery::encodeCursor(['Post 9', 9], 'title', 'asc');

    [$sql, $bindings] = postsQuery()->build(['sort' => 'title', 'cursor' => $cursor], 1000);

    expect($sql)->toBe(ROWS_POSTS_SELECT.' WHERE (("title", "id") > (?, ?)) ORDER BY "title", "id" LIMIT ?')
        ->and($bindings)->toBe(['Post 9', '9', 101]);
});

it('keeps the NULL tail after a non-NULL cursor on a nullable sort column', function () {
    $cursor = RowsQuery::encodeCursor(['2026-02-02 12:00:00+00', 1], 'published_at', 'desc');

    [$sql, $bindings] = postsQuery()->build(['sort' => 'published_at', 'direction' => 'desc', 'cursor' => $cursor], 1000);

    expect($sql)->toContain(' WHERE (("published_at", "id") < (?, ?) OR "published_at" IS NULL) ORDER BY')
        ->and($bindings)->toBe(['2026-02-02 12:00:00+00', '1', 101]);
});

it('pages through the NULL tail by key once the cursor sits in it', function () {
    $cursor = RowsQuery::encodeCursor([null, 8], 'published_at', 'asc');

    [$sql, $bindings] = postsQuery()->build(['sort' => 'published_at', 'cursor' => $cursor], 1000);

    expect($sql)->toContain(' WHERE ("published_at" IS NULL AND ("id") > (?)) ORDER BY')
        ->and($bindings)->toBe(['8', 101]);
});

it('pages a composite key in key order', function () {
    $cursor = RowsQuery::encodeCursor([3, 1], null, 'asc');

    [$sql, $bindings, $meta] = tagsQuery()->build(['cursor' => $cursor, 'limit' => '10'], 1000);

    expect($sql)->toBe('SELECT "post_id", "tag_id" FROM "fixture"."post_tags" WHERE (("post_id", "tag_id") > (?, ?)) ORDER BY "post_id", "tag_id" LIMIT ?')
        ->and($bindings)->toBe(['3', '1', 11])
        ->and($meta['cursor_columns'])->toBe(['post_id', 'tag_id']);
});

it('does not repeat a key column used as the sort column', function () {
    [$sql, , $meta] = tagsQuery()->build(['sort' => 'tag_id', 'direction' => 'desc'], 1000);

    expect($sql)->toEndWith('ORDER BY "tag_id" DESC, "post_id" DESC LIMIT ?')
        ->and($meta['cursor_columns'])->toBe(['tag_id', 'post_id']);
});

it('pages by offset in ctid order when there is no key', function () {
    [$sql, $bindings, $meta] = postsQuery(null)->build(['offset' => '200', 'cursor' => 'ignored'], 1000);

    expect($sql)->toBe(ROWS_POSTS_SELECT.' ORDER BY ctid LIMIT ? OFFSET ?')
        ->and($bindings)->toBe([101, 200])
        ->and($meta['pagination'])->toBe('offset')
        ->and($meta['offset'])->toBe(200)
        ->and($meta['cursor_columns'])->toBe([]);
});

it('sorts offset pages by the sort column, then ctid', function () {
    [$sql] = postsQuery([])->build(['sort' => 'published_at', 'direction' => 'desc'], 1000);

    expect($sql)->toBe(ROWS_POSTS_SELECT.' ORDER BY "published_at" DESC NULLS LAST, ctid LIMIT ? OFFSET ?');
});

it('leaves a relation without ctid, like a view, unordered', function () {
    [$sql, $bindings] = postsQuery(null, false)->build([], 1000);

    expect($sql)->toBe(ROWS_POSTS_SELECT.' LIMIT ? OFFSET ?')->and($bindings)->toBe([101, 0]);
});

it('rejects offsets beyond the cap or that are not whole numbers', function (mixed $offset) {
    expect(rowsErrors(fn () => postsQuery(null)->build(['offset' => $offset], 1000)))->toHaveKey('offset');
})->with(['over the cap' => '100001', 'negative' => '-1', 'text' => '1 OR 1=1', 'array' => [['1']]]);

it('S12 binds every filter value and maps each allowed operator', function (string $op, mixed $value, string $condition, array $bindings) {
    [$sql, $actual] = postsQuery()->build(['filters' => [['column' => 'price', 'op' => $op, 'value' => $value]]], 1000);

    expect($sql)->toBe(ROWS_POSTS_SELECT." WHERE ({$condition}) ORDER BY \"id\" LIMIT ?")
        ->and($actual)->toBe([...$bindings, 101]);
})->with([
    '=' => ['=', '10', '"price" = ?', ['10']],
    '!=' => ['!=', '10', '"price" <> ?', ['10']],
    '<' => ['<', '10', '"price" < ?', ['10']],
    '<=' => ['<=', '10', '"price" <= ?', ['10']],
    '>' => ['>', '10', '"price" > ?', ['10']],
    '>=' => ['>=', '10', '"price" >= ?', ['10']],
    'like on text form' => ['like', '1%', '"price"::text LIKE ?', ['1%']],
    'ilike on text form' => ['ilike', '%5', '"price"::text ILIKE ?', ['%5']],
    'is_null ignores the value' => ['is_null', 'x', '"price" IS NULL', []],
    'not_null' => ['not_null', null, '"price" IS NOT NULL', []],
    'in' => ['in', ['1', 2, '3'], '"price" IN (?, ?, ?)', ['1', '2', '3']],
    'boolean value' => ['=', true, '"price" = ?', ['true']],
    'float value' => ['>', 1.5, '"price" > ?', ['1.5']],
]);

it('S12 ANDs filters and binds them before the cursor and the limit', function () {
    $cursor = RowsQuery::encodeCursor([7], null, 'asc');

    [$sql, $bindings] = postsQuery()->build([
        'filters' => [
            ['column' => 'title', 'op' => 'ilike', 'value' => '%post%'],
            ['column' => 'published_at', 'op' => 'not_null'],
        ],
        'cursor' => $cursor,
    ], 1000);

    expect($sql)->toBe(ROWS_POSTS_SELECT.' WHERE ("title"::text ILIKE ?) AND ("published_at" IS NOT NULL) AND (("id") > (?)) ORDER BY "id" LIMIT ?')
        ->and($bindings)->toBe(['%post%', '7', 101]);
});

it('S12 keeps an injection payload in a value as a bound value only', function () {
    $payload = "1'); DROP TABLE fixture.posts; --";

    [$sql, $bindings] = postsQuery()->build(['filters' => [['column' => 'title', 'op' => '=', 'value' => $payload]]], 1000);

    expect($sql)->not->toContain('DROP')->and($bindings)->toBe([$payload, 101]);
});

it('S12 rejects a sort column that is not an introspected column', function (mixed $sort) {
    expect(rowsErrors(fn () => postsQuery()->build(['sort' => $sort], 1000)))->toHaveKey('sort');
})->with([
    'statement' => 'id; DROP TABLE fixture.posts',
    'direction inside' => 'id desc',
    'second expression' => 'id desc, (select 1)',
    'subquery' => '(SELECT password FROM fixture.users LIMIT 1)',
    'quoted' => '"id"',
    'qualified' => 'posts.id',
    'case' => 'ID',
    'whitespace' => ' id',
    'comment' => 'id--',
    'function' => 'pg_sleep(5)',
    'ordinal' => '1',
    'array' => [['id']],
    'unknown' => 'nope',
]);

it('S12 rejects a filter column that is not an introspected column', function (mixed $column) {
    $filters = [['column' => $column, 'op' => '=', 'value' => '1']];

    expect(rowsErrors(fn () => postsQuery()->build(['filters' => $filters], 1000)))->toHaveKey('filters.0.column');
})->with([
    'tautology' => 'id = id OR 1',
    'statement' => 'id; DROP TABLE fixture.posts; --',
    'cast' => 'id::text',
    'quoted' => '"title"',
    'missing' => null,
    'array' => [['title']],
    'ctid' => 'ctid',
]);

it('S12 rejects an operator outside the allowlist', function (mixed $op) {
    $filters = [['column' => 'title', 'op' => $op, 'value' => '1']];

    expect(rowsErrors(fn () => postsQuery()->build(['filters' => $filters], 1000)))->toHaveKey('filters.0.op');
})->with([
    'tautology' => '= 1 OR 1=1 --',
    'statement' => '; DROP TABLE fixture.posts',
    'sql spelling' => 'LIKE',
    'double equals' => '==',
    'regex' => '~',
    'similar to' => 'similar to',
    'between' => 'between',
    'upper-case in' => 'IN',
    'missing' => null,
    'array' => [['=']],
]);

it('rejects a filter value that is not text, a number or a boolean', function (string $op, mixed $value) {
    $filters = [['column' => 'title', 'op' => $op, 'value' => $value]];

    expect(rowsErrors(fn () => postsQuery()->build(['filters' => $filters], 1000)))->toHaveKey('filters.0.value');
})->with([
    'array for =' => ['=', ['a', 'b']],
    'missing value' => ['=', null],
    'NUL byte' => ['=', "a\0b"],
    'invalid UTF-8' => ['like', "\xC3\x28"],
    'scalar for in' => ['in', 'a,b'],
    'empty in' => ['in', []],
    'nested in' => ['in', [['a']]],
    'null in in' => ['in', ['a', null]],
    'too many in' => ['in', array_fill(0, RowsQuery::MAX_IN_VALUES + 1, '1')],
]);

it('accepts the most values the in operator allows', function () {
    [$sql, $bindings] = postsQuery()->build(['filters' => [['column' => 'id', 'op' => 'in', 'value' => range(1, RowsQuery::MAX_IN_VALUES)]]], 1000);

    expect(substr_count($sql, '?'))->toBe(RowsQuery::MAX_IN_VALUES + 1)->and($bindings)->toHaveCount(RowsQuery::MAX_IN_VALUES + 1);
});

it('rejects malformed filter lists', function (mixed $filters, string $field) {
    expect(rowsErrors(fn () => postsQuery()->build(['filters' => $filters], 1000)))->toHaveKey($field);
})->with([
    'a string' => ['title = 1', 'filters'],
    'a filter that is a string' => [['title'], 'filters.0'],
    'too many' => [array_fill(0, RowsQuery::MAX_FILTERS + 1, ['column' => 'id', 'op' => 'not_null']), 'filters'],
]);

it('S14 rejects sorting or filtering by a masked column', function (array $params, string $field) {
    expect(rowsErrors(fn () => postsQuery()->build($params, 1000)))->toHaveKey($field);
})->with([
    'sort' => [['sort' => 'secret'], 'sort'],
    'filter' => [['filters' => [['column' => 'secret', 'op' => 'like', 'value' => 'a%']]], 'filters.0.column'],
    'null check' => [['filters' => [['column' => 'secret', 'op' => 'is_null']]], 'filters.0.column'],
]);

it('rejects a direction other than asc or desc', function (mixed $direction) {
    expect(rowsErrors(fn () => postsQuery()->build(['sort' => 'id', 'direction' => $direction], 1000)))->toHaveKey('direction');
})->with(['DESC', 'up', 'desc; DROP TABLE x', [['desc']]]);

it('S12 rejects a tampered or foreign cursor', function (mixed $cursor, array $params = []) {
    expect(rowsErrors(fn () => postsQuery()->build($params + ['cursor' => $cursor], 1000)))->toHaveKey('cursor');
})->with([
    'not base64url' => ['abc+/=='],
    'garbage' => ['!!!'],
    'not JSON' => [rtrim(strtr(base64_encode('nope'), '+/', '-_'), '=')],
    'JSON list' => [rtrim(strtr(base64_encode('[1]'), '+/', '-_'), '=')],
    'no values' => [rtrim(strtr(base64_encode('{"s":null,"d":"asc"}'), '+/', '-_'), '=')],
    'too many values' => [RowsQuery::encodeCursor([1, 2], null, 'asc')],
    'too few values' => [RowsQuery::encodeCursor([], null, 'asc')],
    'NULL key value' => [RowsQuery::encodeCursor([null], null, 'asc')],
    'nested value' => [rtrim(strtr(base64_encode('{"v":[[1]],"s":null,"d":"asc"}'), '+/', '-_'), '=')],
    'object values' => [rtrim(strtr(base64_encode('{"v":{"id":1},"s":null,"d":"asc"}'), '+/', '-_'), '=')],
    'NUL byte value' => [RowsQuery::encodeCursor(["1\0"], null, 'asc')],
    'made for another sort' => [RowsQuery::encodeCursor(['Post 1', 1], 'title', 'asc')],
    'made for another direction' => [RowsQuery::encodeCursor([1], null, 'desc')],
    'NULL in a NOT NULL sort column' => [RowsQuery::encodeCursor([null, 1], 'title', 'asc'), ['sort' => 'title']],
    'NULL in the key of a nullable sort' => [RowsQuery::encodeCursor(['x', null], 'published_at', 'asc'), ['sort' => 'published_at']],
    'too long' => [str_repeat('A', RowsQuery::MAX_CURSOR_LENGTH + 1)],
    'array' => [['x']],
    'injection' => [RowsQuery::encodeCursor(['1) OR (1=1'], null, 'asc')."'; DROP TABLE x"],
]);

it('S12 binds a cursor value carrying SQL instead of running it', function () {
    $cursor = RowsQuery::encodeCursor(['1) OR (1=1'], null, 'asc');

    [$sql, $bindings] = postsQuery()->build(['cursor' => $cursor], 1000);

    expect($sql)->not->toContain('1=1')->and($bindings)->toBe(['1) OR (1=1', 101]);
});

it('encodes cursors as unpadded base64url JSON', function () {
    $cursor = RowsQuery::encodeCursor(['ünï/+?', 1], 'title', 'desc');

    expect($cursor)->toMatch('/\A[A-Za-z0-9_-]+\z/')
        ->and(json_decode((string) base64_decode(strtr($cursor, '-_', '+/')), true))
        ->toBe(['v' => ['ünï/+?', 1], 's' => 'title', 'd' => 'desc']);
});

it('builds an exact count over the filtered rows on request', function () {
    [, , $meta] = postsQuery()->build(['exact' => '1', 'filters' => [['column' => 'title', 'op' => '=', 'value' => 'x']]], 1000);

    expect($meta['count'])->toBe([
        'sql' => 'SELECT count(*) AS count FROM "fixture"."posts" WHERE ("title" = ?)',
        'bindings' => ['x'],
    ]);
});

it('builds no count unless exact is truthy, and rejects other values', function () {
    expect(postsQuery()->build(['exact' => '0'], 1000)[2]['count'])->toBeNull()
        ->and(postsQuery()->build(['exact' => 'true'], 1000)[2]['count'])->not->toBeNull()
        ->and(rowsErrors(fn () => postsQuery()->build(['exact' => 'maybe'], 1000)))->toHaveKey('exact');
});
