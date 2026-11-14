<?php

declare(strict_types=1);

use Happenv\QueuePrefork\QueuePreforkServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getProviders(QueuePreforkServiceProvider::class))->not->toBeEmpty();
});

it('merges the package config', function (): void {
    expect(config('queue-prefork'))->toBeArray();
});
