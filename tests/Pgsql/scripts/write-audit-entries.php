<?php

declare(strict_types=1);

/*
 * Child process for AuditChainConcurrencyTest.
 *
 * Usage: php write-audit-entries.php <writer> <count> <start-at-unix-time>
 *
 * Boots the same Testbench app as PgsqlTestCase, waits until <start-at> so that
 * sibling processes write at the same time, then appends <count> audit entries.
 */

use TacticalNinj4\DbExplorer\Audit\AuditLog;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

require __DIR__.'/../../../vendor/autoload.php';

[, $writer, $count, $startAt] = $argv;

$app = (new class('write-audit-entries') extends PgsqlTestCase {})->createApplication();
$audit = $app->make(AuditLog::class);

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

for ($i = 1; $i <= (int) $count; $i++) {
    $audit->record(['action' => 'query', 'label' => $writer, 'sql' => "select {$i}"]);
}
