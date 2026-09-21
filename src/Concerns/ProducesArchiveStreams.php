<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Concerns;

use Fiber;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\PumpStream;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;
use ZipStream\ZipStream;

/**
 * The archive as something to read from, built as it is consumed.
 *
 * Part of Builder, kept apart so each concern sits with the state it owns.
 *
 * @internal
 */
trait ProducesArchiveStreams
{
    public function toStream(): StreamInterface
    {
        return $this->lazyArchive();
    }

    public function toString(): string
    {
        return $this->toStream()->getContents();
    }

    /**
     * A stream that builds the archive as it is read.
     *
     * ZipStream pushes its output while readers (Flysystem, the AWS SDK) pull at their own pace. The archive
     * is produced inside a fiber that the reader drives: every read resumes the fiber just far enough to
     * supply it, so the archive is never buffered as a whole.
     *
     * @param  int|null  $size  Receives the archive size once it has been read to the end.
     * @param  Throwable|null  $failure  Receives what went wrong while building, before it is rethrown to the reader.
     */
    private function lazyArchive(?int &$size = null, ?Throwable &$failure = null): PumpStream
    {
        $this->resolveKnownSize();

        $fiber = new Fiber(function () use (&$size) {
            // Every slot a decorator might proxy has to be here: FnStream::decorate() fills the ones
            // it isn't given with a callable into this stream, and a missing slot throws when called -
            // including from __destruct(), where the stack no longer points at anything useful.
            $zipStream = $this->prepareZipStream(new FnStream([
                'isReadable'  => fn () => false,
                'isWritable'  => fn () => true,
                'isSeekable'  => fn () => false,
                'close'       => fn () => null,
                'detach'      => fn () => null,
                'getSize'     => fn () => null,
                'getMetadata' => fn (?string $key = null) => $key === null ? [] : null,
                'write'       => function (string $data): int {
                    Fiber::suspend($data);

                    return strlen($data);
                },
            ]));

            $this->pending->process($zipStream, $this->events, $this->getZipOptions());

            $size = $this->finishArchive($zipStream);
        });

        return new PumpStream(function () use ($fiber, &$failure) {
            try {
                $data = $fiber->isStarted() ? $fiber->resume() : $fiber->start();
            } catch (Throwable $e) {
                // Throwing through the read aborts the consumer instead of completing a truncated archive.
                throw $failure = $e;
            }

            return $fiber->isTerminated() ? false : $data;
        });
    }

    /**
     * Allow rewinding to the start while no more than the first $limit bytes have been read.
     *
     * A userland stream resource always reports itself as seekable, so the AWS SDK reads up to
     * 5 MB (MultipartUploader::PART_MIN_SIZE) to probe the size of the body and then rewinds.
     * Remembering that head satisfies it without buffering the rest of the archive.
     */
    private function rewindableHead(StreamInterface $stream, int $limit = 6 * 1024 * 1024): StreamInterface
    {
        // ponytail: 1 MB of slack over the 5 MB probe covers PHP's read-ahead on the resource.
        $head = '';
        $position = 0;
        $rewindable = true;

        return FnStream::decorate($stream, [
            'read' => function (int $length) use ($stream, $limit, &$head, &$position, &$rewindable): string {
                if ($position < strlen($head)) {
                    $data = substr($head, $position, $length);
                } else {
                    $data = $stream->read($length);

                    if ($rewindable && strlen($head) + strlen($data) <= $limit) {
                        $head .= $data;
                    } else {
                        $head = '';
                        $rewindable = false;
                    }
                }

                $position += strlen($data);

                return $data;
            },
            'seek' => function (int $offset, int $whence = SEEK_SET) use (&$position, &$rewindable): void {
                if (! $rewindable || $offset !== 0 || $whence !== SEEK_SET) {
                    throw new RuntimeException('The archive can only be rewound to the start within its first bytes.');
                }

                $position = 0;
            },
            'tell' => function () use (&$position): int {
                return $position;
            },
            'eof' => function () use ($stream, &$head, &$position): bool {
                return $position >= strlen($head) && $stream->eof();
            },
        ]);
    }
}
