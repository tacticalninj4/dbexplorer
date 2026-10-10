<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;

// S15: cell values are data, never markup. The server hands them out only as
// JSON strings, byte for byte (no HTML context, no escaping or stripping to
// rely on), and the shell page never embeds row data; the SPA renders them
// through text interpolation, with `v-html` banned (tests/Feature/ShellTest).

const XSS_PAYLOADS = [
    '<img src=x onerror=alert(1)>',
    '"><script>alert(document.cookie)</script>',
];

beforeEach(function () {
    app()['env'] = 'local';
    DbExplorer::roleUsing(fn (): string => 'viewer');

    $this->schema = $this->scratchSchema();

    DB::connection('pgsql_admin')->statement("CREATE TABLE {$this->schema}.xss (id int PRIMARY KEY, body text, doc jsonb, tags text[])");

    foreach (XSS_PAYLOADS as $i => $payload) {
        DB::connection('pgsql_admin')->insert(
            "INSERT INTO {$this->schema}.xss VALUES (?, ?, ?::jsonb, ARRAY[?])",
            [$i + 1, $payload, json_encode(['html' => $payload]), $payload],
        );
    }
});

/**
 * Every string anywhere in a decoded JSON document.
 *
 * @return list<string>
 */
function xssStrings(mixed $value): array
{
    if (is_string($value)) {
        return [$value];
    }

    if (! is_array($value)) {
        return [];
    }

    return array_merge([], ...array_map('xssStrings', array_values($value)));
}

function expectPlainJsonStrings(TestResponse $response): void
{
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $strings = xssStrings($response->json());

    foreach (XSS_PAYLOADS as $payload) {
        // The text column comes back exactly as stored: not HTML-escaped,
        // not stripped, so nothing downstream can treat it as markup by mistake.
        expect($strings)->toContain($payload);
    }
}

it('S15 returns cell values from the rows API as plain JSON strings', function () {
    $response = $this->actingAs(makeUser())->getJson("/dbexplorer/api/main/rows/{$this->schema}/xss");

    expectPlainJsonStrings($response);
});

it('S15 returns cell values from the query API as plain JSON strings', function () {
    $response = $this->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/query', ['sql' => "SELECT id, body, doc, tags FROM {$this->schema}.xss ORDER BY id"]);

    expectPlainJsonStrings($response);
});

it('S15 never embeds row data in the shell page', function () {
    $html = (string) $this->actingAs(makeUser())
        ->get("/dbexplorer/main/{$this->schema}/xss")
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('onerror')
        ->not->toContain('alert(')
        ->and(substr_count($html, '<script'))->toBe(2);
});
