<?php

declare(strict_types=1);

namespace Happenv\QueuePrefork\Commands;

use Illuminate\Console\Command;

class QueuePreforkCommand extends Command
{
    public $signature = 'laravel-queue-prefork';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
