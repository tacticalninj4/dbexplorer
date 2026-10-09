<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Export\ExportWriter;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\Export;
use TacticalNinj4\DbExplorer\Jobs\RunQuery;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Tests\TestCase;

// M3.2: POST api/{label}/export and the query API's background flag. The
// jobs themselves run against Postgres only (tests/Pgsql/ExportJobTest.php);
// nothing here touches a database.

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly', 'editor' => 'pgsql_editor', 'admin' => 'pgsql'],
    ]]);

    $this->artisan('migrate');
});

/**
 * POST $body to /dbexplorer/api/main/export as user $user with $role, with
 * the session's CSRF token, as the SPA sends it.
 *
 * @param  array<string, mixed>  $body
 */
function exportAs(TestCase $test, array $body, string $role = 'viewer', int $user = 1): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser($user))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/export', $body);
}

it('queues a query export with its bindings and format', function () {
    Queue::fake();

    exportAs($this, ['sql' => 'SELECT id FROM fixture.users WHERE id > ? ORDER BY id', 'bindings' => [5], 'format' => 'jsonl'], 'editor', 7)
        ->assertStatus(202);

    $model = Run::query()->sole();

    expect($model->meta['format'])->toBe(ExportWriter::JSONL)
        ->and($model->meta['bindings'])->toBe([5])
        ->and($model->meta)->not->toHaveKey('table');

    Queue::assertPushed(Export::class);
});

it('rejects an export of anything but a read query', function () {
    Queue::fake();

    exportAs($this, ['sql' => "INSERT INTO fixture.sentinel VALUES ('sentinel-value')"])->assertStatus(422)
        ->assertJsonValidationErrors('sql');

    expect(Run::query()->count())->toBe(0);

    exportAs($this, ['sql' => 'DELETE FROM fixture.users;'])->assertStatus(422)->assertJsonValidationErrors('sql');
});

it('rejects the sql format without a table, and unknown formats', function () {
    exportAs($this, ['sql' => 'SELECT 1', 'format' => 'sql'])->assertStatus(422)->assertJsonValidationErrors('format');
    exportAs($this, ['table' => ['schema' => 'fixture', 'name' => 'users'], 'format' => 'xlsx'])->assertStatus(422)->assertJsonValidationErrors('format');
    exportAs($this, [])->assertStatus(422);
});

it('queues a read query in the background from the query API', function () {
    Queue::fake();

    DbExplorer::roleUsing(fn (): string => 'viewer');

    $this->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/query', ['sql' => 'SELECT * FROM fixture.sentinel_table', 'background' => true])
        ->assertStatus(202);

    $model = Run::query()->sole();

    expect($model->type)->toBe(RunQuery::TYPE)
        ->and($model->meta['sql'])->toBe('SELECT * FROM fixture.sentinel_table');

    Queue::assertPushed(RunQuery::class, function (RunQuery $job) use ($model): bool {
        return $job->runId === $model->id && ! str_contains(serialize($job), 'sentinel');
    });
});

it('refuses to run anything but reads in the background', function () {
    DbExplorer::roleUsing(fn (): string => 'admin');

    $this->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/query', ['sql' => 'DROP TABLE fixture.sentinel_table', 'background' => true, 'confirm' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('background');
});
