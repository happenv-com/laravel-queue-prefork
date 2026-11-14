<?php

declare(strict_types=1);

use Happenv\QueuePrefork\Tests\Fixtures\User;
use Happenv\QueuePrefork\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

function createUser(string $email = 'jane@example.com'): User
{
    return User::query()->create([
        'name' => 'Jane',
        'email' => $email,
        'password' => 'password',
    ]);
}
