<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\RunScript;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Tests\TestCase;

// M3.5: POST api/{label}/script. The job itself runs against Postgres only
// (tests/Pgsql/ScriptJobTest.php); nothing here touches a database.

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly', 'editor' => 'pgsql_editor', 'admin' => 'pgsql'],
    ]]);

    $this->artisan('migrate');
});

/**
 * POST $body to /dbexplorer/api/main/script as user $user with $role, with
 * the session's CSRF token and an optional fresh password confirmation, as
 * the SPA sends it.
 *
 * @param  array<string, mixed>  $body
 */
function scriptAs(TestCase $test, array $body, string $role = 'editor', int $user = 1, bool $confirmed = false): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser($user))
        ->withSession(['_token' => 'csrf-test-token'] + ($confirmed ? ['auth.password_confirmed_at' => time()] : []))
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/script', $body);
}

it('requires the editor role or higher', function () {
    scriptAs($this, ['sql' => 'SELECT 1'], 'viewer')->assertForbidden()
        ->assertJsonPath('message', 'The script runner needs the editor role or higher.');

    expect(Run::query()->count())->toBe(0);
});

it('queues a read-only script for an editor without any confirmation', function () {
    Queue::fake();

    $run = scriptAs($this, ['sql' => "SELECT 1;\nSELECT 2"])->assertStatus(202)->json('data');

    expect($run['type'])->toBe('script')
        ->and($run['status'])->toBe(RunStatus::QUEUED)
        ->and($run)->not->toHaveKey('statements');

    Queue::assertPushed(RunScript::class);
});

it('queues a DML script once confirmed, with the script only in the run row', function () {
    Queue::fake();

    scriptAs($this, ['sql' => "INSERT INTO app.sentinel_table VALUES ('sentinel-value');"], 'editor', 7)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'confirmation_required')
        ->assertJsonPath('error.classification', 'dml');

    expect(Run::query()->count())->toBe(0);

    scriptAs($this, ['sql' => "INSERT INTO app.sentinel_table VALUES ('sentinel-value');", 'confirm' => true, 'mode' => 'step'], 'editor', 7)
        ->assertStatus(202);

    $run = Run::query()->sole();

    expect($run->only(['type', 'user_id', 'label', 'role', 'status']))->toBe([
        'type' => 'script',
        'user_id' => '7',
        'label' => 'main',
        'role' => 'editor',
        'status' => RunStatus::QUEUED,
    ])
        ->and($run->meta['mode'])->toBe('step')
        ->and($run->meta['statement_count'])->toBe(1)
        ->and($run->meta['sql'])->toContain('sentinel-value');

    Queue::assertPushed(RunScript::class, function (RunScript $job) use ($run): bool {
        return $job->runId === $run->id
            && ! str_contains(serialize($job), 'sentinel')
            && ! str_contains(serialize($job), 'pgsql');
    });
});

it('lets an admin queue a DDL script, but not an editor', function (string $role, int $status) {
    Queue::fake();

    scriptAs($this, ['sql' => 'CREATE TABLE app.widgets (id int);', 'confirm' => true], $role)->assertStatus($status);

    expect(Run::query()->count())->toBe($status === 202 ? 1 : 0);

    if ($status === 202) {
        Queue::assertPushed(RunScript::class);
    }
})->with([
    'editor refused' => ['editor', 403],
    'admin accepted' => ['admin', 202],
]);

it('S5 answers destructive scripts with 423 until the password is confirmed', function () {
    Queue::fake();

    scriptAs($this, ['sql' => 'DELETE FROM app.sentinel_table;', 'confirm' => true])
        ->assertStatus(423)
        ->assertExactJson(['message' => 'Password confirmation required.']);

    expect(Run::query()->count())->toBe(0);

    scriptAs($this, ['sql' => 'DELETE FROM app.sentinel_table;', 'confirm' => true], 'editor', 1, true)
        ->assertStatus(202);

    expect(Run::query()->sole()->meta['sql'])->toBe('DELETE FROM app.sentinel_table;');
});

it('refuses a script the splitter cannot split, naming the line', function () {
    scriptAs($this, ['sql' => "SELECT 1;\n\\set x 1"], 'admin', 1, true)
        ->assertStatus(422)
        ->assertJsonValidationErrors('sql');

    expect(Run::query()->count())->toBe(0);
});

it('refuses a script with no statement to run', function () {
    scriptAs($this, ['sql' => "-- nothing to see\n/* here either */"], 'admin', 1, true)
        ->assertStatus(422)
        ->assertJsonPath('message', 'There is no statement to run.');

    scriptAs($this, ['sql' => ''], 'admin', 1, true)
        ->assertStatus(422)
        ->assertJsonValidationErrors('sql');

    expect(Run::query()->count())->toBe(0);
});

it('validates the mode', function () {
    scriptAs($this, ['sql' => 'SELECT 1', 'mode' => 'explosive'], 'admin', 1, true)
        ->assertStatus(422)
        ->assertJsonValidationErrors('mode');
});
