<?php

declare(strict_types=1);

/*
 * Child process for ExportJobTest's memory check: runs one export job in a
 * fresh PHP process, so its peak memory is the export's own, not whatever
 * the test process retained from earlier tests.
 *
 * Usage: php run-export-job.php <run-id>
 *
 * Writes "DBX-PEAK-KB: <kilobytes>" to stdout on success.
 */

use TacticalNinj4\DbExplorer\Jobs\Export;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;
use Workbench\App\Models\User;

require __DIR__.'/../../../vendor/autoload.php';

[, $runId] = $argv;

$app = (new class('run-export-job') extends PgsqlTestCase {})->createApplication();

$app['config']->set('queue.default', 'database');
$app['config']->set('auth.providers.users.model', User::class);

app()->call([new Export((int) $runId), 'handle']);

echo 'DBX-PEAK-KB: '.(int) (memory_get_peak_usage(true) / 1024)."\n";
