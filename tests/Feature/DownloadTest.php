<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;

// M3.3, S16: GET api/{label}/runs/{run}/download hands out a 10-minute
// signed URL; api/runs/{run}/file serves the file behind it, to the run's
// owner or the label's admin only.

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly', 'editor' => 'pgsql_editor', 'admin' => 'pgsql'],
    ]]);

    $this->artisan('migrate');

    Storage::fake('local');
    config(['dbexplorer.storage.disk' => 'local']);
    Storage::disk('local')->put('exports/run-1.csv', "id,name\n1,Alice\n");

    DbExplorer::roleUsing(fn (): string => 'viewer');
});

/**
 * A succeeded export run for user $user, its result stored on the fake disk.
 */
function finishedRun(int $user = 1): Run
{
    return createRun([
        'type' => 'export',
        'user_id' => (string) $user,
        'status' => RunStatus::SUCCEEDED,
        'result_disk' => 'local',
        'result_path' => 'exports/run-1.csv',
        'finished_at' => now(),
    ]);
}

it('S16 hands the owner a signed URL that serves the file', function () {
    $run = finishedRun();

    $url = $this->actingAs(makeUser())
        ->getJson("/dbexplorer/api/main/runs/{$run->id}/download")
        ->assertOk()
        ->json('data.url');

    expect($url)->toStartWith('http');

    $file = $this->get($url);

    expect($file->getStatusCode())->toBe(200);

    $file->assertDownload(); // the file's contents come straight from the disk the job wrote
});

it('S16 answers 404 for a run without a result, another user\'s run, or none at all', function () {
    $other = finishedRun(2);

    $this->actingAs(makeUser())->getJson("/dbexplorer/api/main/runs/{$other->id}/download")->assertNotFound();

    $resultless = createRun(['status' => RunStatus::SUCCEEDED, 'result_path' => null]);

    $this->actingAs(makeUser())->getJson("/dbexplorer/api/main/runs/{$resultless->id}/download")->assertNotFound();
});

it('S16 lets the label admin download another user\'s run', function () {
    $run = finishedRun(2);

    DbExplorer::roleUsing(fn (): string => 'admin');

    $url = $this->actingAs(makeUser(3))
        ->getJson("/dbexplorer/api/main/runs/{$run->id}/download")
        ->assertOk()
        ->json('data.url');

    $this->get($url)->assertOk();
});

it('S16 refuses an expired signature', function () {
    $run = finishedRun();

    $url = $this->actingAs(makeUser())->getJson("/dbexplorer/api/main/runs/{$run->id}/download")->json('data.url');

    $this->travel(11)->minutes();

    $this->get($url)->assertForbidden();
});

it('S16 refuses a tampered signature', function () {
    $run = finishedRun();

    $url = $this->actingAs(makeUser())->getJson("/dbexplorer/api/main/runs/{$run->id}/download")->json('data.url');

    // Tamper with the signature itself.
    $url = preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $url) ?? $url;

    $this->get($url)->assertForbidden();
});

it('S16 refuses the file to anyone but the owner or the label admin, even with a valid signature', function () {
    $run = finishedRun(1);

    // A URL signed for the owner, fetched by someone else.
    $url = $this->actingAs(makeUser(1))->getJson("/dbexplorer/api/main/runs/{$run->id}/download")->json('data.url');

    $this->actingAs(makeUser(2))->get($url)->assertNotFound();
    $this->get($url)->assertNotFound(); // guest
});
