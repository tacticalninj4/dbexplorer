<?php

declare(strict_types=1);

/*
 * Child process for RunsJobTest: a real queue worker.
 *
 * Boots the same Testbench app as PgsqlTestCase, points the queue at the
 * database (the jobs table the test created), resolves users through the
 * workbench model like createUsers() does, then works exactly one job.
 * DBX_TEST_ROLE, when set, is the role every user resolves to (viewer by
 * default), for runs started as an editor or admin. DBX_TEST_PG_DUMP, when
 * set, is the pg_dump a backup runs.
 */

use Illuminate\Contracts\Console\Kernel;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;
use Workbench\App\Models\User;

require __DIR__.'/../../../vendor/autoload.php';

$app = (new class('work-one-job') extends PgsqlTestCase {})->createApplication();

$app['config']->set('queue.default', 'database');
$app['config']->set('auth.providers.users.model', User::class);

$role = getenv('DBX_TEST_ROLE');

if (is_string($role) && $role !== '') {
    DbExplorer::roleUsing(fn (): string => $role);
}

$pgDump = getenv('DBX_TEST_PG_DUMP');

if (is_string($pgDump) && $pgDump !== '') {
    $app['config']->set('dbexplorer.backup.pg_dump_path', $pgDump);
}

exit($app->make(Kernel::class)->call('queue:work', ['--once' => true]));
