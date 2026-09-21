<?php

namespace ExeQue\ZipStream;

use Closure;
use Fiber;
use ExeQue\ZipStream\Concerns\InteractsWithZipOptions;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Content\DiskFile;
use ExeQue\ZipStream\Content\LocalFile;
use ExeQue\ZipStream\Content\Raw;
use ExeQue\ZipStream\Contracts\CanStreamToZip;
use ExeQue\ZipStream\Contracts\HasZipOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Events\Event;
use ExeQue\ZipStream\Events\EventQueue;
use ExeQue\ZipStream\Events\SavedToDisk;
use ExeQue\ZipStream\Events\SavedToFilesystem;
use ExeQue\ZipStream\Events\SavingToDisk;
use ExeQue\ZipStream\Events\SavingToFilesystem;
use ExeQue\ZipStream\Events\StreamedBytes;
use ExeQue\ZipStream\Events\StreamedResponse;
use ExeQue\ZipStream\Events\StreamingResponse;
use ExeQue\ZipStream\Exceptions\InvalidFilenameException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse as SymfonyStreamedResponse;
use Throwable;
use ZipStream\Exception\OverflowException;
use ZipStream\Exception\SimulationFileUnknownException;
use ZipStream\OperationMode;
use ZipStream\ZipStream;

class Builder implements Responsable, HasZipOptions
{
    use InteractsWithZipOptions;
    use Macroable;

    private string $filename;

    private Pending $pending;

    private bool $withContentLength = false;

    public function __construct(
        private Factory $filesystemManager,
        Repository $config,
        private EventQueue $events = new EventQueue(),
    ) {
        $this->pending = new Pending();

        $this->prepareZipOptions($config);
        $this->as('archive');
    }

    public function as(string $filename): static
    {
        if (preg_match('/[\r\n]/', $filename) === 1) {
            InvalidFilenameException::forFilename($filename);
        }

        if (Str::of($filename)->lower()->doesntEndWith('.zip')) {
            $filename .= '.zip';
        }

        $this->filename = $filename;

        return $this;
    }

    public function stopOnConnectionAborted(): static
    {
        $this->pending->stopOnConnectionAborted();

        return $this;
    }

    /**
     * Stop the archive after the entry being streamed right now.
     *
     * Meant to be called from an event handler: remaining entries are skipped, ProcessAborted fires and
     * the archive is finished, so what has been written stays a valid - if incomplete - zip.
     */
    public function abort(): static
    {
        $this->pending->abort();

        return $this;
    }

    public function withoutVerification(): static
    {
        $this->pending->withoutVerification();

        return $this;
    }

    public function withContentLength(bool $enabled = true): static
    {
        $this->withContentLength = $enabled;

        return $this;
    }

    public function add(StreamableToZip|CanStreamToZip|Directory $content, ?callable $modify = null): static
    {
        $modify = $this->resolveModifierCallback($modify);

        $this->pending->add(
            tap($content, $modify),
        );

        return $this;
    }

    public function fromDisk(
        string|FilesystemAdapter $disk,
        string $source,
        ?string $destination = null,
        ?callable $modify = null,
    ): static {
        $disk = is_string($disk) ? $this->filesystemManager->disk($disk) : $disk;

        $destination ??= basename($source);

        return $this->add(DiskFile::make($disk, $source, $destination), $modify);
    }

    public function fromLocal(
        string $source,
        ?string $destination = null,
        ?callable $modify = null,
    ): static {
        return $this->add(LocalFile::make($source, $destination), $modify);
    }

    public function fromRaw(
        string $destination,
        string $content,
        ?callable $modify = null,
    ): static {
        return $this->add(Raw::make($destination, $content), $modify);
    }

    public function emptyDirectory(string $directory, ?callable $modify = null): static
    {
        return $this->add(new Directory($directory), $modify);
    }

    /**
     * The stream is built while it is read: it can be read once, front to back, and is not seekable.
     */
    public function output(bool $stream = false): string|StreamInterface
    {
        $archive = $this->lazyArchive();

        return $stream ? $archive : $archive->getContents();
    }

    public function saveToLocal(string $path): ?int
    {

        $directory = dirname($path);

        // makeDirectory() warns about a directory that is already there, which a strict error handler turns into an error.
        is_dir($directory) || Filesystem::makeDirectory($directory, 0755, true, true);

        $this->events->dispatch(SavingToFilesystem::class, $path);

        $existed = is_file($path);
        $stream = new Stream(fopen($path, 'w+b'));
        $failed = true;

        try {
            $zipStream = $this->prepareZipStream($stream);

            $this->pending->process($zipStream, $this->events, $this->getZipOptions());

            $zipStream->finish();

            $size = $stream->getSize();
            $failed = false;
        } finally {
            $stream->close();

            // Only what this call created: half an archive is worse than no archive, but a file
            // that was already there is not ours to remove.
            if ($failed && !$existed) {
                unlink($path);
            }
        }

        $this->events->dispatch(SavedToFilesystem::class, $path, $size);

        return $size;
    }

    /**
     * Stream the archive to a disk while it is being built, see lazyArchive().
     *
     * @param  array<string, mixed>  $options  Passed on to the disk, e.g. ['part_size' => ...] for S3.
     * @return int|null The archive size, or null if the disk stopped reading before the archive was finished.
     */
    public function saveToDisk(string|FilesystemAdapter $disk, string $path, array $options = []): ?int
    {
        $disk = is_string($disk) ? $this->filesystemManager->disk($disk) : $disk;

        $this->events->dispatch(SavingToDisk::class, $disk, $path);

        // Only an S3 disk hands out a client, and only S3 can leave a multipart upload behind.
        $client = method_exists($disk, 'getClient') ? $disk->getClient() : null;
        $upload = null;

        if ($client !== null) {
            $options = $this->capturingMultipartUpload($options, $upload);
        }

        $existed = $disk->exists($path);

        $size = null;
        $failure = null;

        $handle = StreamWrapper::getResource($this->rewindableHead($this->lazyArchive($size, $failure)));

        try {
            $disk->writeStream($path, $handle, $options);
        } catch (Throwable $e) {
            $failure ??= $e;
        } finally {
            // A disk may already have closed it through the PSR-7 stream it wrapped it in.
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($failure !== null) {
            $this->cleanUpFailedWrite($disk, $path, $existed, $client, $upload);

            // The disk wraps (or, when not set to throw, swallows) errors raised while reading.
            throw $failure;
        }

        $this->events->dispatch(SavedToDisk::class, $disk, $path, $size);

        return $size;
    }

    /**
     * Remember the multipart upload S3 starts, so that a failed write can abort it.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, string>|null  $upload  Receives Bucket/Key/UploadId once a part is on its way.
     * @return array<string, mixed>
     */
    private function capturingMultipartUpload(array $options, ?array &$upload): array
    {
        $before = $options['before_upload'] ?? null;

        $options['before_upload'] = function ($command) use ($before, &$upload): void {
            if (isset($command['UploadId'])) {
                $upload = [
                    'Bucket'   => $command['Bucket'],
                    'Key'      => $command['Key'],
                    'UploadId' => $command['UploadId'],
                ];
            }

            if ($before !== null) {
                $before($command);
            }
        };

        return $options;
    }

    /**
     * Leave the disk as it was before the failed write.
     *
     * @param  array<string, string>|null  $upload
     */
    private function cleanUpFailedWrite(
        FilesystemAdapter $disk,
        string $path,
        bool $existed,
        mixed $client,
        ?array $upload,
    ): void {
        if ($client !== null && $upload !== null) {
            try {
                // The SDK keeps the parts of a failed multipart upload, billed and hidden from a listing.
                $client->abortMultipartUpload($upload);
            } catch (Throwable) {
                // Nothing left to do about it - the original failure is the one worth reporting.
            }
        }

        if (! $existed) {
            // Only what this call created: a file that was already there is not ours to remove.
            $disk->delete($path);
        }
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

            $size = $zipStream->finish();
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

    private function prepareZipStream(
        mixed $outputStream = null,
        OperationMode $operationMode = OperationMode::NORMAL,
    ): ZipStream {
        $options = $this->getZipOptions();

        // Only the response path writes to php://output, and only it needs to flush.
        $flush = $outputStream === null;

        $outputStream ??= fopen('php://output', 'w+b');

        $outputStream = Utils::streamFor($outputStream);

        if ($operationMode === OperationMode::NORMAL) {
            $outputStream = $this->reportingProgress($outputStream);
        }

        return new ZipStream(
            operationMode: $operationMode,
            comment: $options->comment,
            outputStream: $outputStream,
            defaultCompressionMethod: $options->compressionMethod,
            defaultDeflateLevel: $options->deflateLevel,
            defaultEnableZeroHeader: $options->enableZeroHeader,
            sendHttpHeaders: false,
            flushOutput: $flush,
        );
    }

    /**
     * Report archive bytes as they are written, on every destination.
     *
     * Counts the archive's own output rather than the bytes read from the sources, so it also
     * covers the central directory a zip ends with.
     */
    private function reportingProgress(StreamInterface $stream): StreamInterface
    {
        if (! $this->events->hasHandlerFor(StreamedBytes::class)) {
            return $stream;
        }

        $total = 0;

        return FnStream::decorate($stream, [
            'write' => function (string $data) use ($stream, &$total): int {
                $written = $stream->write($data);
                $total += $written;

                $this->events->dispatch(StreamedBytes::class, $written, $total);

                return $written;
            },
        ]);
    }

    public function toResponse($request): SymfonyStreamedResponse
    {
        $headers = [
            'X-Accel-Buffering'   => 'no',
            'Content-Type'        => 'application/zip',
            // Quotes and non-ASCII in a filename need escaping and an RFC 6266 fallback.
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $this->filename,
                Str::ascii($this->filename) ?: 'archive.zip',
            ),
        ];

        if ($this->withContentLength && ($size = $this->calculateSize()) !== null) {
            $headers['Content-Length'] = $size;
        }

        return new SymfonyStreamedResponse(
            function () {
                $this->events->dispatch(StreamingResponse::class);

                $stream = $this->prepareZipStream();

                $this->pending->process($stream, $this->events, $this->getZipOptions());

                $stream->finish();

                $this->events->dispatch(StreamedResponse::class);
            },
            200,
            $headers,
        );
    }

    /**
     * Determine the exact archive size without performing any I/O.
     *
     * Returns null when a size cannot be known up front - every entry has to use
     * CompressionMethod::STORE and carry a known exactSize for the simulation to succeed.
     */
    private function calculateSize(): ?int
    {
        $sink = fopen('php://memory', 'w+b');
        $simulation = $this->prepareZipStream($sink, OperationMode::SIMULATE_STRICT);

        try {
            // A fresh queue fires no user handlers, and - since it has no ProcessError
            // handler - lets a failed simulation bubble out instead of being swallowed.
            $this->pending->process($simulation, new EventQueue(), $this->getZipOptions());

            return $simulation->finish();
        } catch (SimulationFileUnknownException|OverflowException) {
            return null;
        } finally {
            fclose($sink);
        }
    }

    private function resolveModifierCallback(?callable $modify): Closure
    {
        return ($modify ?? static fn ($optionable) => null)(...);
    }

    /**
     * Register an event handler.
     *
     * What it listens for is its first parameter: a concrete event, one of the interfaces they are
     * grouped by, or a union of either.
     *
     * @param  callable(Event): void  $handler
     */
    public function on(callable $handler): static
    {
        $this->events->add($handler);

        return $this;
    }
}
