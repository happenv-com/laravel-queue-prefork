<?php

declare(strict_types=1);

namespace Happenv\QueuePrefork;

use RuntimeException;

/**
 * The pool parent. It loads no application code: it owns opcache's shared
 * memory, fills it with one throwaway warm-up run, then forks the workers and
 * keeps their number up until it is told to stop.
 *
 * run() returns an exit code in the parent, and an ArtisanCommand in a forked
 * child, so that the child leaves every frame of this class before it boots
 * the application at the launcher's top level.
 */
final class Supervisor
{
    /**
     * This many workers dying before their first loop within CRASH_WINDOW_SECONDS
     * stop the pool with exit code 1, so the process manager restarts it with
     * its own backoff instead of this pool forking in a tight loop.
     */
    public const int CRASH_LIMIT = 5;

    public const float CRASH_WINDOW_SECONDS = 60.0;

    /**
     * Signals passed on to every worker. TERM, INT and QUIT also stop the pool.
     */
    private const array STOP_SIGNALS = [SIGTERM, SIGINT, SIGQUIT];

    private const array FORWARDED_SIGNALS = [SIGUSR2, SIGCONT];

    /**
     * A worker killed by one of these was stopped from outside, not crashed.
     */
    private const array EXTERNAL_STOP_SIGNALS = [SIGTERM, SIGINT, SIGQUIT, SIGHUP];

    /** @var array<int, true> Pids of running workers. */
    private array $workers = [];

    /** @var array<int, true> Workers that reached their first loop. */
    private array $ready = [];

    private ?int $warmupPid = null;

    private bool $stopping = false;

    private int $exitCode = 0;

    /** @var list<float> When workers died before their first loop. */
    private array $bootFailures = [];

    /** @var resource|null The pool's end of the ready channel. */
    private $readyReader;

    /** @var resource|null The workers' end, handed to every fork. */
    private $readyWriter;

    public function __construct(private readonly PoolOptions $options) {}

    public function run(): int | ArtisanCommand
    {
        $this->setTitle('queue-prefork: pool of ' . $this->options->workers . ' (php artisan queue:work ' . implode(' ', $this->options->workerArguments) . ')');
        $this->installSignalHandlers();
        $this->openReadyChannel();

        // A worker replaced after `queue:restart` must compile the new code, not
        // reuse what the pool cached before the deploy.
        ini_set('opcache.validate_timestamps', '1');
        ini_set('opcache.revalidate_freq', '0');

        $warmup = $this->warmUp();

        if ($warmup instanceof ArtisanCommand) {
            return $warmup;
        }

        while (! $this->stopping && count($this->workers) < $this->options->workers) {
            $child = $this->spawnWorker();

            if ($child instanceof ArtisanCommand) {
                return $child;
            }
        }

        if (! $this->stopping) {
            $this->log('started ' . count($this->workers) . ' workers: ' . implode(', ', array_keys($this->workers)));
        }

        return $this->superviseUntilStopped();
    }

    private function warmUp(): ?ArtisanCommand
    {
        if ($this->options->warmup === null) {
            return null;
        }

        $startedAt = microtime(true);
        $pool = (int) getmypid();
        $pid = $this->fork();

        if ($pid === 0) {
            return $this->becomeChild(new ArtisanCommand($this->options->basePath, $this->options->warmup, $pool));
        }

        $this->warmupPid = $pid;
        $status = 0;

        while (($reaped = pcntl_waitpid($pid, $status, WNOHANG)) === 0) {
            if (microtime(true) - $startedAt > $this->options->warmupTimeout) {
                $this->log(sprintf('warm-up still running after %ds; killing it', $this->options->warmupTimeout));
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);

                break;
            }

            usleep(10_000);
        }

        $this->warmupPid = null;

        if ($reaped === $pid && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
            $this->log(sprintf('warm-up took %.2fs%s', microtime(true) - $startedAt, $this->describeOpcache()));
        } elseif (! $this->stopping) {
            $this->log(sprintf('warm-up "%s" failed (%s); workers start on a partly empty opcache', implode(' ', $this->options->warmup), $this->describeStatus($status)));
        }

        return null;
    }

    private function spawnWorker(): ?ArtisanCommand
    {
        $pool = (int) getmypid();
        $pid = $this->fork();

        if ($pid === 0) {
            return $this->becomeChild(new ArtisanCommand(
                $this->options->basePath,
                ['queue:work', ...$this->options->workerArguments],
                $pool,
                $this->readyWriter,
            ));
        }

        $this->workers[$pid] = true;

        return null;
    }

    private function superviseUntilStopped(): int | ArtisanCommand
    {
        while ($this->workers !== []) {
            $status = 0;
            $pid = pcntl_wait($status);

            if ($pid === -1) {
                if (pcntl_get_last_error() === PCNTL_EINTR) {
                    continue;
                }

                // ECHILD: nothing left to wait for, whatever the bookkeeping says.
                break;
            }

            if (! isset($this->workers[$pid])) {
                // An orphan this process adopted as PID 1; reaping it is all it needs.
                continue;
            }

            $this->readReadyReports();
            $wasReady = isset($this->ready[$pid]);
            unset($this->workers[$pid], $this->ready[$pid]);

            if ($this->stopping) {
                continue;
            }

            $this->log(sprintf('worker %d exited (%s)%s; starting a new one', $pid, $this->describeStatus($status), $wasReady ? '' : ' before its first loop'));

            if (! $wasReady && $this->tripsCrashBreaker($status)) {
                $this->log(sprintf('%d workers died before their first loop within %.0fs; stopping the pool', self::CRASH_LIMIT, self::CRASH_WINDOW_SECONDS));
                $this->exitCode = 1;
                $this->stop(SIGTERM);

                continue;
            }

            $child = $this->spawnWorker();

            if ($child instanceof ArtisanCommand) {
                return $child;
            }
        }

        $this->log('stopped');

        return $this->exitCode;
    }

    private function tripsCrashBreaker(int $status): bool
    {
        if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
            return false;
        }

        if (pcntl_wifsignaled($status) && in_array(pcntl_wtermsig($status), self::EXTERNAL_STOP_SIGNALS, true)) {
            return false;
        }

        $now = microtime(true);
        $this->bootFailures[] = $now;
        $this->bootFailures = array_values(array_filter($this->bootFailures, static fn (float $at): bool => $at > $now - self::CRASH_WINDOW_SECONDS));

        return count($this->bootFailures) >= self::CRASH_LIMIT;
    }

    private function openReadyChannel(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new RuntimeException('Could not open the channel workers report their first loop on.');
        }

        [$this->readyReader, $this->readyWriter] = $pair;
        stream_set_blocking($this->readyReader, false);
    }

    private function readReadyReports(): void
    {
        if (! is_resource($this->readyReader)) {
            return;
        }

        while (($line = fgets($this->readyReader)) !== false) {
            $this->ready[(int) trim($line)] = true;
        }
    }

    private function installSignalHandlers(): void
    {
        pcntl_async_signals(true);

        // `false`: do not restart the interrupted wait, or the handler would
        // only run once a worker exits.
        foreach (self::STOP_SIGNALS as $signal) {
            pcntl_signal($signal, $this->stop(...), false);
        }

        foreach (self::FORWARDED_SIGNALS as $signal) {
            pcntl_signal($signal, $this->forward(...), false);
        }
    }

    private function stop(int $signal): void
    {
        if (! $this->stopping) {
            $this->log('received signal ' . $signal . '; waiting for ' . count($this->workers) . ' workers to finish their jobs');
        }

        $this->stopping = true;
        $this->forward($signal);
    }

    private function forward(int $signal): void
    {
        foreach ([...array_keys($this->workers), ...($this->warmupPid === null ? [] : [$this->warmupPid])] as $pid) {
            posix_kill($pid, $signal);
        }
    }

    private function fork(): int
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork() failed: ' . pcntl_strerror(pcntl_get_last_error()));
        }

        return $pid;
    }

    /**
     * Turns a fresh fork into a plain process again: default signal handling,
     * its own random seed, an empty realpath cache and its own title.
     */
    private function becomeChild(ArtisanCommand $command): ArtisanCommand
    {
        foreach ([...self::STOP_SIGNALS, ...self::FORWARDED_SIGNALS] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        pcntl_async_signals(false);

        if (is_resource($this->readyReader)) {
            fclose($this->readyReader);
        }

        if (! $command->isWorker() && is_resource($this->readyWriter)) {
            fclose($this->readyWriter);
        }

        mt_srand(random_int(0, PHP_INT_MAX));
        clearstatcache(true);
        memory_reset_peak_usage();

        $this->setTitle('queue-prefork: ' . ($command->isWorker() ? 'worker' : 'warm-up') . ' (php artisan ' . implode(' ', $command->arguments) . ')');

        return $command;
    }

    private function describeStatus(int $status): string
    {
        if (pcntl_wifsignaled($status)) {
            return 'killed by signal ' . pcntl_wtermsig($status);
        }

        return 'exit code ' . pcntl_wexitstatus($status);
    }

    private function describeOpcache(): string
    {
        $status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;

        if (! is_array($status)) {
            return '; opcache is off, so the workers share no compiled code';
        }

        $memory = $status['memory_usage'];
        $total = $memory['used_memory'] + $memory['free_memory'] + $memory['wasted_memory'];

        return sprintf(
            '; opcache holds %d scripts in %.0f of %.0f MB%s',
            $status['opcache_statistics']['num_cached_scripts'],
            $memory['used_memory'] / 1048576,
            $total / 1048576,
            $status['cache_full'] ? ' and is FULL: raise opcache.memory_consumption' : '',
        );
    }

    private function setTitle(string $title): void
    {
        // Not every platform lets a process rename itself; the title is cosmetic.
        @cli_set_process_title($title);
    }

    private function log(string $message): void
    {
        fwrite(STDERR, sprintf("[queue-prefork %s] %s\n", date('Y-m-d H:i:s'), $message));
    }
}
