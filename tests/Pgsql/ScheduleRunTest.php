<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use TacticalNinj4\DbExplorer\Events\ScheduleFailed;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\RunScheduledQuery;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Models\SavedQuery;
use TacticalNinj4\DbExplorer\Models\Schedule;
use TacticalNinj4\DbExplorer\Notifications\ScheduledQueryFinished;

// M4.2: the scheduled query job, against the fixture Postgres.

beforeEach(function () {
    app()['env'] = 'local';

    createUsers(1);
    DbExplorer::roleUsing(fn (): string => 'admin');
});

/**
 * A schedule for a saved query, and its job run inline.
 *
 * @param  array<string, mixed>  $notify
 */
function runScheduled(string $sql, array $notify = []): Run
{
    $saved = SavedQuery::query()->create(['user_id' => '1', 'label' => 'main', 'name' => 'Nightly count', 'sql' => $sql]);
    $schedule = Schedule::query()->create(['saved_query_id' => $saved->id, 'user_id' => '1', 'cron' => '* * * * *', 'notify' => $notify ?: null]);

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

it('runs the scheduled query as its creator, records it and stamps the schedule', function () {
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

    $schedule = Schedule::query()->sole();
    fwrite(STDERR, "\nNOTIF-DBG: settings=".json_encode($schedule->notifySettings()).' raw='.json_encode($schedule->notify).' run='.Schedule::query()->sole()->last_run_id."\n");

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

it('fails the run when the creator lost the admin role since the tick', function () {
    DbExplorer::roleUsing(fn (): string => 'editor');

    $run = runScheduled('SELECT 1');

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toStartWith('Permission changed');
});
