<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;

// The grid sends a row back exactly as the rows API returned it: as the
// original of a delete or update, every value must still match the database.

beforeEach(function () {
    app()['env'] = 'local';
    $this->schema = $this->scratchSchema();
});

it('deletes a row using the values the rows API returned', function (string $type, string $literal) {
    DB::connection('pgsql_admin')->unprepared("CREATE TABLE {$this->schema}.t (id int PRIMARY KEY, v {$type}); INSERT INTO {$this->schema}.t VALUES (1, {$literal});");
    DbExplorer::roleUsing(fn (): string => 'editor');
    $base = $this->actingAs(makeUser())->withSession(['_token' => 'x', 'auth.password_confirmed_at' => time()])->withHeaders(['X-CSRF-TOKEN' => 'x']);

    $page = $base->getJson('/dbexplorer/api/main/rows/'.$this->schema.'/t')->assertOk()->json('data');
    $row = array_combine(array_column($page['columns'], 'name'), $page['rows'][0]);

    $response = $base->json('DELETE', '/dbexplorer/api/main/rows/'.$this->schema.'/t', ['key' => ['id' => $row['id']], 'original' => ['v' => $row['v']]]);
    expect($response->status())->toBe(200, $type.' sent '.json_encode($row['v']).' → '.$response->getContent());
})->with([
    'timestamp' => ['timestamp', "'2026-01-02 03:04:05.123456'"],
    'timestamptz' => ['timestamptz', "'2026-01-02 03:04:05.123456+05'"],
    'date' => ['date', "'2026-01-02'"],
    'time' => ['time', "'03:04:05.5'"],
    'timetz' => ['timetz', "'03:04:05+02'"],
    'interval' => ['interval', "'1 day 02:03:04'"],
    'bigint big' => ['bigint', '9007199254740993'],
    'real' => ['real', '0.1'],
    'float8' => ['double precision', '0.30000000000000004'],
    'float8 int' => ['double precision', '3'],
    'numeric' => ['numeric', '1.10'],
    'numeric big' => ['numeric', '123456789012345678901234567890.5'],
    'money' => ['money', '12.34'],
    'boolean' => ['boolean', 'true'],
    'inet' => ['inet', "'10.0.0.1/24'"],
    'macaddr' => ['macaddr', "'08:00:2b:01:02:03'"],
    'bit' => ['bit(4)', "B'1010'"],
    'xml' => ['xml', "'<a>1</a>'"],
    'point' => ['point', "'(1,2)'"],
    'int4range' => ['int4range', "'[1,5)'"],
    'tsvector' => ['tsvector', "'a b'"],
    'jsonb' => ['jsonb', "'{\"a\": 1.0}'"],
    'json' => ['json', "'{\"a\": 1.0}'"],
    'text newline' => ['text', "E'a\\nb'"],
    'text trailing space' => ['text', "'ends with a space '"],
    'text leading space' => ['text', "'  starts with spaces'"],
    'empty text' => ['text', "''"],
    'text long' => ['text', "repeat('x', 100000)"],
    'int[]' => ['int[]', "'{1,2}'"],
    'smallint' => ['smallint', '7'],
    'oid' => ['oid', '5'],
    'uuid' => ['uuid', "'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'"],
    'bytea' => ['bytea', "'\\xdead'"],
]);

it('writes values byte for byte, despite the host\'s TrimStrings and ConvertEmptyStringsToNull', function () {
    DB::connection('pgsql_admin')->unprepared("CREATE TABLE {$this->schema}.t (id int PRIMARY KEY, a text, b text); INSERT INTO {$this->schema}.t VALUES (1, 'x', 'y');");
    DbExplorer::roleUsing(fn (): string => 'editor');

    $this->actingAs(makeUser())->withSession(['_token' => 'x'])->withHeaders(['X-CSRF-TOKEN' => 'x'])
        ->patchJson('/dbexplorer/api/main/rows/'.$this->schema.'/t', ['key' => ['id' => 1], 'original' => ['a' => 'x', 'b' => 'y'], 'changes' => ['a' => ' padded ', 'b' => '']])
        ->assertOk();

    expect((array) DB::connection('pgsql_super')->selectOne("SELECT a, b FROM {$this->schema}.t"))->toBe(['a' => ' padded ', 'b' => '']);
});
