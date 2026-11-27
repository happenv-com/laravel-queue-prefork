<?php

declare(strict_types=1);

/*
 * Writes to STDOUT through LineBufferFilter, the way a pool worker does.
 * Usage: php line-writer.php <scenario> [--unfiltered]
 */

require dirname(__DIR__, 2) . '/src/LineBufferFilter.php';

use Happenv\QueuePrefork\LineBufferFilter;

if (! in_array('--unfiltered', $argv, true)) {
    LineBufferFilter::attachTo(STDOUT);
}

switch ($argv[1]) {
    case 'forked-writers':
        // Four processes share one stdout and write every line in three pieces, as Laravel's console does.
        $children = [];

        for ($writer = 1; $writer <= 4; $writer++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                for ($line = 1; $line <= 300; $line++) {
                    fwrite(STDOUT, "writer {$writer} line {$line}");
                    usleep(50);
                    fwrite(STDOUT, ' ........................');
                    usleep(50);
                    fwrite(STDOUT, " DONE\n");
                }

                exit(0);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        break;

    case 'tail-at-exit':
        fwrite(STDOUT, "whole line\n");
        fwrite(STDOUT, 'tail without a newline');

        exit(7);

    case 'memory-fatal':
        fwrite(STDOUT, "before the fatal error\n");
        ini_set('memory_limit', '16M');
        $hog = [];

        while (true) {
            $hog[] = str_repeat('x', 1024 * 1024);
        }

    case 'fork-with-half-line':
        fwrite(STDOUT, 'parent half');
        $pid = pcntl_fork();

        if ($pid === 0) {
            fwrite(STDOUT, "child line\n");

            exit(0);
        }

        pcntl_waitpid($pid, $status);
        fwrite(STDOUT, " and the rest\n");

        break;

    case 'long-line':
        fwrite(STDOUT, str_repeat('a', 10_000) . "\n");

        break;
}
