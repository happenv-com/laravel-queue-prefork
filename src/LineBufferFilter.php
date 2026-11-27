<?php

declare(strict_types=1);

namespace Happenv\QueuePrefork;

use php_user_filter;
use RuntimeException;
use Throwable;

/**
 * A write filter for a worker's STDOUT, which every worker of a pool shares.
 *
 * Laravel's console writes one job line in several pieces (time, name, dots,
 * status). Unfiltered, pieces from different workers interleave and the log
 * becomes unreadable. This filter holds the text until the end of a line and
 * hands out whole lines, at most PIPE_BUF bytes per write, which a pipe keeps
 * in one piece.
 *
 * STDERR stays unfiltered on purpose: after a fatal error (memory or time
 * limit) PHP stops calling user filters, and a filtered stream would drop
 * Laravel's report of that error.
 */
final class LineBufferFilter extends php_user_filter
{
    public const string NAME = 'queue-prefork.line-buffer';

    /**
     * The size up to which POSIX promises that a write to a pipe is not
     * interleaved with other writers (PIPE_BUF; 4096 on Linux).
     */
    public const int ATOMIC_WRITE_BYTES = 4096;

    private string $held = '';

    private int $ownerPid = 0;

    /** @var resource|null Buckets are made against this, not the filtered stream, which PHP may close first at shutdown. */
    private static $bucketStream;

    /**
     * @param  resource  $stream
     */
    public static function attachTo($stream): void
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }

        stream_filter_append($stream, self::NAME, STREAM_FILTER_WRITE);
    }

    public function onCreate(): bool
    {
        $this->ownerPid = (int) getmypid();

        return true;
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        // A process the job forked inherits this filter with the parent's half
        // line, which is the parent's to finish, not the child's to repeat.
        if ((int) getmypid() !== $this->ownerPid) {
            $this->held = '';
            $this->ownerPid = (int) getmypid();
        }

        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->held .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        $ready = $this->takeReadyText($closing);

        if ($ready === '') {
            return PSFS_FEED_ME;
        }

        try {
            foreach ($this->chunks($ready) as $chunk) {
                stream_bucket_append($out, stream_bucket_new($this->bucketStream(), $chunk));
            }
        } catch (Throwable) {
            // Losing a log line is better than failing the write, or the worker's exit code.
            return PSFS_FEED_ME;
        }

        return PSFS_PASS_ON;
    }

    /**
     * Whole lines, the held text once it reaches the atomic size (a line that
     * long cannot be kept whole anyway), and everything when the stream closes.
     */
    private function takeReadyText(bool $closing): string
    {
        if ($closing || strlen($this->held) >= self::ATOMIC_WRITE_BYTES) {
            $ready = $this->held;
            $this->held = '';

            return $ready;
        }

        $lastNewline = strrpos($this->held, "\n");

        if ($lastNewline === false) {
            return '';
        }

        $ready = substr($this->held, 0, $lastNewline + 1);
        $this->held = substr($this->held, $lastNewline + 1);

        return $ready;
    }

    /**
     * Splits the text into writes of at most ATOMIC_WRITE_BYTES, breaking only
     * after a newline unless a single line is longer than that.
     *
     * @return list<string>
     */
    private function chunks(string $text): array
    {
        $chunks = [];

        while (strlen($text) > self::ATOMIC_WRITE_BYTES) {
            $window = substr($text, 0, self::ATOMIC_WRITE_BYTES);
            $lastNewline = strrpos($window, "\n");
            $length = $lastNewline === false ? self::ATOMIC_WRITE_BYTES : $lastNewline + 1;

            $chunks[] = substr($text, 0, $length);
            $text = substr($text, $length);
        }

        if ($text !== '') {
            $chunks[] = $text;
        }

        return $chunks;
    }

    /**
     * @return resource
     */
    private function bucketStream()
    {
        if (! is_resource(self::$bucketStream)) {
            $stream = fopen('php://memory', 'w+');

            if ($stream === false) {
                throw new RuntimeException('Could not open a memory stream for the line buffer.');
            }

            self::$bucketStream = $stream;
        }

        return self::$bucketStream;
    }
}
