<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Http\Controllers\ImportController;
use TacticalNinj4\DbExplorer\Jobs\Import;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Tests\TestCase;

// M3.4: the import API's upload, preview and dispatch. The job itself runs
// against Postgres only (tests/Pgsql/ImportJobTest.php).

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly', 'editor' => 'pgsql_editor', 'admin' => 'pgsql'],
    ]]);

    $this->artisan('migrate');

    Storage::fake('local');
    config(['dbexplorer.storage.disk' => 'local']);
});

/**
 * POST to /dbexplorer/api/main/imports... as user $user with $role, with the
 * session's CSRF token, as the SPA sends it.
 *
 * @param  array<string, mixed>  $body
 */
function importAs(TestCase $test, string $action, array $body, string $role = 'editor', int $user = 1): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser($user))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson("/dbexplorer/api/main/imports{$action}", $body);
}

function csvFile(string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'dbx-csv');
    file_put_contents($path, $contents);

    return new UploadedFile($path, 'upload.csv', 'text/csv', null, true);
}

it('rejects viewers from every import endpoint', function () {
    importAs($this, '', ['file' => csvFile("a\n1")], 'viewer')->assertForbidden()->assertJsonPath('message', 'Importing needs the editor role.');
    importAs($this, '/preview', ['token' => '1-'.str_repeat('a', 40)], 'viewer')->assertForbidden();
    importAs($this, '/run', ['token' => '1-'.str_repeat('a', 40), 'table' => ['schema' => 's', 'name' => 't'], 'map' => ['a']], 'viewer')->assertForbidden();

    expect(Run::query()->count())->toBe(0)
        ->and(Storage::disk('local')->files('imports'))->toBe([]);
});

it('stores an upload and previews its first rows and columns', function () {
    $token = importAs($this, '', ['file' => csvFile("name,note\nAlice,first\nBob,\"second, quoted\"\n".implode("\n", array_map(fn (int $i): string => "User{$i},row{$i}", range(3, 30))))])
        ->assertStatus(201)
        ->json('data.token');

    expect($token)->toMatch('/^1-[a-f0-9]{40}$/')
        ->and(Storage::disk('local')->exists("imports/{$token}.csv"))->toBeTrue();

    importAs($this, '/preview', ['token' => $token])->assertOk()->assertExactJson(['data' => [
        'columns' => ['name', 'note'],
        'rows' => array_merge(
            [['Alice', 'first'], ['Bob', 'second, quoted']],
            array_map(fn (int $i): array => ["User{$i}", "row{$i}"], range(3, 20)),
        ),
    ]]);
});

it('previews without a header row on request', function () {
    $token = importAs($this, '', ['file' => csvFile("Alice,first\nBob,second")])->assertStatus(201)->json('data.token');

    importAs($this, '/preview', ['token' => $token, 'header' => false])->assertOk()->assertExactJson(['data' => [
        'columns' => ['', ''],
        'rows' => [['Alice', 'first'], ['Bob', 'second']],
    ]]);
});

it('rejects another user\'s token, a malformed token, and a missing file', function () {
    importAs($this, '', ['file' => csvFile("a\n1")], 'editor', 2)->assertStatus(201);
    $token = importAs($this, '', ['file' => csvFile("a\n1")])->assertStatus(201)->json('data.token');

    // Editor 1 cannot use editor 2's upload.
    importAs($this, '/preview', ['token' => preg_replace('/^1-/', '2-', $token)])->assertStatus(422)
        ->assertJsonValidationErrors('token');

    importAs($this, '/preview', ['token' => '../evil'])->assertStatus(422);
    importAs($this, '/preview', ['token' => '1-'.str_repeat('c', 40)])->assertStatus(422);
});

it('accepts CSV whatever name the server\'s libmagic gives it (application/csv on PHP 8.0)', function (string $contents) {
    $file = csvFile($contents);
    $sniffed = (new finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());

    expect(ImportController::CSV_MIME_TYPES)->toContain($sniffed);

    importAs($this, '', ['file' => $file])->assertStatus(201);
})->with([
    'one column' => ["a\n1"],
    'several columns' => ["a,b\n1,x\n2,y\n3,z\n"],
    'quoted' => ["name,note\nAlice,first\nBob,\"second, quoted\"\n"],
]);

it('rejects a real binary spreadsheet named .csv', function () {
    // An OLE2 (.xls) header: finfo calls it application/vnd.ms-excel or CDF, never CSV.
    $xls = tempnam(sys_get_temp_dir(), 'dbx-xls');
    file_put_contents($xls, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 504));

    importAs($this, '', ['file' => new UploadedFile($xls, 'upload.csv', 'text/csv', null, true)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

it('rejects uploads that are not plain text csv, or oversized', function () {
    // Binary content, whatever its name: the mime guesser says not text.
    $binary = tempnam(sys_get_temp_dir(), 'dbx-bin');
    file_put_contents($binary, str_repeat("\x89PNG\r\n\x1a\n\x00\x00", 100));

    importAs($this, '', ['file' => new UploadedFile($binary, 'upload.csv', 'application/octet-stream', null, true)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    config(['dbexplorer.limits.import.max_upload_kb' => 1]);

    importAs($this, '', ['file' => csvFile(str_repeat('a', 4096))])->assertStatus(422)->assertJsonValidationErrors('file');
});

it('rejects a dispatch that maps unknown columns', function () {
    // Column validation needs the database; only the request shape is
    // checked here (the Pgsql lane covers the rest).
    $token = importAs($this, '', ['file' => csvFile("a\n1")])->assertStatus(201)->json('data.token');

    importAs($this, '/run', ['token' => $token, 'table' => ['schema' => 'fixture', 'name' => 'users'], 'map' => []])
        ->assertStatus(422);

    importAs($this, '/run', ['token' => $token, 'table' => ['schema' => 'fixture', 'name' => 'users'], 'map' => ['a'], 'mode' => 'explosive'])
        ->assertStatus(422);
});
