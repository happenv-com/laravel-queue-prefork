<?php

declare(strict_types=1);

use Happenv\QueuePrefork\Tests\Support\RunningPool;

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 */
function startPool(array $arguments, array $env = []): RunningPool
{
    return RunningPool::start($arguments, $env);
}
