<?php

declare(strict_types=1);

/*
 * Child process for RunsJobTest: a real queue worker.
 *
 * Boots the same Testbench app as PgsqlTestCase, points the queue at the
 * database (the jobs table the test created), resolves users through the
 * workbench model like createUsers() does, then works exactly one job.
 */

use Illuminate\Contracts\Console\Kernel;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;
use Workbench\App\Models\User;

require __DIR__.'/../../../vendor/autoload.php';

$app = (new class('work-one-job') extends PgsqlTestCase {})->createApplication();

$app['config']->set('queue.default', 'database');
$app['config']->set('auth.providers.users.model', User::class);

exit($app->make(Kernel::class)->call('queue:work', ['--once' => true]));
