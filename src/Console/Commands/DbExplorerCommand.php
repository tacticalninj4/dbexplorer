<?php

declare(strict_types=1);

namespace njrdevs\DbExplorer\Console\Commands;

use Illuminate\Console\Command;

class DbExplorerCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'dbexplorer:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package dbexplorer.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('DbExplorer placeholder command executed.');

        return self::SUCCESS;
    }
}
