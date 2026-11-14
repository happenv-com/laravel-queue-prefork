<?php

declare(strict_types=1);

use Happenv\QueuePrefork\PoolOptions;

covers(PoolOptions::class);

it('passes every argument it does not own to queue:work, in order', function (): void {
    $options = PoolOptions::fromArgv(['queue-prefork', '--workers=8', 'redis', '--queue=high,default', '--tries=3', '--max-time=3600'], '/app');

    expect($options->workers)->toBe(8)
        ->and($options->basePath)->toBe('/app')
        ->and($options->warmup)->toBe(PoolOptions::DEFAULT_WARMUP)
        ->and($options->workerArguments)->toBe(['redis', '--queue=high,default', '--tries=3', '--max-time=3600']);
});

it('stops reading its own options after --', function (): void {
    $options = PoolOptions::fromArgv(['queue-prefork', '--workers=2', '--', '--workers=9', 'redis'], '/app');

    expect($options->workers)->toBe(2)
        ->and($options->workerArguments)->toBe(['--workers=9', 'redis']);
});

it('resolves the base path against the working directory', function (string $given, string $expected): void {
    expect(PoolOptions::fromArgv(['queue-prefork', '--base-path=' . $given], '/srv')->basePath)->toBe($expected);
})->with([
    'absolute' => ['/var/www/app/', '/var/www/app'],
    'relative' => ['current', '/srv/current'],
]);

it('takes a custom warm-up command or none', function (): void {
    expect(PoolOptions::fromArgv(['queue-prefork', '--warmup=route:list --json'], '/app')->warmup)->toBe(['route:list', '--json'])
        ->and(PoolOptions::fromArgv(['queue-prefork', '--no-warmup'], '/app')->warmup)->toBeNull();
});

it('refuses what would make the pool misbehave', function (array $argv, string $message): void {
    expect(fn (): PoolOptions => PoolOptions::fromArgv(['queue-prefork', ...$argv], '/app'))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no workers' => [['--workers=0'], 'at least 1'],
    'not a number' => [['--workers=two'], 'whole number'],
    'empty warm-up' => [['--warmup='], '--no-warmup'],
    'zero warm-up timeout' => [['--warmup-timeout=0'], 'whole number of seconds'],
    '--once' => [['redis', '--once'], '--once ends a worker'],
    '--stop-when-empty' => [['--stop-when-empty'], '--stop-when-empty ends a worker'],
]);
