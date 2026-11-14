<?php

declare(strict_types=1);

namespace Happenv\QueuePrefork;

use InvalidArgumentException;

/**
 * The command line of `queue-prefork`: the pool's own options, then everything
 * else, passed unchanged to every worker's `queue:work`.
 */
final readonly class PoolOptions
{
    public const array DEFAULT_WARMUP = ['list', '--quiet'];

    public const int DEFAULT_WARMUP_TIMEOUT = 60;

    /**
     * `queue:work` options that end a worker on purpose after a job or an empty
     * queue. A pool would restart that worker at once, over and over.
     */
    private const array REFUSED_WORKER_OPTIONS = ['--once', '--stop-when-empty'];

    /**
     * @param  list<string>|null  $warmup  Artisan arguments of the warm-up run, or null for none.
     * @param  list<string>  $workerArguments  Arguments after `queue:work`.
     */
    public function __construct(
        public int $workers,
        public string $basePath,
        public ?array $warmup,
        public array $workerArguments,
        public int $warmupTimeout = self::DEFAULT_WARMUP_TIMEOUT,
    ) {
        if ($workers < 1) {
            throw new InvalidArgumentException('--workers must be at least 1.');
        }

        foreach ($workerArguments as $argument) {
            $name = explode('=', $argument, 2)[0];

            if (in_array($name, self::REFUSED_WORKER_OPTIONS, true)) {
                throw new InvalidArgumentException("{$name} ends a worker on purpose, and the pool would restart it at once. Run plain queue:work for that.");
            }
        }
    }

    /**
     * @param  list<string>  $argv  The script's $argv, script name first.
     */
    public static function fromArgv(array $argv, string $workingDirectory): self
    {
        $workers = 1;
        $basePath = $workingDirectory;
        $warmup = self::DEFAULT_WARMUP;
        $warmupTimeout = self::DEFAULT_WARMUP_TIMEOUT;
        $workerArguments = [];
        $passThrough = false;

        foreach (array_slice($argv, 1) as $argument) {
            if ($passThrough) {
                $workerArguments[] = $argument;

                continue;
            }

            if ($argument === '--') {
                $passThrough = true;

                continue;
            }

            if (str_starts_with($argument, '--workers=')) {
                $value = substr($argument, strlen('--workers='));

                if (! ctype_digit($value)) {
                    throw new InvalidArgumentException("--workers must be a whole number, got '{$value}'.");
                }

                $workers = (int) $value;
            } elseif (str_starts_with($argument, '--base-path=')) {
                $basePath = substr($argument, strlen('--base-path='));
            } elseif (str_starts_with($argument, '--warmup=')) {
                $command = trim(substr($argument, strlen('--warmup=')));

                if ($command === '') {
                    throw new InvalidArgumentException('--warmup needs an Artisan command; use --no-warmup to skip it.');
                }

                $warmup = array_values(array_filter(explode(' ', $command), static fn (string $part): bool => $part !== ''));
            } elseif (str_starts_with($argument, '--warmup-timeout=')) {
                $value = substr($argument, strlen('--warmup-timeout='));

                if (! ctype_digit($value) || (int) $value < 1) {
                    throw new InvalidArgumentException("--warmup-timeout must be a whole number of seconds, got '{$value}'.");
                }

                $warmupTimeout = (int) $value;
            } elseif ($argument === '--no-warmup') {
                $warmup = null;
            } else {
                $workerArguments[] = $argument;
            }
        }

        if (! str_starts_with($basePath, '/')) {
            $basePath = $workingDirectory . '/' . $basePath;
        }

        return new self($workers, rtrim($basePath, '/'), $warmup, $workerArguments, $warmupTimeout);
    }

    public static function usage(): string
    {
        return <<<'TXT'
            Usage: queue-prefork [--workers=N] [--base-path=DIR] [--warmup="COMMAND" | --no-warmup]
                                 [--warmup-timeout=SECONDS] [--] [queue:work arguments]

            Runs N `php artisan queue:work` workers as forks of one parent that never loads
            your application, so the whole pool shares a single copy of the compiled code.

              --workers=N        Workers in the pool (default: 1).
              --base-path=DIR    The Laravel application (default: the current directory).
              --warmup="CMD"     Artisan command that fills opcache before the workers start
                                 (default: "list --quiet"). It must not change anything.
              --warmup-timeout=S Kill a warm-up still running after S seconds (default: 60).
              --no-warmup        Start the workers on an empty opcache.

            Every other argument goes to each worker's queue:work, for example:

              vendor/bin/queue-prefork --workers=8 redis --queue=high,default --tries=3 --max-time=3600

            TXT;
    }
}
