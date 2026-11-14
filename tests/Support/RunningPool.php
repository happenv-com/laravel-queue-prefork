<?php

declare(strict_types=1);

namespace Happenv\QueuePrefork\Tests\Support;

use RuntimeException;

/**
 * A real `bin/queue-prefork` process running the fixture application, with
 * what its workers recorded.
 */
final class RunningPool
{
    private ?int $exitCode = null;

    /**
     * @param  resource  $process
     */
    private function __construct(private $process, public readonly int $pid, private readonly string $log, private readonly string $stderr) {}

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    public static function start(array $arguments, array $env = []): self
    {
        $directory = sys_get_temp_dir() . '/queue-prefork-test-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $log = $directory . '/events.log';
        $stderr = $directory . '/stderr.log';
        touch($log);

        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/queue-prefork', '--base-path=' . dirname(__DIR__) . '/Fixtures/app', ...$arguments];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderr, 'w']], $pipes, null, [...getenv(), 'FIXTURE_LOG' => $log, ...$env]);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the pool.');
        }

        return new self($process, (int) proc_get_status($process)['pid'], $log, $stderr);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(?string $event = null): array
    {
        $lines = array_filter(explode(PHP_EOL, (string) file_get_contents($this->log)));
        $events = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_values($lines));

        return array_values(array_filter($events, static fn (array $recorded): bool => $event === null || $recorded['event'] === $event));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function workerRuns(): array
    {
        return array_values(array_filter($this->events('run'), static fn (array $run): bool => $run['command'] === 'queue:work'));
    }

    public function stderr(): string
    {
        return (string) file_get_contents($this->stderr);
    }

    public function waitUntil(callable $condition, float $timeout = 10.0): bool
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if ($condition($this)) {
                return true;
            }

            usleep(20_000);
        }

        return (bool) $condition($this);
    }

    public function signal(int $signal): void
    {
        posix_kill($this->pid, $signal);
    }

    public function isRunning(): bool
    {
        if ($this->exitCode !== null) {
            return false;
        }

        $status = proc_get_status($this->process);

        if ($status['running']) {
            return true;
        }

        $this->exitCode = $status['exitcode'];

        return false;
    }

    public function waitForExit(float $timeout = 10.0): ?int
    {
        $this->waitUntil(fn (): bool => ! $this->isRunning(), $timeout);

        return $this->exitCode;
    }

    public function isAlive(int $pid): bool
    {
        // A zombie still answers signal 0; only a reaped or vanished process is gone.
        if (is_dir('/proc/self')) {
            $state = is_readable("/proc/{$pid}/stat") ? (string) file_get_contents("/proc/{$pid}/stat") : '';

            return $state !== '' && ! str_contains(explode(')', $state, 2)[1] ?? '', ' Z ');
        }

        return posix_kill($pid, 0);
    }

    public function __destruct()
    {
        foreach ($this->workerRuns() as $run) {
            if (is_int($run['pid'])) {
                posix_kill($run['pid'], SIGKILL);
            }
        }

        if ($this->isRunning()) {
            posix_kill($this->pid, SIGKILL);
        }

        proc_close($this->process);
    }
}
