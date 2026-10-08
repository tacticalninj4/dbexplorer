<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use TacticalNinj4\DbExplorer\Audit\AuditLog;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);

    Artisan::call('migrate');

    foreach (range(1, 5) as $i) {
        DbExplorer::audit(['action' => 'query', 'user_id' => $i, 'sql' => "select {$i}", 'meta' => ['n' => $i]]);
    }
});

/**
 * @return array{0: int, 1: string}
 */
function verifyAuditChain(): array
{
    $code = Artisan::call('dbx:audit:verify');

    return [$code, Artisan::output()];
}

it('S22 verifies a clean chain', function () {
    [$code, $output] = verifyAuditChain();

    expect($code)->toBe(0)
        ->and($output)->toContain('5 entries verified');
});

it('S22 links every entry to the previous hash', function () {
    $rows = DB::table('dbx_audit_log')->orderBy('id')->get();

    expect($rows[0]->prev_hash)->toBe(AuditLog::GENESIS);

    foreach ($rows->slice(1) as $i => $row) {
        expect($row->prev_hash)->toBe($rows[$i - 1]->hash);
    }
});

it('S22 detects a tampered field', function (string $column, $value) {
    DB::table('dbx_audit_log')->where('id', 3)->update([$column => $value]);

    [$code, $output] = verifyAuditChain();

    expect($code)->toBe(1)
        ->and($output)->toContain('entry #3');
})->with([
    'sql' => ['sql', 'select 42'],
    'action' => ['action', 'ddl'],
    'user_id' => ['user_id', '99'],
    'outcome' => ['outcome', 'error'],
    'meta' => ['meta', '{"n":42}'],
    'created_at' => ['created_at', '2000-01-01 00:00:00'],
    'null to value' => ['error', 'boom'],
    'prev_hash' => ['prev_hash', str_repeat('f', 64)],
    'hash' => ['hash', str_repeat('f', 64)],
]);

it('S22 detects a deleted middle entry', function () {
    DB::table('dbx_audit_log')->where('id', 3)->delete();

    [$code, $output] = verifyAuditChain();

    expect($code)->toBe(1)
        ->and($output)->toContain('entry #4');
});

it('S22 detects reordered entries', function () {
    $two = (array) DB::table('dbx_audit_log')->where('id', 2)->first();
    $three = (array) DB::table('dbx_audit_log')->where('id', 3)->first();
    unset($two['id'], $three['id']);

    // Swap contents through a temporary hash, since hash is unique.
    DB::table('dbx_audit_log')->where('id', 2)->update(['hash' => 'tmp']);
    DB::table('dbx_audit_log')->where('id', 3)->update($two);
    DB::table('dbx_audit_log')->where('id', 2)->update($three);

    [$code, $output] = verifyAuditChain();

    expect($code)->toBe(1)
        ->and($output)->toContain('entry #2');
});

it('S22 detects a wrong key', function () {
    config(['dbexplorer.audit.key' => 'not-the-key-that-wrote-the-chain']);

    [$code, $output] = verifyAuditChain();

    expect($code)->toBe(1)
        ->and($output)->toContain('entry #1');
});

it('S22 detects entries deleted from the end when dbx_audit_head was left alone', function () {
    DB::table('dbx_audit_log')->where('id', 5)->delete();

    [$code, $output] = verifyAuditChain();

    expect($code)->toBe(1)
        ->and($output)->toContain('after the newest entry');
});

it('GAP: S22 cannot detect deleting the newest entries when dbx_audit_head is rewritten too', function () {
    // An attacker with DB access truncates the tail and points the head at the new last entry.
    // Only the hashes forwarded to the log channel (S19) reveal this.
    DB::table('dbx_audit_log')->where('id', '>', 3)->delete();
    DB::table('dbx_audit_head')->update(['hash' => DB::table('dbx_audit_log')->where('id', 3)->value('hash')]);

    [$code] = verifyAuditChain();

    expect($code)->toBe(0);
});

it('S22 uses audit.key over APP_KEY and decodes base64 keys', function () {
    DB::table('dbx_audit_log')->delete();
    DB::table('dbx_audit_head')->update(['hash' => null]);

    config(['dbexplorer.audit.key' => 'base64:'.base64_encode('audit-secret')]);
    DbExplorer::audit(['action' => 'query']);

    config(['dbexplorer.audit.key' => 'audit-secret', 'app.key' => 'something-else']);
    expect(verifyAuditChain()[0])->toBe(0);

    config(['dbexplorer.audit.key' => null]);
    expect(verifyAuditChain()[0])->toBe(1);
});

it('S22 refuses to write without any key', function () {
    config(['dbexplorer.audit.key' => null, 'app.key' => null]);

    DbExplorer::audit(['action' => 'query']);
})->throws(RuntimeException::class, 'needs a key');

it('S22 verifies the chain after dbx:prune and writes a checkpoint', function () {
    $this->travelTo(now()->addDays(100));
    DbExplorer::audit(['action' => 'query', 'sql' => 'select 6']);

    expect(Artisan::call('dbx:prune'))->toBe(0)
        ->and(Artisan::output())->toContain('pruned 5 entries');

    $checkpoint = DB::table('dbx_audit_log')->where('action', AuditLog::PRUNED)->sole();
    $meta = json_decode($checkpoint->meta, true);

    expect(DB::table('dbx_audit_log')->orderBy('id')->pluck('id')->all())->toBe([6, 7])
        ->and($meta['through_id'])->toBe(5)
        ->and($meta['entries'])->toBe(5)
        ->and(DB::table('dbx_audit_log')->where('id', 6)->value('prev_hash'))->toBe($meta['anchor_hash'])
        ->and(verifyAuditChain()[0])->toBe(0);

    // A second prune anchors on its own checkpoint.
    $this->travelTo(now()->addDays(100));
    Artisan::call('dbx:prune');

    expect(DB::table('dbx_audit_log')->count())->toBe(1)
        ->and(verifyAuditChain()[0])->toBe(0);
});

it('S22 detects entries deleted beyond the prune checkpoint', function () {
    $this->travelTo(now()->addDays(100));
    DbExplorer::audit(['action' => 'query']);
    DbExplorer::audit(['action' => 'query']);
    Artisan::call('dbx:prune');

    DB::table('dbx_audit_log')->where('id', 6)->delete();

    [$code, $output] = verifyAuditChain();

    expect($code)->toBe(1)
        ->and($output)->toContain('entry #7');
});

it('S18 prunes nothing when no entry is past retention', function () {
    expect(Artisan::call('dbx:prune'))->toBe(0)
        ->and(DB::table('dbx_audit_log')->count())->toBe(5)
        ->and(DB::table('dbx_audit_log')->where('action', AuditLog::PRUNED)->exists())->toBeFalse();
});

it('S18 keeps every entry when retention is disabled', function () {
    config(['dbexplorer.audit.retention_days' => null]);
    $this->travelTo(now()->addYears(10));

    expect(Artisan::call('dbx:prune'))->toBe(0)
        ->and(DB::table('dbx_audit_log')->count())->toBe(5);
});
