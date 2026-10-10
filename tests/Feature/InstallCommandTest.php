<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// Parallel workers share the Testbench skeleton's config directory, so point
// config_path() at a private directory instead of publishing into it.
beforeEach(function () {
    $this->originalBasePath = app()->basePath();
    $this->installBasePath = sys_get_temp_dir().'/dbx-install-'.bin2hex(random_bytes(6));

    mkdir($this->installBasePath.'/config', 0777, true);
    app()->setBasePath($this->installBasePath);
});

afterEach(function () {
    app()->setBasePath($this->originalBasePath);

    @unlink($this->installBasePath.'/config/dbexplorer.php');
    @rmdir($this->installBasePath.'/config');
    @rmdir($this->installBasePath);
});

function runDbxInstall(array $options = []): string
{
    expect(Artisan::call('dbx:install', $options))->toBe(0);

    return Artisan::output();
}

function packageConfigSource(): string
{
    return (string) file_get_contents(__DIR__.'/../../config/dbexplorer.php');
}

function customizedConfigSource(): string
{
    return str_replace('<?php', "<?php\n\n// customized by the host app", packageConfigSource());
}

it('publishes the config file', function () {
    $output = runDbxInstall();

    expect(config_path('dbexplorer.php'))->toBeFile()
        ->and(file_get_contents(config_path('dbexplorer.php')))->toBe(packageConfigSource())
        ->and($output)->toContain('Published config/dbexplorer.php');
});

it('leaves an existing config file alone without --force', function () {
    file_put_contents(config_path('dbexplorer.php'), customizedConfigSource());

    $output = runDbxInstall();

    expect(file_get_contents(config_path('dbexplorer.php')))->toBe(customizedConfigSource())
        ->and($output)->toContain('already exists')
        ->and($output)->toContain('--force');
});

it('overwrites an existing config file with --force', function () {
    file_put_contents(config_path('dbexplorer.php'), customizedConfigSource());

    $output = runDbxInstall(['--force' => true]);

    expect(file_get_contents(config_path('dbexplorer.php')))->toBe(packageConfigSource())
        ->and($output)->toContain('Overwrote config/dbexplorer.php');
});

it('prints the gate and role resolver stubs', function () {
    $output = runDbxInstall();

    expect($output)
        ->toContain("Gate::define('viewDbExplorer', function (\$user) {")
        ->toContain('DbExplorer::roleUsing(function ($user, string $label) {')
        ->toContain('DBEXPLORER_ENABLED=true')
        ->toContain('DBEXPLORER_STORAGE_CONNECTION')
        ->toContain('php artisan migrate');
});

it('asks the host for the scheduler cron, an audit check and a queue worker, but not for dbx:prune', function () {
    $output = runDbxInstall();

    // The provider schedules dbx:prune and dbx:schedule:tick itself; a host
    // entry for either would run it twice.
    expect($output)
        ->toContain('php artisan schedule:run')
        ->toContain("Schedule::command('dbx:audit:verify')->daily();")
        ->toContain('php artisan queue:work')
        ->not->toContain("command('dbx:prune')")
        ->not->toContain("command('dbx:schedule:tick')");
});

it('prints the PostgreSQL role recipe documented in docs/security.md', function () {
    $recipe = trim((string) file_get_contents(__DIR__.'/../../resources/sql/postgres-roles.sql'));
    $output = runDbxInstall();

    expect($output)
        ->toContain('CREATE ROLE dbx_viewer LOGIN')
        ->toContain('GRANT SELECT ON ALL TABLES IN SCHEMA public TO dbx_viewer;')
        ->and(file_get_contents(__DIR__.'/../../docs/security.md'))->toContain($recipe);
});

it('prints a freshly generated audit key without saving it', function () {
    config(['dbexplorer.audit.key' => null]);

    $first = runDbxInstall();
    $second = runDbxInstall();

    preg_match('/DBEXPLORER_AUDIT_KEY=(base64:\S+)/', $first, $a);
    preg_match('/DBEXPLORER_AUDIT_KEY=(base64:\S+)/', $second, $b);

    expect($a[1] ?? null)->toBeString()
        ->and(strlen((string) base64_decode(substr($a[1], 7), true)))->toBe(32)
        ->and($b[1] ?? null)->not->toBe($a[1])
        ->and(config('dbexplorer.audit.key'))->toBeNull();
});

it('does not suggest a new audit key when one is configured', function () {
    config(['dbexplorer.audit.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);

    $output = runDbxInstall();

    expect($output)->toContain('DBEXPLORER_AUDIT_KEY is already set')
        ->and($output)->not->toMatch('/DBEXPLORER_AUDIT_KEY=base64:/');
});
