<?php

declare(strict_types=1);

namespace Happenv\QueuePrefork\Tests;

use ErrorException;
use Happenv\QueuePrefork\QueuePreforkServiceProvider;
use Happenv\QueuePrefork\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    // The `users` table for Fixtures\User.
    use WithLaravelMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName): string => 'Happenv\\QueuePrefork\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );

        // Laravel only logs deprecations. Fail the test when the package's OWN
        // code triggers one, so it is fixed before the next PHP / Laravel
        // release turns it into an error.
        $sourcePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;

        $previousHandler = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previousHandler, $sourcePath): bool {
            if (in_array($level, [E_DEPRECATED, E_USER_DEPRECATED], true) && str_starts_with($file, $sourcePath)) {
                throw new ErrorException($message, 0, $level, $file, $line);
            }

            // Laravel's handler returns nothing once it has logged a deprecation;
            // only an explicit `false` hands the error back to PHP, which would
            // print it and make the test risky.
            return $previousHandler !== null && $previousHandler($level, $message, $file, $line) !== false;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();

        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            QueuePreforkServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/database/migrations');
    }
}
