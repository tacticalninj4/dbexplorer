<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Tests\TestCase;

// M4.5: the audit log viewer API, admins only.

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly', 'editor' => 'pgsql_editor', 'admin' => 'pgsql'],
    ]]);

    $this->artisan('migrate');
});

/**
 * GET /dbexplorer/api/main/audit as $role.
 */
function auditAs(TestCase $test, string $query = '', string $role = 'admin'): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->getJson('/dbexplorer/api/main/audit'.($query === '' ? '' : "?{$query}"));
}

function entry(array $attributes = []): AuditEntry
{
    DbExplorer::audit($attributes + ['action' => 'query', 'user_id' => '1', 'label' => 'main', 'classification' => 'read', 'outcome' => 'success']);

    return AuditEntry::query()->orderByDesc('id')->firstOrFail();
}

it('is for admins only', function (string $role) {
    auditAs($this, '', $role)->assertForbidden()->assertJsonPath('message', 'Only admins can read the audit log.');
})->with(['viewer', 'editor']);

it('lists entries newest first, with pagination metadata', function () {
    foreach (range(1, 51) as $i) {
        entry(['user_id' => (string) $i]);
    }

    $first = auditAs($this)->assertOk();

    expect(count($first->json('data')))->toBe(50)
        ->and($first->json('data.0.user_id'))->toBe('51')
        ->and($first->json('meta'))->toBe(['page' => 1, 'per_page' => 50, 'has_more' => true])
        ->and(auditAs($this, 'page=2')->json('data.0.user_id'))->toBe('1');
});

it('filters by user, action, classification, outcome and date range', function () {
    entry(['action' => 'query', 'user_id' => '1', 'classification' => 'read', 'outcome' => 'success']);
    entry(['action' => 'run.cancelled', 'user_id' => '2', 'outcome' => 'cancelled']);
    entry(['action' => 'query', 'user_id' => '3', 'classification' => 'ddl', 'outcome' => 'success']);
    Date::setTestNow(now()->subDays(2));
    entry(['action' => 'query', 'user_id' => '4']);
    Date::setTestNow();

    $ids = fn (TestResponse $response): array => array_column($response->assertOk()->json('data'), 'user_id');

    expect($ids(auditAs($this, 'user_id=1')))->toBe(['1'])
        ->and($ids(auditAs($this, 'action=run.*')))->toBe(['2'])
        ->and($ids(auditAs($this, 'classification=ddl')))->toBe(['3'])
        ->and($ids(auditAs($this, 'outcome=cancelled')))->toBe(['2'])
        ->and($ids(auditAs($this, 'from='.now()->subDay()->toDateString())))->toBe(['3', '2', '1']);
});

it('shows the entry as stored, hash chain fields excluded', function () {
    $created = entry(['action' => 'query', 'sql' => 'SELECT 1', 'meta' => ['bindings' => 2]]);

    $data = auditAs($this)->assertOk()->json('data.0');

    expect($data['id'])->toBe($created->id)
        ->and(array_keys($data))->toBe(['id', 'action', 'user_id', 'label', 'role', 'classification', 'outcome', 'error', 'duration_ms', 'row_count', 'sql', 'meta', 'created_at'])
        ->and($data['sql'])->toBe('SELECT 1')
        ->and($data)->not->toHaveKey('hash')
        ->and($data)->not->toHaveKey('prev_hash');
});

it('S17 shows only rows from labels the user is admin on, whatever the filters', function () {
    config(['dbexplorer.connections.prod' => ['viewer' => 'pgsql_readonly', 'admin' => 'pgsql']]);

    entry(['label' => 'main', 'user_id' => '1']);
    entry(['label' => 'prod', 'user_id' => '1', 'sql' => 'SELECT secret FROM prod_only']);
    entry(['label' => null, 'action' => 'password_confirm', 'user_id' => '1']);

    // admin on main, no role on prod
    DbExplorer::roleUsing(fn ($user, string $label): ?string => $label === 'main' ? 'admin' : null);

    $labels = fn (string $query): array => array_column(
        $this->actingAs(makeUser())->getJson('/dbexplorer/api/main/audit'.($query === '' ? '' : "?{$query}"))->assertOk()->json('data'),
        'label',
    );

    expect($labels(''))->toBe(['main'])
        ->and($labels('label=prod'))->toBe([])
        ->and($labels('user_id=1'))->toBe(['main'])
        ->and($labels('classification=read&action=*'))->toBe(['main'])
        ->and($labels('action=password_confirm'))->toBe([]);

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/prod/audit')->assertForbidden();

    // a viewer on prod is no admin there either
    DbExplorer::roleUsing(fn ($user, string $label): string => $label === 'main' ? 'admin' : 'viewer');

    expect($labels(''))->toBe(['main'])
        ->and($labels('label=prod'))->toBe([]);

    // admin on every label: every row, the label-less ones too
    DbExplorer::roleUsing(fn (): string => 'admin');

    expect($labels(''))->toBe([null, 'prod', 'main'])
        ->and($labels('label=prod'))->toBe(['prod']);
});
