<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\RunQuery;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\SavedQuery;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

// M4.1: running saved queries with typed parameters, against the fixture.

beforeEach(function () {
    app()['env'] = 'local';
});

/**
 * POST to a saved query's run endpoint as $role, as the SPA sends it.
 *
 * @param  array<string, mixed>  $body
 */
function runSaved(PgsqlTestCase $test, int $id, array $body = [], string $role = 'viewer'): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson("/dbexplorer/api/main/queries/{$id}/run", $body);
}

it('runs a shared saved query with its typed parameters bound', function () {
    $query = SavedQuery::query()->create([
        'user_id' => '1',
        'label' => 'main',
        'name' => 'Recent posts',
        'sql' => 'SELECT id FROM fixture.posts WHERE user_id = :author AND id < :max ORDER BY id',
        'params' => [
            ['name' => 'author', 'type' => 'int'],
            ['name' => 'max', 'type' => 'int'],
        ],
        'visibility' => 'shared',
    ]);

    $data = runSaved($this, $query->id, ['values' => ['author' => 1, 'max' => 3]])->assertOk()->json('data');

    $expected = DB::connection('pgsql_super')->table('fixture.posts')->where('user_id', 1)->where('id', '<', 3)->orderBy('id')->pluck('id')->all();

    expect($data['rows'])->toBe(array_map(fn ($id) => [$id], $expected))
        ->and($data['classification'])->toBe('read');
});

it('answers 422 for a value its parameter type rejects, and 404 for another user\'s private query', function () {
    $query = SavedQuery::query()->create([
        'user_id' => '1',
        'label' => 'main',
        'name' => 'Typed',
        'sql' => 'SELECT :author AS author',
        'params' => [['name' => 'author', 'type' => 'int']],
    ]);

    runSaved($this, $query->id, ['values' => ['author' => 'not-a-number']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('values');

    $private = SavedQuery::query()->create([
        'user_id' => '9',
        'label' => 'main',
        'name' => 'Private',
        'sql' => 'SELECT 1',
    ]);

    runSaved($this, $private->id)->assertNotFound();
});

it('applies the editor\'s confirmation ladder to saved queries', function () {
    $dml = SavedQuery::query()->create([
        'user_id' => '1',
        'label' => 'main',
        'name' => 'Updater',
        'sql' => 'UPDATE fixture.counters SET value = value WHERE name = :name',
        'params' => [['name' => 'name', 'type' => 'string']],
        'visibility' => 'shared',
    ]);

    runSaved($this, $dml->id, ['values' => ['name' => 'sessions']], 'editor')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'confirmation_required');

    // A viewer is refused before any confirmation (F41), confirmed or not,
    // in the background too: their read-only transaction would refuse the
    // write anyway, same as the SQL editor.
    foreach ([[], ['confirm' => true], ['background' => true]] as $extra) {
        runSaved($this, $dml->id, ['values' => ['name' => 'sessions']] + $extra, 'viewer')
            ->assertForbidden()
            ->assertJsonPath('message', "Viewers can't change data (INSERT, UPDATE, DELETE, MERGE, CALL, SELECT ... FOR UPDATE).");
    }

    expect(Run::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'query')->count())->toBe(0);

    DbExplorer::roleUsing(fn (): string => 'editor');

    $this->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson("/dbexplorer/api/main/queries/{$dml->id}/run", ['values' => ['name' => 'sessions'], 'confirm' => true])
        ->assertOk();
});

it('queues a read saved query for the background', function () {
    Queue::fake();

    $query = SavedQuery::query()->create([
        'user_id' => '1',
        'label' => 'main',
        'name' => 'Big read',
        'sql' => 'SELECT * FROM fixture.big WHERE id < :limit',
        'params' => [['name' => 'limit', 'type' => 'int']],
        'visibility' => 'shared',
    ]);

    runSaved($this, $query->id, ['values' => ['limit' => 100], 'background' => true])->assertStatus(202);

    Queue::assertPushed(RunQuery::class);

    expect(Run::query()->sole()->meta['sql'])->toBe('SELECT * FROM fixture.big WHERE id < ?');
});
