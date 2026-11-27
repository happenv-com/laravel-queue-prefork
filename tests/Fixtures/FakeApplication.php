<?php

declare(strict_types=1);

namespace Happenv\QueuePrefork\Tests\Fixtures;

use Symfony\Component\Console\Input\ArgvInput;

/**
 * Stands in for a Laravel application in the pool tests. It records every
 * boot, command and signal as one JSON line, and its "queue:work" loops like
 * Laravel's worker: it fires the looping listeners and stops on SIGTERM.
 */
final class FakeApplication
{
    /** @var list<callable> */
    private array $loopingListeners = [];

    private bool $shouldQuit = false;

    public function __construct(private readonly string $log, private readonly string $mode) {}

    public function make(): self
    {
        return $this;
    }

    public function listen(string $event, callable $listener): void
    {
        $this->loopingListeners[] = $listener;
    }

    public function handleCommand(ArgvInput $input): int
    {
        $command = (string) $input->getFirstArgument();
        $this->record('run', ['command' => $command, 'argv' => $_SERVER['argv'], 'listeners' => count($this->loopingListeners)]);

        if ($command !== 'queue:work') {
            if ($this->mode === 'warmup-hangs') {
                sleep(120);
            }

            return $this->mode === 'warmup-fails' ? 1 : 0;
        }

        if ($this->mode === 'crash') {
            return 3;
        }

        if ($this->mode === 'never-ready') {
            // Still booting: no loop yet, so no listener has run.
            while (true) {
                usleep(20_000);
            }
        }

        if ($this->mode === 'die-in-first-job') {
            // Report the first loop, as Laravel's worker does, then die mid-job.
            foreach ($this->loopingListeners as $listener) {
                $listener();
            }

            return 3;
        }

        return $this->work();
    }

    private function work(): int
    {
        if ($this->mode === 'slow-boot') {
            // Booting: Laravel's worker has not installed its signal handlers yet.
            usleep(500_000);
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->shouldQuit = true;
        });

        foreach ([SIGUSR2 => 'USR2', SIGCONT => 'CONT'] as $signal => $name) {
            pcntl_signal($signal, function () use ($name): void {
                $this->record('signal', ['signal' => $name]);
            });
        }

        if ($this->mode === 'chatty') {
            // Laravel's console writes a job line in pieces; so does this worker.
            for ($line = 1; $line <= 200; $line++) {
                fwrite(STDOUT, '  ' . getmypid() . " job {$line}");
                usleep(100);
                fwrite(STDOUT, ' ..........');
                usleep(100);
                fwrite(STDOUT, " DONE\n");
            }

            $this->record('chatted');
        }

        $startedAt = microtime(true);

        while (! $this->shouldQuit) {
            foreach ($this->loopingListeners as $listener) {
                $listener();
            }

            if ($this->mode === 'exit-after-1s' && microtime(true) - $startedAt > 1) {
                $this->record('finished');

                return 0;
            }

            usleep(20_000);
        }

        $this->record('stopped');

        return 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(string $event, array $data = []): void
    {
        $line = json_encode(['event' => $event, 'pid' => getmypid(), 'ppid' => posix_getppid(), ...$data], JSON_THROW_ON_ERROR);

        file_put_contents($this->log, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
