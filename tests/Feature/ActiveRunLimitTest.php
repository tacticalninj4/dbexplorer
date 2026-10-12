<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\Export;
use TacticalNinj4\DbExplorer\Jobs\RunQuery;
use TacticalNinj4\DbExplorer\Jobs\RunScheduledQuery;
use TacticalNinj4\DbExplorer\Jobs\RunScript;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Tests\TestCase;

// F37: limits.background.max_active_per_user caps the runs one user has
// queued or running per label. Nothing here touches a database.

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly', 'editor' => 'pgsql_editor', 'admin' => 'pgsql'],
        'staging' => ['viewer' => 'staging_readonly', 'editor' => 'staging_editor', 'admin' => 'staging'],
    ]]);

    $this->artisan('migrate');

    Queue::fake();
});

/**
 * Start one background run on $label as user $user, through $endpoint.
 */
function startRun(TestCase $test, string $endpoint, int $user = 1, string $label = 'main'): TestResponse
{
    DbExplorer::roleUsing(fn (): string => 'editor');

    [$uri, $body] = [
        'query' => ['query', ['sql' => 'SELECT pg_sleep(60)', 'background' => true]],
        'export' => ['export', ['sql' => 'SELECT 1', 'format' => 'csv']],
        'script' => ['script', ['sql' => "SELECT 1;\nSELECT 2"]],
    ][$endpoint];

    return $test->actingAs(makeUser($user))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson("/dbexplorer/api/{$label}/{$uri}", $body);
}

it('F37 queues up to max_active_per_user runs per user, then answers 429 and queues nothing', function (string $endpoint, string $job) {
    foreach ([1, 2, 3] as $i) {
        startRun($this, $endpoint)->assertStatus(202);
    }

    startRun($this, $endpoint)
        ->assertStatus(429)
        ->assertJsonPath('message', 'You already have 3 background runs queued or running on this connection. Wait for one to finish, or cancel one on the Runs page, then try again.');

    expect(Run::query()->count())->toBe(3);
    Queue::assertPushed($job, 3);
})->with([
    'background query' => ['query', RunQuery::class],
    'export' => ['export', Export::class],
    'script' => ['script', RunScript::class],
]);

it('F37 counts every kind of run the user has queued or running on the label', function () {
    createRun(['type' => 'export', 'status' => RunStatus::RUNNING]);
    createRun(['type' => 'import']);
    createRun(['type' => 'backup', 'status' => RunStatus::RUNNING]);

    startRun($this, 'query')->assertStatus(429);

    expect(Run::query()->count())->toBe(3);
});

it('F37 does not count finished runs, scheduled runs, other users or other labels', function () {
    foreach (RunStatus::FINISHED as $status) {
        createRun(['status' => $status]);
    }

    createRun(['type' => RunScheduledQuery::TYPE, 'status' => RunStatus::RUNNING]);
    createRun(['user_id' => '2']);
    createRun(['user_id' => '2', 'status' => RunStatus::RUNNING]);
    createRun(['label' => 'staging']);
    createRun(['label' => 'staging', 'status' => RunStatus::RUNNING]);
    createRun(['label' => 'staging']);

    foreach ([1, 2, 3] as $i) {
        startRun($this, 'query')->assertStatus(202);
    }

    startRun($this, 'query')->assertStatus(429);

    // user 2 and the other label have their own budgets
    startRun($this, 'query', 2)->assertStatus(202);
    startRun($this, 'query', 1, 'staging')->assertStatus(429);
    startRun($this, 'query', 3, 'staging')->assertStatus(202);
});

it('F37 frees a slot when a run finishes', function () {
    foreach ([1, 2, 3] as $i) {
        startRun($this, 'export')->assertStatus(202);
    }

    startRun($this, 'export')->assertStatus(429);

    Run::query()->orderBy('id')->firstOrFail()->finish(RunStatus::CANCELLED);

    startRun($this, 'export')->assertStatus(202);
});

it('F37 takes the cap from limits.background.max_active_per_user, and 0 turns it off', function () {
    config(['dbexplorer.limits.background.max_active_per_user' => 1]);

    startRun($this, 'script')->assertStatus(202);
    startRun($this, 'script')
        ->assertStatus(429)
        ->assertJsonPath('message', 'You already have 1 background run queued or running on this connection. Wait for one to finish, or cancel one on the Runs page, then try again.');

    config(['dbexplorer.limits.background.max_active_per_user' => 0]);

    foreach (range(1, 5) as $i) {
        startRun($this, 'script')->assertStatus(202);
    }

    expect(Run::query()->count())->toBe(6);
});

it('F37 defaults to 3 active runs per user', function () {
    expect(config('dbexplorer.limits.background.max_active_per_user'))->toBe(3);
});
