<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use TacticalNinj4\DbExplorer\Security\Masker;

/**
 * Run $sql as the viewer through PDO, like the QueryRunner, and mask the result.
 *
 * @return array{flags: array<int, bool>, rows: list<array<int, mixed>>, meta: list<array<string, mixed>>}
 */
function maskedQuery(Masker $masker, string $sql): array
{
    $connection = DB::connection('pgsql_viewer');
    $statement = $connection->getPdo()->query($sql);
    $meta = [];

    for ($i = 0; $i < $statement->columnCount(); $i++) {
        $meta[] = $statement->getColumnMeta($i);
    }

    $flags = $masker->forColumns($connection, $meta);

    return [
        'flags' => $flags,
        'rows' => iterator_to_array($masker->maskRows($statement->fetchAll(PDO::FETCH_NUM), $flags), false),
        'meta' => $meta,
    ];
}

it('S14 masks password and api_token in SELECT * with the default rules', function () {
    $result = maskedQuery(app(Masker::class), 'SELECT id, email, password, api_token FROM fixture.users ORDER BY id');
    $all = maskedQuery(app(Masker::class), 'SELECT * FROM fixture.users ORDER BY id');
    $names = array_column($all['meta'], 'name');

    expect($result['flags'])->toBe([false, false, true, true])
        ->and($result['rows'][0])->toBe([1, 'Alice@Example.com', Masker::MASK, Masker::MASK])
        // Carol has no api_token: NULL stays NULL.
        ->and($result['rows'][2])->toBe([3, 'carol@example.com', Masker::MASK, null])
        ->and(array_keys(array_filter($all['flags'])))->toBe([array_search('password', $names, true), array_search('api_token', $names, true)])
        ->and($all['rows'][0][array_search('name', $names, true)])->toBe('Alice');
});

it('S14 masks a column selected through a table alias', function () {
    $result = maskedQuery(app(Masker::class), 'SELECT u.name, u.password FROM fixture.users u ORDER BY u.id');

    expect($result['flags'])->toBe([false, true])
        ->and(array_column($result['rows'], 1))->toBe([Masker::MASK, Masker::MASK, Masker::MASK]);
});

it('S14 masks only the joined column whose table matches the rule', function () {
    config(['dbexplorer.masking' => ['users.name']]);

    $result = maskedQuery(app(Masker::class), 'SELECT u.name, t.name FROM fixture.users u JOIN fixture.tags t ON t.id = u.id WHERE u.id = 1');

    expect($result['flags'])->toBe([true, false])
        ->and($result['rows'])->toBe([[Masker::MASK, 'postgres']]);
});

it('S14 tells same-named tables in different schemas apart', function () {
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$schema}.users (id int, password text)");
    DB::connection('pgsql_admin')->insert("INSERT INTO {$schema}.users VALUES (1, 'not-a-secret')");
    config(['dbexplorer.masking' => ['fixture.users.password']]);

    $result = maskedQuery(app(Masker::class), "SELECT f.password, s.password FROM fixture.users f JOIN {$schema}.users s ON s.id = f.id");

    expect($result['flags'])->toBe([true, false])
        ->and($result['rows'])->toBe([[Masker::MASK, 'not-a-secret']]);
});

it('S14 masks an expression column by its name', function () {
    $result = maskedQuery(app(Masker::class), "SELECT 'x' AS password, upper(api_token) AS api_token, 'y' AS label FROM fixture.users WHERE id = 1");

    expect(array_column($result['meta'], 'pgsql:table_oid'))->toBe([0, 0, 0])
        ->and($result['flags'])->toBe([true, true, false])
        ->and($result['rows'])->toBe([[Masker::MASK, Masker::MASK, 'y']]);
});

it('S14 GAP: does not mask an aliased column, because PostgreSQL reports its table but not its source column (docs/security.md)', function () {
    $result = maskedQuery(app(Masker::class), 'SELECT password AS x FROM fixture.users ORDER BY id');

    expect($result['meta'][0]['table'] ?? null)->toBe('users')
        ->and($result['meta'][0]['name'])->toBe('x')
        ->and($result['flags'])->toBe([false])
        ->and($result['rows'][0][0])->toStartWith('$2y$12$alice');
});

it('S14 resolves the tables of a three-table join with one catalog query', function () {
    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries++;
    });

    $masker = app(Masker::class);
    $sql = 'SELECT u.password, p.title, t.name FROM fixture.users u'
        .' JOIN fixture.posts p ON p.user_id = u.id'
        .' JOIN fixture.post_tags pt ON pt.post_id = p.id'
        .' JOIN fixture.tags t ON t.id = pt.tag_id ORDER BY p.id, t.id LIMIT 3';

    $first = maskedQuery($masker, $sql);
    $afterFirst = $queries;
    $second = maskedQuery($masker, $sql);

    expect(count(array_unique(array_column($first['meta'], 'pgsql:table_oid'))))->toBe(3)
        ->and($first['flags'])->toBe([true, false, false])
        ->and($afterFirst)->toBe(1)
        // Cached per instance: the same tables cost nothing the second time.
        ->and($queries)->toBe(1)
        ->and($second['flags'])->toBe([true, false, false]);
});
