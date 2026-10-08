<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Console;

use Illuminate\Console\Command;
use TacticalNinj4\DbExplorer\Audit\AuditLog;

class AuditVerifyCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dbx:audit:verify';

    /**
     * @var string
     */
    protected $description = 'Verify the DbExplorer audit log hash chain';

    public function handle(AuditLog $audit): int
    {
        $result = $audit->verify();

        if ($result['reason'] === null) {
            $this->info("Audit chain intact: {$result['checked']} entries verified.");

            return self::SUCCESS;
        }

        $where = $result['broken_id'] === null ? 'after the newest entry' : "at entry #{$result['broken_id']}";

        $this->error("Audit chain broken {$where}: {$result['reason']}.");

        return self::FAILURE;
    }
}
