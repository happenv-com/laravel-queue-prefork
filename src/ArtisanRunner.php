<?php

declare(strict_types=1);

namespace Happenv\QueuePrefork;

use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\Looping;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * Boots the application in a forked child and runs one Artisan command, the
 * way the `artisan` script does. The pool parent never calls this.
 */
final class ArtisanRunner
{
    /**
     * Laravel's queue worker fires this event before every attempt to take a job.
     */
    private const string LOOPING_EVENT = Looping::class;

    public static function run(ArtisanCommand $command): int
    {
        $argv = ['artisan', ...$command->arguments];
        $_SERVER['argv'] = $argv;
        $_SERVER['argc'] = count($argv);
        // The process started long ago, as the pool; the worker starts now.
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        $_SERVER['REQUEST_TIME'] = time();

        chdir($command->basePath);

        if ($command->isWorker()) {
            // Workers share the pool's stdout: keep each line of their output whole.
            LineBufferFilter::attachTo(STDOUT);
        }

        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }

        require $command->basePath . '/vendor/autoload.php';

        /** @var Application $app */
        $app = require $command->basePath . '/bootstrap/app.php';

        if ($command->isWorker()) {
            self::watchLoop($app, $command);
        }

        return $app->handleCommand(new ArgvInput($argv));
    }

    /**
     * On its first loop a worker tells the pool it booted, so a later death
     * (a --timeout kill, --memory, a crash in a job) is not mistaken for a
     * worker that cannot start. On every loop it checks that the pool is
     * still there: an orphaned worker stops after its current job.
     *
     * @param  Application  $app
     */
    private static function watchLoop(object $app, ArtisanCommand $command): void
    {
        $readyChannel = $command->readyChannel;
        $reported = false;

        $app->make('events')->listen(self::LOOPING_EVENT, static function () use (&$reported, $readyChannel, $command): void {
            if (! $reported && is_resource($readyChannel)) {
                @fwrite($readyChannel, getmypid() . "\n");
                $reported = true;
            }

            if (posix_getppid() !== $command->poolPid) {
                posix_kill((int) getmypid(), SIGTERM);
            }
        });
    }
}
