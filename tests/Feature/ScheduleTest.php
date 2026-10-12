<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Console\ScheduleTickCommand;
use TacticalNinj4\DbExplorer\Events\ScheduleFailed;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Http\Controllers\ScheduleController;
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
 * A schedule for $saved (a new saved query by default), approved by user 1.
 *
 * @param  array<string, mixed>  $attributes
 */
function approvedSchedule(array $attributes = [], ?SavedQuery $saved = null): Schedule
{
    $schedule = (new Schedule($attributes + ['user_id' => '1', 'cron' => '* * * * *']))
        ->approve($saved ?? scheduledQuery(), '1', false, false);
    $schedule->save();

    return $schedule;
}

/**
 * The sql_hash an admin's client sends for the SQL it showed them (F36).
 */
function reviewedHash(SavedQuery|string $sql): string
{
    return hash('sha256', $sql instanceof SavedQuery ? $sql->sql : $sql);
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

    // created by user 7, approved (and so run) by user 1
    $due = approvedSchedule(['user_id' => '7']);
    $notDue = approvedSchedule(['cron' => '0 0 1 1 *'], scheduledQuery(['name' => 'Once a year']));
    $disabled = approvedSchedule(['enabled' => false], scheduledQuery(['name' => 'Paused']));

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

    $schedule = approvedSchedule();
    Cache::lock("dbx:schedule:{$schedule->id}", 60)->get();

    DbExplorer::roleUsing(fn (): string => 'admin');

    Artisan::call('dbx:schedule:tick');

    Queue::assertNotPushed(RunScheduledQuery::class);
    expect(Run::query()->count())->toBe(0)
        ->and($schedule->refresh()->last_run_at)->toBeNull();
});

it('S17 fails, disables and audits a schedule whose approver lost the admin role, then fires ScheduleFailed', function () {
    Queue::fake();

    $schedule = approvedSchedule();
    $fired = [];

    Event::listen(ScheduleFailed::class, function (ScheduleFailed $event) use (&$fired): void {
        $fired[] = ['event' => $event, 'audits' => AuditEntry::query()->orderBy('id')->pluck('action')->all()];
    });

    DbExplorer::roleUsing(fn (): ?string => null);

    Artisan::call('dbx:schedule:tick');

    Queue::assertNotPushed(RunScheduledQuery::class);

    $run = Run::query()->sole();

    expect($schedule->refresh()->enabled)->toBeFalse()
        ->and($run->status)->toBe(RunStatus::FAILED)
        ->and($run->user_id)->toBe('1')
        ->and($run->error)->toBe(ScheduleTickCommand::DEMOTED);

    $disabled = AuditEntry::query()->where('action', 'schedule.disabled')->sole();

    expect($disabled->user_id)->toBe('1')
        ->and($disabled->outcome)->toBe('error')
        ->and($disabled->error)->toBe(ScheduleTickCommand::DEMOTED)
        ->and($disabled->meta)->toEqual(['schedule_id' => $schedule->id, 'saved_query_id' => $schedule->saved_query_id, 'run_id' => $run->id]);

    // fired once, after the run's and the schedule's audit rows were written
    expect($fired)->toHaveCount(1)
        ->and($fired[0]['audits'])->toBe(['run.failed', 'schedule.disabled'])
        ->and(get_object_vars($fired[0]['event']))->toBe(['scheduleId' => $schedule->id, 'runId' => $run->id, 'error' => ScheduleTickCommand::DEMOTED]);
});

it('S11 disables a schedule without an approved SQL snapshot instead of running it', function () {
    Queue::fake();
    DbExplorer::roleUsing(fn (): string => 'admin');

    $unapproved = Schedule::query()->create(['saved_query_id' => scheduledQuery()->id, 'user_id' => '1', 'cron' => '* * * * *']);
    $tampered = approvedSchedule([], scheduledQuery(['name' => 'Tampered']));
    Schedule::query()->whereKey($tampered->id)->update(['sql' => 'DROP TABLE fixture.posts']);

    Artisan::call('dbx:schedule:tick');

    Queue::assertNotPushed(RunScheduledQuery::class);

    expect(Run::query()->count())->toBe(0)
        ->and($unapproved->refresh()->enabled)->toBeFalse()
        ->and($tampered->refresh()->enabled)->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'schedule.disabled')->pluck('error')->all())
        ->toBe([RunScheduledQuery::UNAPPROVED, RunScheduledQuery::UNAPPROVED]);
});

it('S11 backfills existing schedules from their saved query, unapproved and disabled', function () {
    Schema::drop('dbx_schedules');
    (require __DIR__.'/../../database/migrations/2026_10_09_000005_create_dbx_schedules_table.php')->up();

    $saved = scheduledQuery(['params' => [['name' => 'max', 'type' => 'int', 'required' => false]]]);
    DB::table('dbx_schedules')->insert(['saved_query_id' => $saved->id, 'user_id' => '1', 'cron' => '* * * * *', 'enabled' => true]);

    (require __DIR__.'/../../database/migrations/2026_10_09_000006_add_sql_snapshot_to_dbx_schedules_table.php')->up();

    $schedule = Schedule::query()->sole();

    expect($schedule->sql)->toBe($saved->sql)
        ->and($schedule->params)->toBe([['name' => 'max', 'type' => 'int', 'required' => false]])
        ->and($schedule->sql_hash)->toBe(hash('sha256', $saved->sql))
        ->and($schedule->enabled)->toBeFalse()
        ->and($schedule->approved_by)->toBeNull()
        ->and($schedule->approved_at)->toBeNull()
        ->and($schedule->allow_writes)->toBeFalse()
        ->and($schedule->allow_ddl)->toBeFalse()
        ->and($schedule->isApproved())->toBeFalse();

    // it can't be switched back on without an approval
    schedulesAs($this, 'PATCH', "/{$schedule->id}", ['enabled' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('enabled');
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
        'sql_hash' => reviewedHash($query),
        'cron' => '*/5 * * * *',
        'notify' => ['on' => ['failure', 'threshold'], 'threshold_rows' => 1000, 'mail' => ['ops@example.com']],
    ])->assertStatus(201)->json('data');

    expect($data['cron'])->toBe('*/5 * * * *')
        ->and($data['notify'])->toBe(['on' => ['failure', 'threshold'], 'threshold_rows' => 1000, 'mail' => ['ops@example.com']])
        ->and($data['sql'])->toBe('SELECT count(*) FROM fixture.posts')
        ->and($data['approved'])->toBeTrue()
        ->and($data['approved_by'])->toBe('1')
        ->and($data['allow_writes'])->toBeFalse()
        ->and($data['allow_ddl'])->toBeFalse()
        ->and($data['in_sync'])->toBeTrue();

    $audit = AuditEntry::query()->where('action', 'schedule.created')->sole();

    expect($audit->sql)->toBe('SELECT count(*) FROM fixture.posts')
        ->and($audit->classification)->toBe('read')
        ->and($audit->meta)->toEqual(['schedule_id' => $data['id'], 'saved_query_id' => $query->id, 'sql_hash' => hash('sha256', 'SELECT count(*) FROM fixture.posts'), 'allow_writes' => false, 'allow_ddl' => false]);

    schedulesAs($this, 'POST', '', ['saved_query_id' => $query->id, 'sql_hash' => reviewedHash($query), 'cron' => 'not a cron'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cron');

    schedulesAs($this, 'POST', '', ['saved_query_id' => 999, 'sql_hash' => reviewedHash($query), 'cron' => '* * * * *'])->assertNotFound();

    $parameterful = scheduledQuery([
        'name' => 'Needs input',
        'sql' => 'SELECT :id',
        'params' => [['name' => 'id', 'type' => 'int']],
    ]);

    schedulesAs($this, 'POST', '', ['saved_query_id' => $parameterful->id, 'sql_hash' => reviewedHash($parameterful), 'cron' => '* * * * *'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('saved_query_id');
});

it('pauses, resumes and deletes schedules', function () {
    $schedule = approvedSchedule();

    schedulesAs($this, 'PATCH', "/{$schedule->id}", ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);

    expect($schedule->refresh()->enabled)->toBeFalse();

    schedulesAs($this, 'DELETE', "/{$schedule->id}")->assertNoContent();

    expect(Schedule::query()->count())->toBe(0);
});

it('S11 snapshots the SQL: editing or hiding the saved query later does not change the schedule', function () {
    // user 2, a viewer, shares a SELECT; user 1, an admin, schedules it
    $shared = scheduledQuery(['user_id' => '2', 'visibility' => 'shared']);

    $id = schedulesAs($this, 'POST', '', ['saved_query_id' => $shared->id, 'sql_hash' => reviewedHash($shared), 'cron' => '* * * * *'])->assertStatus(201)->json('data.id');

    DbExplorer::roleUsing(fn (): string => 'viewer');

    $this->actingAs(makeUser(2))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->patchJson("/dbexplorer/api/main/queries/{$shared->id}", ['sql' => 'DROP TABLE fixture.posts', 'visibility' => 'private'])
        ->assertOk();

    $schedule = Schedule::query()->findOrFail($id);

    expect($schedule->sql)->toBe('SELECT count(*) FROM fixture.posts')
        ->and($schedule->isApproved())->toBeTrue()
        ->and(schedulesAs($this, 'GET', '')->json('data.0.in_sync'))->toBeFalse();

    // the admin can no longer see the now-private query, so can't re-sync it
    schedulesAs($this, 'POST', "/{$id}/resync", ['sql_hash' => reviewedHash('DROP TABLE fixture.posts')])->assertNotFound();

    expect($schedule->refresh()->sql)->toBe('SELECT count(*) FROM fixture.posts');
});

it('S11 asks for allow_writes for write SQL and allow_ddl for DDL before scheduling it', function () {
    $dml = scheduledQuery(['name' => 'Cleanup', 'sql' => 'DELETE FROM fixture.posts WHERE id < 0']);
    $ddl = scheduledQuery(['name' => 'Rebuild', 'sql' => 'DROP TABLE fixture.posts']);

    schedulesAs($this, 'POST', '', ['saved_query_id' => $dml->id, 'sql_hash' => reviewedHash($dml), 'cron' => '* * * * *'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('allow_writes');

    schedulesAs($this, 'POST', '', ['saved_query_id' => $ddl->id, 'sql_hash' => reviewedHash($ddl), 'cron' => '* * * * *', 'allow_writes' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('allow_ddl');

    expect(Schedule::query()->count())->toBe(0);
});

it('S5 approving writes or DDL for a schedule needs a recent password confirmation', function () {
    $dml = scheduledQuery(['name' => 'Cleanup', 'sql' => 'DELETE FROM fixture.posts WHERE id < 0']);

    schedulesAs($this, 'POST', '', ['saved_query_id' => $dml->id, 'sql_hash' => reviewedHash($dml), 'cron' => '* * * * *', 'allow_writes' => true])
        ->assertStatus(423);

    schedulesAs($this, 'POST', '', ['saved_query_id' => ($select = scheduledQuery())->id, 'sql_hash' => reviewedHash($select), 'cron' => '* * * * *', 'allow_ddl' => true])
        ->assertStatus(423);

    expect(Schedule::query()->count())->toBe(0);

    $this->withSession(['auth.password_confirmed_at' => time()]);

    $data = schedulesAs($this, 'POST', '', ['saved_query_id' => $dml->id, 'sql_hash' => reviewedHash($dml), 'cron' => '* * * * *', 'allow_writes' => true])
        ->assertStatus(201)
        ->json('data');

    expect($data['allow_writes'])->toBeTrue()
        ->and($data['allow_ddl'])->toBeFalse();
});

it('S17 re-syncs a schedule from its saved query for admins only, re-approved by them and audited', function () {
    $saved = scheduledQuery(['user_id' => '2', 'visibility' => 'shared']);
    $schedule = (new Schedule(['user_id' => '1', 'cron' => '* * * * *']))->approve($saved, '5', false, false);
    $schedule->save();

    $saved->update(['sql' => 'SELECT count(*) FROM fixture.users']);

    $reviewed = ['sql_hash' => reviewedHash($saved)];

    schedulesAs($this, 'POST', "/{$schedule->id}/resync", $reviewed, 'editor')->assertForbidden();
    schedulesAs($this, 'POST', "/{$schedule->id}/resync", $reviewed, 'viewer')->assertForbidden();

    expect($schedule->refresh()->sql)->toBe('SELECT count(*) FROM fixture.posts');

    $data = schedulesAs($this, 'POST', "/{$schedule->id}/resync", $reviewed)->assertOk()->json('data');

    expect($data['sql'])->toBe('SELECT count(*) FROM fixture.users')
        ->and($data['approved_by'])->toBe('1')
        ->and($data['in_sync'])->toBeTrue()
        ->and($schedule->refresh()->sql_hash)->toBe(hash('sha256', 'SELECT count(*) FROM fixture.users'));

    $audit = AuditEntry::query()->where('action', 'schedule.resynced')->sole();

    expect($audit->user_id)->toBe('1')
        ->and($audit->label)->toBe('main')
        ->and($audit->role)->toBe('admin')
        ->and($audit->sql)->toBe('SELECT count(*) FROM fixture.users')
        ->and($audit->meta['schedule_id'])->toBe($schedule->id);

    // re-syncing to write SQL asks for allow_writes, and then for the password
    $saved->update(['sql' => 'DELETE FROM fixture.posts WHERE id < 0']);

    $reviewed = ['sql_hash' => reviewedHash($saved)];

    schedulesAs($this, 'POST', "/{$schedule->id}/resync", $reviewed)->assertStatus(422)->assertJsonValidationErrors('allow_writes');
    schedulesAs($this, 'POST', "/{$schedule->id}/resync", $reviewed + ['allow_writes' => true])->assertStatus(423);

    expect($schedule->refresh()->sql)->toBe('SELECT count(*) FROM fixture.users');
});

it('F36 approves nothing when the saved query changed between review and approval: 409', function () {
    // the audit's proof of concept: the admin reviewed a harmless DELETE,
    // then the owner swapped in another one before the admin approved
    $saved = scheduledQuery(['user_id' => '2', 'visibility' => 'shared', 'sql' => 'DELETE FROM fixture.posts WHERE id = -2']);
    $reviewed = reviewedHash($saved);

    $saved->update(['sql' => 'DELETE FROM dbx_audit_log WHERE true']);

    $this->withSession(['auth.password_confirmed_at' => time()]);

    schedulesAs($this, 'POST', '', ['saved_query_id' => $saved->id, 'sql_hash' => $reviewed, 'cron' => '* * * * *', 'allow_writes' => true])
        ->assertStatus(409)
        ->assertJsonPath('message', ScheduleController::SQL_CHANGED);

    expect(Schedule::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'schedule.created')->exists())->toBeFalse();

    // the same for a re-sync: the schedule keeps its approved snapshot
    $saved->update(['sql' => 'DELETE FROM fixture.posts WHERE id = -2']);
    $schedule = approvedSchedule([], $saved);
    $reviewed = reviewedHash($saved);
    $saved->update(['sql' => 'DELETE FROM dbx_audit_log WHERE true']);

    schedulesAs($this, 'POST', "/{$schedule->id}/resync", ['sql_hash' => $reviewed, 'allow_writes' => true])
        ->assertStatus(409)
        ->assertJsonPath('message', ScheduleController::SQL_CHANGED);

    expect($schedule->refresh()->sql)->toBe('DELETE FROM fixture.posts WHERE id = -2')
        ->and(AuditEntry::query()->where('action', 'schedule.resynced')->exists())->toBeFalse();

    // reviewing the new SQL and approving that works
    schedulesAs($this, 'POST', "/{$schedule->id}/resync", ['sql_hash' => reviewedHash($saved), 'allow_writes' => true])
        ->assertOk()
        ->assertJsonPath('data.sql', 'DELETE FROM dbx_audit_log WHERE true');
});

it('F36 needs the reviewed sql_hash to create or re-sync a schedule', function (array $body) {
    $saved = scheduledQuery();
    $schedule = approvedSchedule([], $saved);

    schedulesAs($this, 'POST', '', ['saved_query_id' => $saved->id, 'cron' => '* * * * *'] + $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('sql_hash');

    schedulesAs($this, 'POST', "/{$schedule->id}/resync", $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('sql_hash');

    expect(Schedule::query()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'missing' => [[]],
    'empty' => [['sql_hash' => '']],
    'not a sha256' => [['sql_hash' => 'not-a-hash']],
    'upper case' => [['sql_hash' => strtoupper(hash('sha256', 'SELECT count(*) FROM fixture.posts'))]],
    'not a string' => [['sql_hash' => ['a']]],
]);

it('F36 gives saved queries their sql_hash, the value an approval sends back', function () {
    $saved = scheduledQuery();

    DbExplorer::roleUsing(fn (): string => 'admin');

    $listed = $this->actingAs(makeUser())->getJson('/dbexplorer/api/main/queries')->assertOk()->json('data.0');

    expect($listed['sql_hash'])->toBe(hash('sha256', $saved->sql));

    schedulesAs($this, 'POST', '', ['saved_query_id' => $saved->id, 'sql_hash' => $listed['sql_hash'], 'cron' => '* * * * *'])->assertStatus(201);
});
