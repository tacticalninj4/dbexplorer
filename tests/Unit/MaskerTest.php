<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use TacticalNinj4\DbExplorer\Security\Masker;

/**
 * @param  array<int, mixed>  $rules
 */
function maskerWith(array $rules): Masker
{
    return new Masker(new Repository(['dbexplorer' => ['masking' => $rules]]));
}

/**
 * Column meta shaped like pdo_pgsql's getColumnMeta().
 *
 * @return array<string, mixed>
 */
function columnMeta(string $name, int $tableOid = 0): array
{
    return ['name' => $name, 'pgsql:table_oid' => $tableOid, 'native_type' => 'text'];
}

/**
 * A connection that fails the test if the Masker queries it.
 */
function noQueries(): ConnectionInterface
{
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldNotReceive('select');

    return $connection;
}

final class MaskerTestRules
{
    public static function billing(string $schema, string $table, string $column): bool
    {
        return $schema === 'billing';
    }
}

it('S14 matches a pattern with N dots against the last N+1 parts of schema.table.column', function (string $rule, string $schema, string $table, string $column, bool $expected) {
    expect(maskerWith([$rule])->masksColumn($schema, $table, $column))->toBe($expected);
})->with([
    ['*.password', 'public', 'users', 'password', true],
    ['*.password', 'fixture', 'accounts', 'password', true],
    ['*.password', 'public', 'users', 'password_hash', false],
    ['*.password', 'public', 'password', 'id', false],
    ['*.password', 'public', 'users', 'Password', true],
    ['*.*token*', 'public', 'users', 'api_token', true],
    ['*.*token*', 'public', 'users', 'token', true],
    ['*.*token*', 'public', 'users', 'remember_token_at', true],
    ['*.*token*', 'public', 'tokens', 'id', false],
    ['*.*secret*', 'public', 'secrets', 'label', false],
    ['*.*secret*', 'public', 'clients', 'client_secret', true],
    ['users.ssn', 'public', 'users', 'ssn', true],
    ['users.ssn', 'billing', 'users', 'ssn', true],
    ['users.ssn', 'public', 'customers', 'ssn', false],
    ['users.ssn', 'public', 'users_archive', 'ssn', false],
    ['users.ssn', 'public', 'users', 'ssn_last4', false],
    ['public.users.ssn', 'public', 'users', 'ssn', true],
    ['public.users.ssn', 'billing', 'users', 'ssn', false],
    ['ssn', 'public', 'users', 'ssn', true],
    ['ssn', 'public', 'ssn', 'id', false],
    ['USERS.SSN', 'public', 'users', 'ssn', true],
    ['users.ssn', 'Public', 'Users', 'SSN', true],
    ['users.*', 'public', 'users', 'email', true],
    ['users.*', 'public', 'posts', 'email', false],
    ['*card*.number', 'public', 'credit_cards', 'number', true],
    ['billing.*.*', 'billing', 'cards', 'number', true],
    ['billing.*.*', 'public', 'cards', 'number', false],
    ['billing.*', 'billing', 'cards', 'number', false],
    ['*.*.*', 'a', 'b', 'c', true],
    ['*', 'public', 'users', 'anything', true],
    ['pass*', 'public', 'users', 'passcode', true],
    ['pass?ord', 'public', 'users', 'password', false],
    ['db.public.users.ssn', 'public', 'users', 'ssn', false],
    ['', 'public', 'users', 'ssn', false],
]);

it('S14 masks a column when any rule matches and none by default for unrelated columns', function () {
    $masker = maskerWith(['*.password', '*.*token*', '*.*secret*']);

    expect($masker->masksColumn('fixture', 'users', 'password'))->toBeTrue()
        ->and($masker->masksColumn('fixture', 'users', 'api_token'))->toBeTrue()
        ->and($masker->masksColumn('fixture', 'users', 'email'))->toBeFalse()
        ->and(maskerWith([])->masksColumn('fixture', 'users', 'password'))->toBeFalse();
});

it('S14 applies closure and static method rules with the original case', function () {
    $seen = [];
    $masker = maskerWith([
        function (string $schema, string $table, string $column) use (&$seen): bool {
            $seen[] = "{$schema}.{$table}.{$column}";

            return $table === 'Customers' && $column === 'dob';
        },
        [MaskerTestRules::class, 'billing'],
    ]);

    expect($masker->masksColumn('public', 'Customers', 'dob'))->toBeTrue()
        ->and($masker->masksColumn('public', 'customers', 'dob'))->toBeFalse()
        ->and($masker->masksColumn('billing', 'cards', 'number'))->toBeTrue()
        ->and($seen)->toBe(['public.Customers.dob', 'public.customers.dob', 'billing.cards.number']);
});

it('rejects a rule that is neither a pattern nor a callable', function () {
    maskerWith([42]);
})->throws(InvalidArgumentException::class);

it('S14 matches expression columns by name against the column part of each rule', function () {
    $columns = [];
    $masker = maskerWith(['*.password', 'users.ssn', 'public.cards.number', function (string $schema, string $table, string $column) use (&$columns): bool {
        $columns[] = [$schema, $table, $column];

        return $column === 'dob';
    }]);

    $flags = $masker->forColumns(noQueries(), [
        columnMeta('password'),
        columnMeta('ssn'),
        columnMeta('number'),
        columnMeta('dob'),
        columnMeta('?column?'),
    ]);

    expect($flags)->toBe([true, true, true, true, false])
        ->and($columns)->toBe([['', '', 'dob'], ['', '', '?column?']]);
});

it('S14 resolves source tables with one batched catalog query and caches them per instance', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('select')
        ->once()
        ->with(Mockery::pattern('/pg_catalog\.pg_class/'), ['{10,11,12}'])
        ->andReturn([
            (object) ['oid' => 10, 'nspname' => 'fixture', 'relname' => 'users'],
            (object) ['oid' => 11, 'nspname' => 'fixture', 'relname' => 'posts'],
        ]);

    $masker = maskerWith(['users.password', 'users.id']);
    $meta = [
        columnMeta('id', 10),
        columnMeta('id', 11),
        columnMeta('password', 10),
        columnMeta('password', 11),
        // Unknown oid: falls back to the column name.
        columnMeta('password', 12),
        columnMeta('password'),
    ];

    expect($masker->forColumns($connection, $meta))->toBe([true, false, true, false, true, true])
        // Every oid is cached now, so this doesn't query again.
        ->and($masker->forColumns($connection, [columnMeta('id', 11), columnMeta('id', 10), columnMeta('x', 12)]))->toBe([false, true, false]);
});

it('S14 unmasks every column without querying when asked or when there are no rules', function () {
    $meta = [columnMeta('password', 10), columnMeta('api_token')];

    expect(maskerWith(['*.password', '*.*token*'])->forColumns(noQueries(), $meta, true))->toBe([false, false])
        ->and(maskerWith([])->forColumns(noQueries(), $meta))->toBe([false, false]);
});

it('S14 replaces masked values with the mask and keeps NULL', function () {
    $masker = maskerWith(['*.password']);

    expect($masker->maskRow(['id' => 1, 'password' => 'hash', 'token' => null], [false, true, true]))
        ->toBe(['id' => 1, 'password' => Masker::MASK, 'token' => null])
        ->and($masker->maskRow([1, null, '', 0], [false, true, true, true]))->toBe([1, null, Masker::MASK, Masker::MASK])
        ->and(Masker::MASK)->toBe('••••••');
});

it('S14 masks duplicate column names by position in numeric rows', function () {
    // SELECT u.id, s.id, u.password, s.password: flags differ for the same name.
    $flags = [false, true, true, false];

    expect(maskerWith([])->maskRow([1, 'secret-id', 'hash', 'plain'], $flags))
        ->toBe([1, Masker::MASK, Masker::MASK, 'plain']);
});

it('S14 refuses a row whose values do not line up with the columns', function () {
    // FETCH_ASSOC collapses SELECT p.id, u.password AS id into one value.
    maskerWith([])->maskRow(['id' => 'hash'], [false, true]);
})->throws(InvalidArgumentException::class, 'PDO::FETCH_NUM');

it('S14 masks rows lazily without materialising the source', function () {
    $pulled = 0;
    $source = (function () use (&$pulled): Generator {
        foreach ([10 => ['a', 'x'], 20 => ['b', null], 30 => ['c', 'z']] as $key => $row) {
            $pulled++;

            yield $key => $row;
        }
    })();

    $masked = maskerWith([])->maskRows($source, [false, true]);

    expect($pulled)->toBe(0);

    $first = [];

    foreach ($masked as $key => $row) {
        $first = [$key => $row];

        break;
    }

    expect($pulled)->toBe(1)
        ->and($first)->toBe([10 => ['a', Masker::MASK]])
        ->and(iterator_to_array(maskerWith([])->maskRows([10 => ['a', 'x'], 20 => ['b', null]], [false, true])))
        ->toBe([10 => ['a', Masker::MASK], 20 => ['b', null]]);
});
