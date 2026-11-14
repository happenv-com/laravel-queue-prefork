<?php

declare(strict_types=1);

namespace Happenv\QueuePrefork;

use InvalidArgumentException;

/**
 * Entry point of `vendor/bin/queue-prefork`: checks the platform, makes sure
 * opcache is on for the CLI, then hands over to the Supervisor.
 */
final class Launcher
{
    /**
     * Set on the re-executed process, so a PHP that cannot turn opcache on
     * does not re-execute forever.
     */
    private const string REEXEC_ENV = 'QUEUE_PREFORK_REEXECUTED';

    /**
     * @param  list<string>  $argv
     */
    public static function main(array $argv): int | ArtisanCommand
    {
        if (array_intersect(['--help', '-h'], $argv) !== []) {
            fwrite(STDOUT, PoolOptions::usage());

            return 0;
        }

        try {
            $options = PoolOptions::fromArgv($argv, (string) getcwd());
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, 'queue-prefork: ' . $exception->getMessage() . PHP_EOL . PHP_EOL . PoolOptions::usage());

            return 2;
        }

        $problem = self::platformProblem() ?? self::applicationProblem($options->basePath);

        if ($problem !== null) {
            fwrite(STDERR, 'queue-prefork: ' . $problem . PHP_EOL);

            return 1;
        }

        self::enableOpcacheForCli($argv);

        return (new Supervisor($options))->run();
    }

    private static function platformProblem(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'Windows has no fork(); run queue:work instead.';
        }

        foreach (['pcntl', 'posix'] as $extension) {
            if (! extension_loaded($extension)) {
                return "the {$extension} extension is required for the PHP CLI.";
            }
        }

        foreach (['pcntl_fork', 'pcntl_signal', 'pcntl_wait', 'posix_kill'] as $function) {
            if (! function_exists($function)) {
                return "{$function}() is disabled; remove it from disable_functions for the PHP CLI.";
            }
        }

        return null;
    }

    private static function applicationProblem(string $basePath): ?string
    {
        foreach (['vendor/autoload.php', 'bootstrap/app.php'] as $file) {
            if (! is_file($basePath . '/' . $file)) {
                return "{$basePath}/{$file} not found; run it from your application's directory or pass --base-path.";
            }
        }

        return null;
    }

    /**
     * Opcache's shared memory is created when PHP starts, so turning it on for
     * an already running CLI process is impossible: re-execute this script, in
     * the same process, with `opcache.enable_cli=1`.
     *
     * @param  list<string>  $argv
     */
    private static function enableOpcacheForCli(array $argv): void
    {
        if (filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL) || getenv(self::REEXEC_ENV) === '1') {
            return;
        }

        if (! function_exists('pcntl_exec') || ! extension_loaded('Zend OPcache')) {
            return;
        }

        putenv(self::REEXEC_ENV . '=1');

        pcntl_exec(PHP_BINARY, ['-d', 'opcache.enable_cli=1', ...$argv]);
        // Only reached when exec failed: carry on without opcache.
    }
}
