<?php

declare(strict_types=1);

use Happenv\QueuePrefork\Supervisor;
use Happenv\QueuePrefork\Tests\Support\RunningPool;

covers(Supervisor::class);

it('warms up once, then boots every worker in its own fork and never boots the app itself', function (): void {
    $pool = startPool(['--workers=3', 'redis', '--queue=high,default', '--tries=3']);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === 3))->toBeTrue();

    $runs = $pool->events('run');
    $workers = $pool->workerRuns();

    expect($runs[0]['command'])->toBe('list')
        ->and($runs[0]['argv'])->toBe(['artisan', 'list', '--quiet'])
        ->and(array_column($runs, 'pid'))->not->toContain($pool->pid)
        ->and(array_unique(array_column($workers, 'pid')))->toHaveCount(3)
        ->and(array_unique(array_column($workers, 'ppid')))->toBe([$pool->pid])
        ->and(array_unique(array_map(json_encode(...), array_column($workers, 'argv'))))
        ->toBe([json_encode(['artisan', 'queue:work', 'redis', '--queue=high,default', '--tries=3'])]);
});

it('skips the warm-up with --no-warmup', function (): void {
    $pool = startPool(['--workers=1', '--no-warmup']);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === 1))->toBeTrue()
        ->and(array_column($pool->events('run'), 'command'))->toBe(['queue:work']);
});

it('starts the workers when the warm-up fails', function (): void {
    $pool = startPool(['--workers=2'], ['FIXTURE_MODE' => 'warmup-fails']);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === 2))->toBeTrue()
        ->and($pool->stderr())->toContain('warm-up "list --quiet" failed (exit code 1)');
});

it('replaces a worker that exits, keeping the pool at its size', function (): void {
    $pool = startPool(['--workers=2'], ['FIXTURE_MODE' => 'exit-after-1s']);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) >= 4, 8.0))->toBeTrue()
        ->and($pool->isRunning())->toBeTrue()
        ->and($pool->stderr())->not->toContain('stopping the pool');
});

it('stops on SIGTERM only after every worker has finished, with exit code 0', function (): void {
    $pool = startPool(['--workers=3']);
    $pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === 3);

    $pool->signal(SIGTERM);

    expect($pool->waitForExit())->toBe(0)
        ->and(array_column($pool->events('stopped'), 'pid'))->toEqualCanonicalizing(array_column($pool->workerRuns(), 'pid'))
        ->and(array_filter(array_column($pool->workerRuns(), 'pid'), $pool->isAlive(...)))->toBe([]);
});

it('stops cleanly when the signal also reaches the workers directly, as a process-group stop does', function (): void {
    $pool = startPool(['--workers=2']);
    $pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === 2);

    foreach ($pool->workerRuns() as $run) {
        posix_kill($run['pid'], SIGTERM);
    }

    $pool->signal(SIGTERM);

    expect($pool->waitForExit())->toBe(0)
        ->and($pool->events('stopped'))->toHaveCount(2);
});

it('keeps each line of the workers\' shared stdout whole', function (): void {
    $pool = startPool(['--workers=4', '--no-warmup'], ['FIXTURE_MODE' => 'chatty']);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->events('chatted')) === 4))->toBeTrue();

    $lines = explode("\n", rtrim($pool->stdout(), "\n"));

    expect($lines)->toHaveCount(800)
        ->and(array_filter($lines, static fn (string $line): bool => preg_match('/^  \d+ job \d+ \.{10} DONE$/', $line) !== 1))->toBe([]);
});

it('passes USR2 and CONT on to every worker', function (): void {
    $pool = startPool(['--workers=2']);
    $pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === 2);

    $pool->signal(SIGUSR2);
    $pool->waitUntil(fn (RunningPool $pool): bool => count($pool->events('signal')) === 2);
    $pool->signal(SIGCONT);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->events('signal')) === 4))->toBeTrue()
        ->and(array_count_values(array_column($pool->events('signal'), 'signal')))->toBe(['USR2' => 2, 'CONT' => 2]);
});

it('holds a pause sent while a worker boots and applies it at the worker\'s first loop', function (): void {
    $pool = startPool(['--workers=1', '--no-warmup'], ['FIXTURE_MODE' => 'slow-boot']);
    $pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === 1);

    $pool->signal(SIGUSR2);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->events('signal')) === 1))->toBeTrue()
        ->and($pool->events('signal')[0]['signal'])->toBe('USR2')
        ->and($pool->workerRuns())->toHaveCount(1)
        ->and($pool->stderr())->not->toContain('exited');
});

it('starts a replacement worker paused while the pool is paused, and resumes it on CONT', function (): void {
    $pool = startPool(['--workers=1', '--no-warmup'], ['FIXTURE_MODE' => 'exit-after-1s']);
    $pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === 1);

    $pool->signal(SIGUSR2);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) >= 2))->toBeTrue();

    $replacement = $pool->workerRuns()[1]['pid'];

    expect($pool->waitUntil(fn (RunningPool $pool): bool => in_array(['USR2', $replacement], array_map(fn (array $signal): array => [$signal['signal'], $signal['pid']], $pool->events('signal')), true)))->toBeTrue();

    $pool->signal(SIGCONT);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count(array_filter($pool->events('signal'), fn (array $signal): bool => $signal['signal'] === 'CONT')) >= 1))->toBeTrue();
});

it('never sends a pause to the warm-up, which has no handler for it', function (): void {
    $pool = startPool(['--workers=1', '--warmup-timeout=5'], ['FIXTURE_MODE' => 'warmup-hangs']);
    usleep(300_000);

    $pool->signal(SIGUSR2);
    usleep(300_000);

    expect($pool->stderr())->not->toContain('warm-up "list --quiet" failed (killed by signal ' . SIGUSR2 . ')');
});

it('stops with exit code 1 when workers keep dying before their first loop', function (): void {
    $pool = startPool(['--workers=2'], ['FIXTURE_MODE' => 'crash']);

    expect($pool->waitForExit())->toBe(1)
        ->and(count($pool->workerRuns()))->toBeGreaterThanOrEqual(Supervisor::CRASH_LIMIT)
        ->and($pool->stderr())->toContain('died before their first loop');
});

it('keeps replacing workers that die in a job after they booted', function (): void {
    $pool = startPool(['--workers=1'], ['FIXTURE_MODE' => 'die-in-first-job']);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) > Supervisor::CRASH_LIMIT + 1))->toBeTrue()
        ->and($pool->isRunning())->toBeTrue();
});

it('does not count a worker stopped by a signal before its first loop as a crash', function (): void {
    $pool = startPool(['--workers=1', '--no-warmup'], ['FIXTURE_MODE' => 'never-ready']);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === 1))->toBeTrue();

    for ($round = 1; $round <= Supervisor::CRASH_LIMIT + 1; $round++) {
        $runs = $pool->workerRuns();
        posix_kill(end($runs)['pid'], SIGINT);
        $pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === $round + 1);
    }

    expect($pool->isRunning())->toBeTrue()
        ->and($pool->stderr())->not->toContain('stopping the pool');
});

it('lets the workers stop themselves once the pool parent is killed', function (): void {
    $pool = startPool(['--workers=2']);
    $pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === 2);

    $pool->signal(SIGKILL);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->events('stopped')) === 2))->toBeTrue()
        ->and($pool->waitUntil(fn (RunningPool $pool): bool => array_filter(array_column($pool->workerRuns(), 'pid'), $pool->isAlive(...)) === []))->toBeTrue();
});

it('kills a warm-up that hangs and starts the workers anyway', function (): void {
    $pool = startPool(['--workers=1', '--warmup-timeout=1'], ['FIXTURE_MODE' => 'warmup-hangs']);

    expect($pool->waitUntil(fn (RunningPool $pool): bool => count($pool->workerRuns()) === 1))->toBeTrue()
        ->and($pool->stderr())->toContain('warm-up still running after 1s');
});

it('refuses --once instead of restarting a worker after every job', function (): void {
    $pool = startPool(['--workers=1', 'redis', '--once']);

    expect($pool->waitForExit())->toBe(2)
        ->and($pool->stderr())->toContain('--once ends a worker on purpose')
        ->and($pool->events())->toBe([]);
});
