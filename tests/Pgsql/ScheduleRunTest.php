<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Events\ScheduleFailed;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\RunScheduledQuery;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Models\SavedQuery;
use TacticalNinj4\DbExplorer\Models\Schedule;
use TacticalNinj4\DbExplorer\Notifications\ScheduledQueryFinished;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

// M4.2: the scheduled query job, against the fixture Postgres.

beforeEach(function () {
    app()['env'] = 'local';

    createUsers(1);
    DbExplorer::roleUsing(fn ($user): string => (string) $user->getAuthIdentifier() === '1' ? 'admin' : 'viewer');
});

/**
 * A schedule for a saved query, approved by user 1, and its job run inline.
 *
 * @param  array<string, mixed>  $notify
 */
function runScheduled(string $sql, array $notify = [], bool $allowWrites = false, bool $allowDdl = false): Run
{
    $saved = SavedQuery::query()->create(['user_id' => '1', 'label' => 'main', 'name' => 'Nightly count', 'sql' => $sql]);
    $schedule = (new Schedule(['user_id' => '1', 'cron' => '* * * * *', 'notify' => $notify ?: null]))->approve($saved, '1', $allowWrites, $allowDdl);
    $schedule->save();

    $run = Run::query()->create([
        'type' => RunScheduledQuery::TYPE,
        'user_id' => '1',
        'label' => 'main',
        'role' => 'admin',
        'meta' => [Run::USER_PROVIDER => 'users', 'schedule_id' => $schedule->id],
    ]);

    app()->call([new RunScheduledQuery($run->id), 'handle']);

    return $run;
}

/**
 * Call the API as user $id, the admin for user 1 and a viewer for anyone else.
 *
 * @param  array<string, mixed>  $body
 */
function scheduleApi(PgsqlTestCase $test, int $id, string $method, string $uri, array $body = []): TestResponse
{
    return $test->actingAs(makeUser($id))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->json($method, '/dbexplorer/api/main/'.$uri, $body);
}

it('runs the scheduled query as its approver, records it and stamps the schedule', function () {
    Notification::fake();

    $run = runScheduled('SELECT count(*) AS posts FROM fixture.posts');

    $schedule = Schedule::query()->sole();

    expect($run->refresh()->status)->toBe(RunStatus::SUCCEEDED)
        ->and($schedule->last_run_id)->toBe($run->id)
        ->and($schedule->last_run_at)->not->toBeNull()
        // the query itself was audited by the runner, like every query
        ->and(AuditEntry::query()->where('action', 'query')->exists())->toBeTrue();

    Notification::assertNothingSent();
});

it('mails the configured recipients on success when asked', function () {
    Notification::fake();

    runScheduled('SELECT 1', ['on' => ['success'], 'mail' => ['ops@example.com', 'dev@example.com']]);

    Notification::assertSentOnDemand(
        ScheduledQueryFinished::class,
        fn (ScheduledQueryFinished $notification, $channels, $notifiable): bool => $notification->status === 'succeeded'
            && $notification->rows === 1
            && $notifiable->routeNotificationFor('mail') === ['ops@example.com', 'dev@example.com'],
    );
});

it('mails only past the row threshold when that is what was asked', function () {
    Notification::fake();

    runScheduled('SELECT * FROM fixture.users', ['on' => ['threshold'], 'threshold_rows' => 1000, 'mail' => ['ops@example.com']]);

    Notification::assertNothingSent();
});

it('reports a failure to the recipients and fires ScheduleFailed', function () {
    Notification::fake();
    Event::fake([ScheduleFailed::class]);

    $run = runScheduled('SELECT * FROM fixture.absent_table', ['on' => ['failure'], 'mail' => ['ops@example.com']]);

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toContain('42P01');

    Notification::assertSentOnDemand(
        ScheduledQueryFinished::class,
        fn (ScheduledQueryFinished $notification): bool => $notification->status === 'failed' && str_contains($notification->error ?? '', '42P01'),
    );

    Event::assertDispatched(ScheduleFailed::class, fn (ScheduleFailed $event): bool => $event->scheduleId === Schedule::query()->sole()->id && $event->runId === $run->id);
});

it('S17 fails the run, disables the schedule and fires ScheduleFailed when the approver lost the admin role since the tick', function () {
    Event::fake([ScheduleFailed::class]);
    DbExplorer::roleUsing(fn (): string => 'editor');

    $run = runScheduled('SELECT 1');
    $schedule = Schedule::query()->sole();

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toStartWith('Permission changed')
        ->and($schedule->enabled)->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'schedule.disabled')->sole()->meta['run_id'])->toBe($run->id)
        ->and(AuditEntry::query()->where('action', 'query')->exists())->toBeFalse();

    Event::assertDispatched(ScheduleFailed::class, fn (ScheduleFailed $event): bool => $event->scheduleId === $schedule->id && $event->runId === $run->id);
});

it('S11 runs the approved snapshot: a viewer editing their shared query to DROP afterwards drops nothing', function () {
    createUsers(2);
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$schema}.victim (id int)");

    // user 2 (a viewer) shares a SELECT, user 1 (an admin) schedules it
    $saved = scheduleApi($this, 2, 'POST', 'queries', ['name' => 'Count', 'sql' => "SELECT count(*) FROM {$schema}.victim", 'visibility' => 'shared'])
        ->assertStatus(201)
        ->json('data');

    scheduleApi($this, 1, 'POST', 'schedules', ['saved_query_id' => $saved['id'], 'cron' => '* * * * *'])->assertStatus(201);

    // then edits it into DDL
    scheduleApi($this, 2, 'PATCH', "queries/{$saved['id']}", ['sql' => "DROP TABLE {$schema}.victim"])->assertOk();

    Artisan::call('dbx:schedule:tick');

    $run = Run::query()->sole();

    expect($run->status)->toBe(RunStatus::SUCCEEDED)
        ->and($run->user_id)->toBe('1')
        ->and(Schema::connection('pgsql_super')->hasTable("{$schema}.victim"))->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'query')->sole()->sql)->toBe("SELECT count(*) FROM {$schema}.victim");
});

it('S11 keeps running the snapshot after the viewer makes their query private and changes it', function () {
    createUsers(2);

    $saved = scheduleApi($this, 2, 'POST', 'queries', ['name' => 'Count', 'sql' => 'SELECT count(*) FROM fixture.posts', 'visibility' => 'shared'])->json('data');
    scheduleApi($this, 1, 'POST', 'schedules', ['saved_query_id' => $saved['id'], 'cron' => '* * * * *'])->assertStatus(201);
    scheduleApi($this, 2, 'PATCH', "queries/{$saved['id']}", ['sql' => 'SELECT 1/0', 'visibility' => 'private'])->assertOk();

    Artisan::call('dbx:schedule:tick');

    expect(Run::query()->sole()->status)->toBe(RunStatus::SUCCEEDED)
        ->and(AuditEntry::query()->where('action', 'query')->sole()->sql)->toBe('SELECT count(*) FROM fixture.posts');
});

it('S9 runs a schedule without allow_writes in a read-only transaction, so writes fail at the database', function () {
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->statement("CREATE SEQUENCE {$schema}.counter");

    // the classifier calls this a read; the database knows better
    $saved = scheduleApi($this, 1, 'POST', 'queries', ['name' => 'Bump', 'sql' => "SELECT nextval('{$schema}.counter')"])->json('data');
    scheduleApi($this, 1, 'POST', 'schedules', ['saved_query_id' => $saved['id'], 'cron' => '* * * * *'])->assertStatus(201);

    Artisan::call('dbx:schedule:tick');

    $run = Run::query()->sole();

    expect($run->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toStartWith('25006')
        ->and(DB::connection('pgsql_super')->selectOne("SELECT last_value, is_called FROM {$schema}.counter")->is_called)->toBeFalse();

    // plain DML commits only when approved with allow_writes
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$schema}.notes (id int)");

    expect(runScheduled("INSERT INTO {$schema}.notes VALUES (1)", [], true)->refresh()->status)->toBe(RunStatus::SUCCEEDED)
        ->and(DB::connection('pgsql_super')->table("{$schema}.notes")->count())->toBe(1)
        ->and(runScheduled("INSERT INTO {$schema}.notes VALUES (2)")->refresh()->error)->toStartWith('25006')
        ->and(DB::connection('pgsql_super')->table("{$schema}.notes")->count())->toBe(1);
});

it('S11 refuses to run DDL from a schedule approved without allow_ddl', function () {
    $schema = $this->scratchSchema();

    $run = runScheduled("CREATE TABLE {$schema}.made (id int)", [], true);

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toContain('does not allow DDL')
        ->and(Schema::connection('pgsql_super')->hasTable("{$schema}.made"))->toBeFalse()
        ->and(runScheduled("CREATE TABLE {$schema}.made (id int)", [], true, true)->refresh()->status)->toBe(RunStatus::SUCCEEDED)
        ->and(Schema::connection('pgsql_super')->hasTable("{$schema}.made"))->toBeTrue();
});

it('S7 ScheduleFailed carries no SQL, bound values or connection config, and fires after the run\'s audit row', function () {
    $fired = [];

    Event::listen(ScheduleFailed::class, function (ScheduleFailed $event) use (&$fired): void {
        $fired[] = ['event' => $event, 'last_audit' => AuditEntry::query()->orderByDesc('id')->value('action')];
    });

    $sql = "SELECT * FROM fixture.absent_table WHERE note = 'top-secret-literal'";
    $run = runScheduled($sql);

    expect($fired)->toHaveCount(1)
        ->and($fired[0]['last_audit'])->toBe('run.failed')
        ->and(array_keys(get_object_vars($fired[0]['event'])))->toBe(['scheduleId', 'runId', 'error'])
        ->and($fired[0]['event']->runId)->toBe($run->id)
        ->and($fired[0]['event']->error)->toStartWith('42P01');

    $payload = serialize($fired[0]['event']);
    $config = config('database.connections.pgsql_admin');

    foreach ([$sql, 'top-secret-literal', $config['host'], $config['username'], $config['password'], 'pgsql_admin'] as $secret) {
        expect(str_contains($payload, (string) $secret))->toBeFalse();
    }
});

it('runs a schedule whose last run failed again on the next due tick', function () {
    Event::fake([ScheduleFailed::class]);

    $saved = SavedQuery::query()->create(['user_id' => '1', 'label' => 'main', 'name' => 'Broken', 'sql' => 'SELECT * FROM fixture.absent_table']);
    $schedule = (new Schedule(['user_id' => '1', 'cron' => '* * * * *']))->approve($saved, '1', false, false);
    $schedule->save();

    Artisan::call('dbx:schedule:tick');
    $this->travel(1)->minutes();
    Artisan::call('dbx:schedule:tick');

    expect(Run::query()->orderBy('id')->pluck('status')->all())->toBe([RunStatus::FAILED, RunStatus::FAILED])
        ->and($schedule->refresh()->enabled)->toBeTrue();

    Event::assertDispatchedTimes(ScheduleFailed::class, 2);
});
