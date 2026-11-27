<?php

declare(strict_types=1);

use Happenv\QueuePrefork\LineBufferFilter;

covers(LineBufferFilter::class);

/**
 * @return array{stdout: string, stderr: string, exitCode: int}
 */
function runLineWriter(string $scenario): array
{
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/../Fixtures/line-writer.php', $scenario],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => proc_close($process)];
}

it('keeps every line whole when several processes share one stdout', function (): void {
    $lines = explode("\n", rtrim(runLineWriter('forked-writers')['stdout'], "\n"));

    expect($lines)->toHaveCount(1200)
        ->and(array_filter($lines, static fn (string $line): bool => preg_match('/^writer [1-4] line \d+ \.{24} DONE$/', $line) !== 1))->toBe([]);
});

it('writes the unfinished last line at exit and keeps the exit code', function (): void {
    $result = runLineWriter('tail-at-exit');

    expect($result['stdout'])->toBe("whole line\ntail without a newline")
        ->and($result['exitCode'])->toBe(7);
});

it('leaves stderr alone, so a fatal error is still reported', function (): void {
    $result = runLineWriter('memory-fatal');

    expect($result['stdout'])->toBe("before the fatal error\n")
        ->and($result['stderr'])->toContain('Allowed memory size')
        ->and($result['exitCode'])->toBe(255);
});

it('does not repeat the parent\'s half line in a process the job forks', function (): void {
    $lines = explode("\n", rtrim(runLineWriter('fork-with-half-line')['stdout'], "\n"));

    expect($lines)->toEqualCanonicalizing(['child line', 'parent half and the rest']);
});

it('passes a line longer than one atomic write through unchanged', function (): void {
    expect(runLineWriter('long-line')['stdout'])->toBe(str_repeat('a', 10_000) . "\n");
});
