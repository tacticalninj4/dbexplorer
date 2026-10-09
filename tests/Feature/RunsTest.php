<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Events\RunFinished;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Tests\Jobs\RunStatements;
use TacticalNinj4\DbExplorer\Tests\TestCase;

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly', 'editor' => 'pgsql_editor', 'admin' => 'pgsql'],
        'staging' => ['viewer' => 'staging_readonly', 'admin' => 'staging'],
    ]]);

    $this->artisan('migrate');
});

/**
 * Call a runs endpoint as user $user with $role on every label, with the
 * session's CSRF token, as the SPA sends it.
 */
function runsAs(TestCase $test, string $method, string $uri, string $role = 'viewer', int $user = 1): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser($user))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->json($method, '/dbexplorer/api/'.ltrim($uri, '/'));
}

it('lists only the user\'s own runs on the label, newest first', function () {
    $first = createRun();
    $second = createRun(['type' => 'export']);
    createRun(['user_id' => '2']);
    createRun(['label' => 'staging']);

    $response = runsAs($this, 'GET', 'main/runs')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$second->id, $first->id])
        ->and($response->json('meta'))->toBe(['page' => 1, 'per_page' => 50, 'has_more' => false]);
});

it('lists every user\'s runs on the label for admins', function () {
    createRun();
    createRun(['user_id' => '2']);
    createRun(['label' => 'staging']);

    expect(runsAs($this, 'GET', 'main/runs', 'admin', 3)->assertOk()->json('data'))->toHaveCount(2);
});

it('filters runs by status and type', function () {
    createRun(['type' => 'export', 'status' => RunStatus::FAILED]);
    $match = createRun(['type' => 'export', 'status' => RunStatus::SUCCEEDED]);
    createRun(['type' => 'query', 'status' => RunStatus::SUCCEEDED]);

    expect(array_column(runsAs($this, 'GET', 'main/runs?status=succeeded&type=export')->assertOk()->json('data'), 'id'))->toBe([$match->id]);

    runsAs($this, 'GET', 'main/runs?status=exploded')->assertStatus(422);
});

it('pages through runs', function () {
    foreach (range(1, 51) as $i) {
        createRun();
    }

    expect(runsAs($this, 'GET', 'main/runs')->json('meta.has_more'))->toBeTrue()
        ->and(runsAs($this, 'GET', 'main/runs?page=2')->json('data'))->toHaveCount(1);
});

it('shows a run without its result location, backend pid or meta', function () {
    $run = createRun([
        'status' => RunStatus::SUCCEEDED,
        'progress' => 100,
        'result_disk' => 'sentinel-disk',
        'result_path' => 'sentinel/path.csv',
        'pid' => 424242,
        'meta' => [Run::USER_PROVIDER => 'users', 'sql' => 'SELECT sentinel_sql', 'bindings' => ['sentinel-binding']],
        'started_at' => Date::now(),
        'finished_at' => Date::now(),
    ]);

    $response = runsAs($this, 'GET', "main/runs/{$run->id}")->assertOk();

    expect(array_keys($response->json('data')))->toBe([
        'id', 'type', 'status', 'progress', 'label', 'role', 'user_id', 'cancel_requested', 'error',
        'result_available', 'created_at', 'started_at', 'finished_at',
    ])
        ->and($response->json('data.result_available'))->toBeTrue()
        ->and($response->json('data.status'))->toBe('succeeded')
        ->and($response->getContent())->not->toContain('sentinel')->not->toContain('424242');
});

it('answers 404, not 403, for another user\'s run, a run on another label, or no run', function (string $method, string $suffix) {
    $other = createRun(['user_id' => '2']);
    $staging = createRun(['label' => 'staging']);

    runsAs($this, $method, "main/runs/{$other->id}{$suffix}")->assertNotFound();
    runsAs($this, $method, "main/runs/{$staging->id}{$suffix}")->assertNotFound();
    runsAs($this, $method, "main/runs/999{$suffix}")->assertNotFound();
    runsAs($this, $method, "main/runs/abc{$suffix}")->assertNotFound();

    expect(Run::query()->where('cancel_requested', true)->count())->toBe(0);
})->with([
    'show' => ['GET', ''],
    'cancel' => ['POST', '/cancel'],
]);

it('rejects users without a role on the label', function () {
    $run = createRun();

    DbExplorer::roleUsing(fn (): ?string => null);

    $this->actingAs(makeUser())->getJson("/dbexplorer/api/main/runs/{$run->id}")->assertForbidden();
});

it('cancels a queued run straight away, audited, and fires RunFinished', function () {
    Event::fake([RunFinished::class]);
    $run = createRun();

    $response = runsAs($this, 'POST', "main/runs/{$run->id}/cancel")->assertStatus(202);

    expect($response->json('data.status'))->toBe('cancelled')
        ->and($response->json('data.cancel_requested'))->toBeTrue()
        ->and($run->refresh()->finished_at)->not->toBeNull()
        ->and(AuditEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['run.cancel', 'run.cancelled']);

    $cancel = AuditEntry::query()->where('action', 'run.cancel')->firstOrFail();
    expect($cancel->user_id)->toBe('1')
        ->and($cancel->meta)->toBe(['run_id' => $run->id, 'type' => RunStatements::TYPE]);

    Event::assertDispatched(RunFinished::class, fn (RunFinished $event): bool => $event->runId === $run->id && $event->status === 'cancelled');
});

it('answers 409 when cancelling a finished run, and changes nothing', function (string $status) {
    $run = createRun(['status' => $status]);

    runsAs($this, 'POST', "main/runs/{$run->id}/cancel")->assertStatus(409)->assertJsonPath('data.status', $status);

    expect($run->refresh()->cancel_requested)->toBeFalse()
        ->and(AuditEntry::query()->count())->toBe(0);
})->with(RunStatus::FINISHED);

it('keeps answering 202 for a running run until its job stops', function () {
    $run = createRun(['status' => RunStatus::RUNNING, 'started_at' => Date::now()]);

    runsAs($this, 'POST', "main/runs/{$run->id}/cancel")->assertStatus(202)->assertJsonPath('data.status', 'running');
    runsAs($this, 'POST', "main/runs/{$run->id}/cancel")->assertStatus(202)->assertJsonPath('data.cancel_requested', true);
});

it('lets an admin cancel another user\'s run', function () {
    $run = createRun(['user_id' => '2']);

    runsAs($this, 'POST', "main/runs/{$run->id}/cancel", 'admin')->assertStatus(202)->assertJsonPath('data.status', 'cancelled');
});

it('creates a queued run for the request\'s user, auth provider, label and role', function () {
    $request = Request::create('/');
    $request->setUserResolver(fn () => makeUser(7));
    $request->attributes->add([ResolveRole::LABEL => 'main', ResolveRole::ROLE => 'editor']);

    $run = Run::queueFor($request, 'export', ['format' => 'csv']);

    expect($run->refresh()->only(['status', 'user_id', 'label', 'role', 'cancel_requested', 'meta']))->toBe([
        'status' => RunStatus::QUEUED,
        'user_id' => '7',
        'label' => 'main',
        'role' => 'editor',
        'cancel_requested' => false,
        'meta' => [Run::USER_PROVIDER => 'users', 'format' => 'csv'],
    ]);
});

it('S21 queues jobs with the run id only, on the configured queue', function () {
    config(['dbexplorer.queue' => ['connection' => 'redis', 'queue' => 'dbx']]);
    Queue::fake();

    $run = createRun(['meta' => [Run::USER_PROVIDER => 'users', 'statements' => ['SELECT sentinel_sql'], 'bindings' => ['sentinel-binding']]]);

    RunStatements::dispatch($run->id);

    Queue::assertPushedOn('dbx', RunStatements::class, function (RunStatements $job) use ($run): bool {
        $payload = serialize($job);

        return $job->runId === $run->id
            && $job->connection === 'redis'
            && ! str_contains($payload, 'sentinel')
            && ! str_contains($payload, 'pgsql');
    });
});

it('never retries and takes its timeout from limits.background.timeout', function () {
    config(['dbexplorer.limits.background.timeout' => 900]);

    $job = new RunStatements(1);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(900)
        ->and($job->failOnTimeout)->toBeTrue();
});

it('fails the run when the user\'s role was lowered or removed since it was queued', function (?string $now) {
    Event::fake([RunFinished::class]);
    createUsers(1);
    $run = createRun(['role' => 'editor']);

    DbExplorer::roleUsing(fn (): ?string => $now);
    app()->call([new RunStatements($run->id), 'handle']);

    $run->refresh();

    expect($run->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toStartWith('Permission changed')
        ->and($run->started_at)->toBeNull()
        ->and($run->finished_at)->not->toBeNull();

    expect(AuditEntry::query()->where('action', 'run.failed')->value('outcome'))->toBe('error');
    Event::assertDispatched(RunFinished::class, fn (RunFinished $event): bool => $event->status === 'failed');
})->with(['lowered' => ['viewer'], 'removed' => [null]]);

it('fails the run when its user no longer exists', function () {
    createUsers(2);
    $run = createRun();

    app()->call([new RunStatements($run->id), 'handle']);

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toStartWith('Permission changed');
});

it('passes the reloaded user to the role callback', function () {
    createUsers(1);
    $run = createRun(['role' => 'admin']);
    $seen = [];

    DbExplorer::roleUsing(function (mixed $user, string $label) use (&$seen): ?string {
        $seen[] = [$user?->getAuthIdentifier(), $user?->email, $label];

        return null;
    });

    app()->call([new RunStatements($run->id), 'handle']);

    expect($seen)->toBe([[1, 'user1@example.com', 'main']]);
});

it('skips a run that is gone or no longer queued, and cancels one cancelled while queued', function () {
    $cancelled = createRun(['status' => RunStatus::CANCELLED]);
    $flagged = createRun(['cancel_requested' => true]);

    app()->call([new RunStatements($cancelled->id), 'handle']);
    app()->call([new RunStatements($flagged->id), 'handle']);
    app()->call([new RunStatements(999), 'handle']);

    expect($cancelled->refresh()->status)->toBe(RunStatus::CANCELLED)
        ->and($flagged->refresh()->status)->toBe(RunStatus::CANCELLED)
        ->and(AuditEntry::query()->pluck('action')->all())->toBe(['run.cancelled']);
});

it('fails an active run from the failed() hook without storing the exception message', function () {
    $running = createRun(['status' => RunStatus::RUNNING, 'started_at' => Date::now()]);
    $timedOut = createRun(['status' => RunStatus::RUNNING, 'started_at' => Date::now()]);
    $done = createRun(['status' => RunStatus::SUCCEEDED]);

    (new RunStatements($running->id))->failed(new RuntimeException('password=sentinel'));
    (new RunStatements($timedOut->id))->failed(new MaxAttemptsExceededException('sentinel timed out'));
    (new RunStatements($done->id))->failed(new RuntimeException('sentinel'));

    expect($running->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($running->error)->toBe('The run failed unexpectedly. The application log has the details.')
        ->and($timedOut->refresh()->error)->toBe('The run timed out or its worker stopped.')
        ->and($done->refresh()->status)->toBe(RunStatus::SUCCEEDED)
        ->and(AuditEntry::query()->pluck('error')->implode(' '))->not->toContain('sentinel');
});

it('finishes a run once, auditing it and firing RunFinished with ids, status and duration only', function () {
    Event::fake([RunFinished::class]);
    $run = createRun([
        'status' => RunStatus::RUNNING,
        'started_at' => Date::now()->subSeconds(3),
        'meta' => [Run::USER_PROVIDER => 'users', 'sql' => 'SELECT sentinel'],
    ]);

    expect($run->finish(RunStatus::SUCCEEDED))->toBeTrue()
        ->and($run->finish(RunStatus::FAILED, 'late'))->toBeFalse()
        ->and($run->status)->toBe(RunStatus::SUCCEEDED)
        ->and($run->progress)->toBe(100);

    $audit = AuditEntry::query()->sole();

    expect($audit->only(['action', 'outcome', 'user_id', 'label', 'role', 'meta']))->toBe([
        'action' => 'run.succeeded',
        'outcome' => 'success',
        'user_id' => '1',
        'label' => 'main',
        'role' => 'viewer',
        'meta' => ['run_id' => $run->id, 'type' => RunStatements::TYPE],
    ])
        ->and($audit->duration_ms)->toBeGreaterThanOrEqual(2000);

    Event::assertDispatchedTimes(RunFinished::class, 1);
    Event::assertDispatched(RunFinished::class, function (RunFinished $event) use ($run, $audit): bool {
        expect(get_object_vars($event))->toBe([
            'auditId' => $audit->id,
            'runId' => $run->id,
            'type' => RunStatements::TYPE,
            'status' => 'succeeded',
            'durationMs' => $audit->duration_ms,
        ]);

        return true;
    });
});

it('warns in /api/me when the queue driver is sync', function () {
    config(['queue.default' => 'sync', 'dbexplorer.queue.connection' => null]);

    expect($this->actingAs(makeUser())->getJson('/dbexplorer/api/me')->assertOk()->json('warnings'))
        ->toBe(['The queue driver is "sync": background work runs inside the request and blocks it until done.']);
});

it('warns in /api/me when a run has been queued for over 5 minutes', function () {
    config(['queue.connections.dbx' => ['driver' => 'database'], 'dbexplorer.queue.connection' => 'dbx']);

    createRun(['status' => RunStatus::RUNNING, 'created_at' => Date::now()->subHour()]);
    $queued = createRun();

    expect($this->actingAs(makeUser())->getJson('/dbexplorer/api/me')->json('warnings'))->toBe([]);

    $queued->forceFill(['created_at' => Date::now()->subMinutes(6)])->save();

    expect($this->actingAs(makeUser())->getJson('/dbexplorer/api/me')->json('warnings'))
        ->toBe(['A background run has been queued for over 5 minutes: no queue worker seems to be running.']);
});
