<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\RunScheduledQuery;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Models\SavedQuery;
use TacticalNinj4\DbExplorer\Models\Schedule;
use TacticalNinj4\DbExplorer\Tests\TestCase;

// M4.2: the schedule tick, its lock, demotion, and the schedules API. The
// job's query run and notifications live in tests/Pgsql/ScheduleRunTest.php.

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly', 'editor' => 'pgsql_editor', 'admin' => 'pgsql'],
    ]]);

    $this->artisan('migrate');

    createUsers(1);
});

function scheduledQuery(array $attributes = []): SavedQuery
{
    return SavedQuery::query()->create($attributes + [
        'user_id' => '1',
        'label' => 'main',
        'name' => 'Nightly count',
        'sql' => 'SELECT count(*) FROM fixture.posts',
    ]);
}

/**
 * Call a schedules endpoint as $role (admin by default).
 *
 * @param  array<string, mixed>  $body
 */
function schedulesAs(TestCase $test, string $method, string $uri, array $body = [], string $role = 'admin'): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->json($method, '/dbexplorer/api/main/schedules'.($uri === '' ? '' : '/'.ltrim($uri, '/')), $body);
}

it('dispatches a run for every due schedule, and only due ones', function () {
    Queue::fake();

    $due = Schedule::query()->create(['saved_query_id' => scheduledQuery()->id, 'user_id' => '1', 'cron' => '* * * * *']);
    $notDue = Schedule::query()->create(['saved_query_id' => scheduledQuery(['name' => 'Once a year'])->id, 'user_id' => '1', 'cron' => '0 0 1 1 *']);
    $disabled = Schedule::query()->create(['saved_query_id' => scheduledQuery(['name' => 'Paused'])->id, 'user_id' => '1', 'cron' => '* * * * *', 'enabled' => false]);

    DbExplorer::roleUsing(fn (): string => 'admin');

    Artisan::call('dbx:schedule:tick');

    $run = Run::query()->sole();

    expect($run->only(['type', 'user_id', 'label', 'role', 'status']))->toBe([
        'type' => RunScheduledQuery::TYPE,
        'user_id' => '1',
        'label' => 'main',
        'role' => 'admin',
        'status' => RunStatus::QUEUED,
    ])
        ->and($run->meta['schedule_id'])->toBe($due->id)
        ->and($due->refresh()->last_run_id)->toBe($run->id)
        ->and($notDue->refresh()->last_run_id)->toBeNull()
        ->and($disabled->refresh()->last_run_id)->toBeNull();

    Queue::assertPushed(RunScheduledQuery::class, fn (RunScheduledQuery $job): bool => $job->runId === $run->id);
});

it('skips a schedule whose lock is still held', function () {
    Queue::fake();

    $schedule = Schedule::query()->create(['saved_query_id' => scheduledQuery()->id, 'user_id' => '1', 'cron' => '* * * * *']);
    Cache::lock("dbx:schedule:{$schedule->id}", 60)->get();

    DbExplorer::roleUsing(fn (): string => 'admin');

    Artisan::call('dbx:schedule:tick');

    Queue::assertNotPushed(RunScheduledQuery::class);
    expect(Run::query()->count())->toBe(0)
        ->and($schedule->refresh()->last_run_at)->toBeNull();
});

it('disables and audits a schedule whose creator lost the admin role', function () {
    Queue::fake();

    $schedule = Schedule::query()->create(['saved_query_id' => scheduledQuery()->id, 'user_id' => '1', 'cron' => '* * * * *']);

    DbExplorer::roleUsing(fn (): ?string => null);

    Artisan::call('dbx:schedule:tick');

    expect($schedule->refresh()->enabled)->toBeFalse()
        ->and(Run::query()->count())->toBe(0);

    Queue::assertNotPushed(RunScheduledQuery::class);

    $audit = AuditEntry::query()->sole();

    expect($audit->action)->toBe('schedule.disabled')
        ->and($audit->user_id)->toBe('1')
        ->and($audit->outcome)->toBe('error')
        ->and($audit->error)->toBe('The creator no longer has the admin role.');
});

it('registers dbx:schedule:tick every minute', function () {
    $events = app(ConsoleSchedule::class)->events();

    $tick = array_values(array_filter($events, fn ($event): bool => str_contains((string) $event->command ?? '', 'dbx:schedule:tick')));

    expect($tick)->toHaveCount(1)
        ->and($tick[0]->expression)->toBe('* * * * *');
});

it('manages schedules for admins only', function (string $role) {
    schedulesAs($this, 'GET', '', [], $role)->assertForbidden();
})->with(['viewer', 'editor']);

it('creates a schedule, and refuses invalid crons and required parameters', function () {
    $query = scheduledQuery();

    $data = schedulesAs($this, 'POST', '', [
        'saved_query_id' => $query->id,
        'cron' => '*/5 * * * *',
        'notify' => ['on' => ['failure', 'threshold'], 'threshold_rows' => 1000, 'mail' => ['ops@example.com']],
    ])->assertStatus(201)->json('data');

    expect($data['cron'])->toBe('*/5 * * * *')
        ->and($data['notify'])->toBe(['on' => ['failure', 'threshold'], 'threshold_rows' => 1000, 'mail' => ['ops@example.com']]);

    schedulesAs($this, 'POST', '', ['saved_query_id' => $query->id, 'cron' => 'not a cron'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cron');

    schedulesAs($this, 'POST', '', ['saved_query_id' => 999, 'cron' => '* * * * *'])->assertNotFound();

    $parameterful = scheduledQuery([
        'name' => 'Needs input',
        'sql' => 'SELECT :id',
        'params' => [['name' => 'id', 'type' => 'int']],
    ]);

    schedulesAs($this, 'POST', '', ['saved_query_id' => $parameterful->id, 'cron' => '* * * * *'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('saved_query_id');
});

it('pauses, resumes and deletes schedules', function () {
    $schedule = Schedule::query()->create(['saved_query_id' => scheduledQuery()->id, 'user_id' => '1', 'cron' => '* * * * *']);

    schedulesAs($this, 'PATCH', "/{$schedule->id}", ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);

    expect($schedule->refresh()->enabled)->toBeFalse();

    schedulesAs($this, 'DELETE', "/{$schedule->id}")->assertNoContent();

    expect(Schedule::query()->count())->toBe(0);
});
