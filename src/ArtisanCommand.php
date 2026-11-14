<?php

declare(strict_types=1);

namespace Happenv\QueuePrefork;

/**
 * What a forked child runs once it has left the pool's code: one Artisan
 * command in a freshly booted application.
 */
final readonly class ArtisanCommand
{
    /**
     * @param  list<string>  $arguments  Artisan arguments, command name first.
     * @param  resource|null  $readyChannel  Where a worker reports its first loop; null for the warm-up.
     */
    public function __construct(
        public string $basePath,
        public array $arguments,
        public int $poolPid,
        public mixed $readyChannel = null,
    ) {}

    public function isWorker(): bool
    {
        return $this->readyChannel !== null;
    }
}
