<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

// M3.4: the import job and its dispatch, against the fixture Postgres.
// Testbench's sync queue runs the job inside the dispatch request, so the
// run has already finished by the time the 202 comes back.

beforeEach(function () {
    app()['env'] = 'local';

    $this->schema = $this->scratchSchema();
    createUsers(1);

    config(['dbexplorer.storage.disk' => 'local']);

    DB::connection('pgsql_admin')->statement("CREATE TABLE {$this->schema}.t (a int, b text)");
});

afterEach(function () {
    Storage::disk('local')->deleteDirectory('imports');
});

/**
 * Upload $csv as $role and return the token.
 */
function uploadImport(PgsqlTestCase $test, string $csv, string $role = 'editor'): string
{
    DbExplorer::roleUsing(fn (): string => $role);

    $path = tempnam(sys_get_temp_dir(), 'dbx-csv');
    file_put_contents($path, $csv);

    return $test->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->post('/dbexplorer/api/main/imports', ['file' => new UploadedFile($path, 'upload.csv', 'text/csv', null, true)])
        ->assertStatus(201)
        ->json('data.token');
}

/**
 * Dispatch the import of $token into the scratch table and return the run,
 * which the sync queue has already finished.
 *
 * @param  array<int, string|null>  $map
 */
function runImport(PgsqlTestCase $test, string $token, array $map, string $mode = 'transaction', array $body = []): Run
{
    DbExplorer::roleUsing(fn (): string => 'editor');

    $test->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/imports/run', [
            'token' => $token,
            'table' => ['schema' => $test->schema, 'name' => 't'],
            'map' => $map,
            'mode' => $mode,
        ] + $body)
        ->assertStatus(202);

    return Run::query()->orderByDesc('id')->firstOrFail();
}

it('imports every row in transaction mode, and rolls the whole file back on one bad row', function () {
    $token = uploadImport($this, "a,b\n1,one\n2,two\n");

    $run = runImport($this, $token, ['a', 'b']);

    expect($run->refresh()->status)->toBe(RunStatus::SUCCEEDED)
        ->and($run->meta['inserted'])->toBe(2)
        ->and(DB::connection('pgsql_super')->table("{$this->schema}.t")->count())->toBe(2);

    $bad = uploadImport($this, "a,b\n3,three\nnot-an-int,four\n");

    $run = runImport($this, $bad, ['a', 'b']);

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toContain('22P02')
        ->and(DB::connection('pgsql_super')->table("{$this->schema}.t")->count())->toBe(2); // the 3 rolled back
});

it('skips and reports the bad rows in skip mode, keeping the good ones', function () {
    $token = uploadImport($this, "a,b\n1,one\nnot-an-int,two\n3,three\nalso bad,four\n");

    $run = runImport($this, $token, ['a', 'b'], 'skip');

    $table = DB::connection('pgsql_super')->table("{$this->schema}.t");

    expect($run->refresh()->status)->toBe(RunStatus::SUCCEEDED)
        ->and($run->meta['inserted'])->toBe(2)
        ->and($run->meta['skipped'])->toBe(2)
        ->and(array_column($run->meta['errors'], 'row'))->toBe([2, 4])
        ->and($run->meta['errors'][0]['error'])->toContain('invalid input syntax for type integer: "not-an-int"')
        ->and($run->meta['errors'][1]['error'])->toContain('invalid input syntax for type integer: "also bad"')
        ->and($table->orderBy('a')->pluck('b')->all())->toBe(['one', 'three']);
});

it('keeps quoted commas, quotes and newlines intact', function () {
    $token = uploadImport($this, "a,b\n1,\"has, comma\"\n2,\"quote \"\"inside\"\"\"\n3,\"spans\n two lines\"\n");

    $run = runImport($this, $token, ['a', 'b'], 'skip');

    $b = DB::connection('pgsql_super')->table("{$this->schema}.t")->orderBy('a')->pluck('b');

    expect($run->refresh()->status)->toBe(RunStatus::SUCCEEDED)
        ->and($b->get(0))->toBe('has, comma')
        ->and($b->get(1))->toBe('quote "inside"')
        ->and($b->get(2))->toBe("spans\n two lines");
});

it('maps columns, skips csv columns mapped to null, and turns empty fields into nulls', function () {
    $token = uploadImport($this, "ignored,a,b\nnoise,1,one\nnoise,2,\n");

    $run = runImport($this, $token, [null, 'a', 'b'], 'skip', ['empty_as_null' => true]);

    $rows = DB::connection('pgsql_super')->table("{$this->schema}.t")->orderBy('a')->get(['a', 'b']);

    expect($run->refresh()->status)->toBe(RunStatus::SUCCEEDED)
        ->and($rows[0])->a->toBe(1)->b->toBe('one')
        ->and($rows[1]->a)->toBe(2)
        ->and($rows[1]->b)->toBeNull();
});

it('refuses to import at all for a viewer, whatever the dispatch body', function () {
    DbExplorer::roleUsing(fn (): string => 'viewer');

    $path = tempnam(sys_get_temp_dir(), 'dbx-csv');
    file_put_contents($path, "a,b\n1,one\n");

    $this->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->post('/dbexplorer/api/main/imports', ['file' => new UploadedFile($path, 'upload.csv', 'text/csv', null, true)])
        ->assertForbidden();

    expect(Run::query()->count())->toBe(0)
        ->and(DB::connection('pgsql_super')->table("{$this->schema}.t")->count())->toBe(0);
});

it('refuses a map naming a column the table does not have', function () {
    $token = uploadImport($this, "a,b\n1,one\n");

    DbExplorer::roleUsing(fn (): string => 'editor');

    $this->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/imports/run', [
            'token' => $token,
            'table' => ['schema' => $this->schema, 'name' => 't'],
            'map' => ['a', 'evil'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('map');

    expect(Run::query()->count())->toBe(0);
});
