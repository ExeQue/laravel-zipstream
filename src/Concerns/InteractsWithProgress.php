<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Concerns;

use Closure;
use DateInterval;
use ExeQue\ZipStream\Events\StreamedBytes;
use ExeQue\ZipStream\Options\ProgressInterval;
use GuzzleHttp\Psr7\FnStream;
use Psr\Http\Message\StreamInterface;
use ZipStream\ZipStream;

/**
 * How often progress is reported, and the counting behind it.
 *
 * Part of Builder, kept apart so each concern sits with the state it owns.
 *
 * @internal
 */
trait InteractsWithProgress
{
    private ProgressInterval $progressEvery;

    /** Flushes whatever progress the last write left unreported. Set per archive. */
    private ?Closure $flushProgress = null;

    public function progressEveryBytes(int $bytes): static
    {
        $this->progressEvery = ProgressInterval::bytes($bytes);

        return $this;
    }

    public function progressEveryInterval(DateInterval|string $interval): static
    {
        $this->progressEvery = ProgressInterval::every($interval);

        return $this;
    }

    /**
     * Report archive bytes as they are written, on every destination.
     *
     * Counts the archive's own output rather than the bytes read from the sources, so it also
     * covers the central directory a zip ends with.
     */
    private function reportingProgress(StreamInterface $stream): StreamInterface
    {
        $this->flushProgress = null;

        if (! $this->events->hasHandlerFor(StreamedBytes::class)) {
            return $stream;
        }

        $state = $this->events->state();
        $pending = 0;
        $reportedAt = microtime(true);

        $report = function () use (&$pending, &$reportedAt): void {
            if ($pending === 0) {
                return;
            }

            $this->events->dispatch(StreamedBytes::class, $pending);

            $pending = 0;
            $reportedAt = microtime(true);
        };

        // Without this the tail of the archive goes unreported and progress never reaches the size.
        $this->flushProgress = $report;

        return FnStream::decorate($stream, [
            'write' => function (string $data) use ($stream, $state, &$pending, &$reportedAt, $report): int {
                $written = $stream->write($data);
                $state->bytesDone += $written;
                $pending += $written;

                $due = $this->progressEvery->isTimeBased()
                    ? microtime(true) - $reportedAt >= $this->progressEvery->seconds
                    : $pending >= $this->progressEvery->bytes;

                if ($due) {
                    $report();
                }

                return $written;
            },
        ]);
    }

    /**
     * Close the archive and report whatever progress the last write left over.
     */
    private function finishArchive(ZipStream $zipStream): ?int
    {
        $size = $zipStream->finish();

        if ($this->flushProgress !== null) {
            ($this->flushProgress)();
        }

        return $size;
    }
}
