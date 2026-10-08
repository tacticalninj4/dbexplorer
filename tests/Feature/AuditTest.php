<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\AuditEntry;

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);

    Artisan::call('migrate');
});

it('S17 writes an audit row with every field', function () {
    $entry = DbExplorer::audit([
        'action' => 'query',
        'user_id' => 7,
        'role' => 'viewer',
        'label' => 'main',
        'ip' => '203.0.113.9',
        'user_agent' => 'Pest',
        'sql' => 'select * from users where id = ?',
        'classification' => 'read',
        'duration_ms' => 12.6,
        'row_count' => 3,
        'meta' => ['object' => 'public.users'],
    ]);

    $row = AuditEntry::query()->sole();

    expect($entry->id)->toBe($row->id)
        ->and($entry->hash)->toBe($row->hash)
        ->and($row->action)->toBe('query')
        ->and($row->user_id)->toBe('7')
        ->and($row->role)->toBe('viewer')
        ->and($row->label)->toBe('main')
        ->and($row->ip)->toBe('203.0.113.9')
        ->and($row->user_agent)->toBe('Pest')
        ->and($row->sql)->toBe('select * from users where id = ?')
        ->and($row->classification)->toBe('read')
        ->and($row->duration_ms)->toBe(13)
        ->and($row->row_count)->toBe(3)
        ->and($row->outcome)->toBe('success')
        ->and($row->error)->toBeNull()
        ->and($row->meta)->toBe(['object' => 'public.users'])
        ->and($row->hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('S17 rejects an entry without an action or with unknown keys', function (array $entry) {
    DbExplorer::audit($entry);
})->throws(InvalidArgumentException::class)->with([
    'no action' => [['sql' => 'select 1']],
    'unknown key' => [['action' => 'query', 'password' => 'secret']],
    'non-array meta' => [['action' => 'query', 'meta' => 'x']],
]);

it('S18 throws when an audit entry is updated through the model', function () {
    $entry = DbExplorer::audit(['action' => 'query', 'sql' => 'select 1']);

    expect(fn () => $entry->forceFill(['sql' => 'select 2'])->save())->toThrow(LogicException::class)
        ->and(AuditEntry::query()->sole()->sql)->toBe('select 1');
});

it('S18 throws when an audit entry is deleted through the model', function () {
    $entry = DbExplorer::audit(['action' => 'query']);

    expect(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(AuditEntry::query()->count())->toBe(1);
});

it('S18 throws when an audit entry is created through the model, bypassing the chain', function () {
    expect(fn () => (new AuditEntry)->forceFill(['action' => 'query'])->save())->toThrow(LogicException::class)
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('S19 forwards each entry with its hash to the configured log channel', function () {
    config([
        'logging.channels.dbx_audit' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'dbexplorer.audit.log_channel' => 'dbx_audit',
    ]);

    $entry = DbExplorer::audit(['action' => 'query', 'sql' => 'select 1']);

    /** @var TestHandler $handler */
    $handler = Log::channel('dbx_audit')->getLogger()->getHandlers()[0];
    $records = $handler->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]['message'])->toBe('dbexplorer.audit')
        ->and($records[0]['context']['id'])->toBe($entry->id)
        ->and($records[0]['context']['sql'])->toBe('select 1')
        ->and($records[0]['context']['prev_hash'])->toBe($entry->prev_hash)
        ->and($records[0]['context']['hash'])->toBe($entry->hash);
});

it('S19 does not forward entries when no log channel is configured', function () {
    config(['logging.channels.dbx_audit' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);

    DbExplorer::audit(['action' => 'query']);

    /** @var TestHandler $handler */
    $handler = Log::channel('dbx_audit')->getLogger()->getHandlers()[0];

    expect($handler->getRecords())->toBe([]);
});

it('S17 honours a separate storage connection', function () {
    config([
        'database.connections.dbx_store' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'dbexplorer.storage.connection' => 'dbx_store',
    ]);

    Artisan::call('migrate:fresh');

    DbExplorer::audit(['action' => 'query']);

    expect(Schema::connection('dbx_store')->hasTable('dbx_audit_log'))->toBeTrue()
        ->and(Schema::connection('dbx_store')->hasTable('dbx_runs'))->toBeTrue()
        ->and(Schema::hasTable('dbx_audit_log'))->toBeFalse()
        ->and(DB::connection('dbx_store')->table('dbx_audit_log')->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(1);
});

it('creates every metadata table', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['dbx_audit_head', 'dbx_audit_log', 'dbx_runs', 'dbx_query_history', 'dbx_saved_queries', 'dbx_schedules']);

it('rolls the metadata tables back', function () {
    Artisan::call('migrate:rollback');

    expect(Schema::hasTable('dbx_audit_log'))->toBeFalse()
        ->and(Schema::hasTable('dbx_schedules'))->toBeFalse();
});

it('registers the audit commands', function (string $command) {
    expect(Artisan::all())->toHaveKey($command);
})->with(['dbx:audit:verify', 'dbx:prune']);

/**
 * A {label} route behind the package's middleware stack that writes one audit entry.
 *
 * @param  array<string, mixed>  $entry
 */
function auditProbeRoute(array $entry): void
{
    app()['env'] = 'local';

    $route = Route::getRoutes()->getByName('dbexplorer.me');
    expect($route)->not->toBeNull();

    Route::middleware($route->gatherMiddleware())
        ->get('dbexplorer/api/{label}/audit-probe', fn (): JsonResponse => new JsonResponse([
            'id' => DbExplorer::audit($entry)->id,
        ]));
}

it('S17 fills user, IP, user agent, label and role from the current request', function () {
    auditProbeRoute(['action' => 'query']);

    $this->actingAs(makeUser(42))
        ->withHeaders(['User-Agent' => 'AuditBrowser/1.0'])
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->getJson('/dbexplorer/api/main/audit-probe')
        ->assertOk();

    $row = AuditEntry::query()->sole();

    expect($row->user_id)->toBe('42')
        ->and($row->ip)->toBe('198.51.100.7')
        ->and($row->user_agent)->toBe('AuditBrowser/1.0')
        ->and($row->label)->toBe('main')
        ->and($row->role)->toBe('viewer')
        ->and(Artisan::call('dbx:audit:verify'))->toBe(0);
});

it('S17 lets explicit values, including null, win over the request context', function () {
    auditProbeRoute(['action' => 'query', 'user_id' => 'system', 'ip' => null, 'label' => 'other']);

    $this->actingAs(makeUser(42))
        ->withHeaders(['User-Agent' => 'AuditBrowser/1.0'])
        ->getJson('/dbexplorer/api/main/audit-probe')
        ->assertOk();

    $row = AuditEntry::query()->sole();

    expect($row->user_id)->toBe('system')
        ->and($row->ip)->toBeNull()
        ->and($row->label)->toBe('other')
        ->and($row->role)->toBe('viewer')
        ->and($row->user_agent)->toBe('AuditBrowser/1.0');
});

it('S17 leaves request fields empty in console and queue context', function () {
    $this->actingAs(makeUser(42));

    DbExplorer::audit(['action' => 'query']);

    $row = AuditEntry::query()->sole();

    expect($row->user_id)->toBeNull()
        ->and($row->ip)->toBeNull()
        ->and($row->user_agent)->toBeNull()
        ->and($row->label)->toBeNull()
        ->and($row->role)->toBeNull();
});
