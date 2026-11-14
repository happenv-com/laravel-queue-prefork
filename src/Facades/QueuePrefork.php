<?php

declare(strict_types=1);

namespace Happenv\QueuePrefork\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Happenv\QueuePrefork\QueuePrefork
 */
class QueuePrefork extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Happenv\QueuePrefork\QueuePrefork::class;
    }
}
