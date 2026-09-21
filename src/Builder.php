<?php

namespace ExeQue\ZipStream;

use Closure;
use DateInterval;
use ExeQue\ZipStream\Concerns\InteractsWithZipOptions;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Content\DiskFile;
use ExeQue\ZipStream\Content\LocalFile;
use ExeQue\ZipStream\Content\Raw;
use ExeQue\ZipStream\Contracts\CanStreamToZip;
use ExeQue\ZipStream\Contracts\HasZipOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Events\Contracts\Event;
use ExeQue\ZipStream\Events\Data\Context;
use ExeQue\ZipStream\Events\EventQueue;
use ExeQue\ZipStream\Events\SavedToDisk;
use ExeQue\ZipStream\Events\SavedToFilesystem;
use ExeQue\ZipStream\Events\SavingToDisk;
use ExeQue\ZipStream\Events\SavingToFilesystem;
use ExeQue\ZipStream\Events\StreamedBytes;
use ExeQue\ZipStream\Events\StreamedResponse;
use ExeQue\ZipStream\Events\StreamingResponse;
use ExeQue\ZipStream\Exceptions\ArchiveDiscardedException;
use ExeQue\ZipStream\Exceptions\InvalidFilenameException;
use ExeQue\ZipStream\Options\ProgressInterval;
use Fiber;
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

    private bool $withKnownSize = false;

    /** Memoised: working the size out walks every entry, and both the header and progress want it. */
    private ?int $knownSize = null;

    private bool $knownSizeResolved = false;

    private ProgressInterval $progressEvery;

    /** Flushes whatever progress the last write left unreported. Set per archive. */
    private ?Closure $flushProgress = null;

    public function __construct(
        private Factory $filesystemManager,
        Repository $config,
        private EventQueue $events = new EventQueue(),
    ) {
        $this->pending = new Pending();

        $this->progressEvery = ProgressInterval::fromConfig($config->get('laravel-zipstream.progress_every'));

        $this->prepareZipOptions($config);
        $this->as('archive');
    }

    /**
     * Report progress once this many bytes have been written.
     *
     * StreamedBytes would otherwise fire at PHP's 8 KB write size. Pass 0 for every write. The final
     * event always carries the finished size, whatever the threshold.
     */
    public function progressEveryBytes(int $bytes): static
    {
        $this->progressEvery = ProgressInterval::bytes($bytes);

        return $this;
    }

    /**
     * Report progress at most this often, whatever the throughput.
     *
     * Takes a DateInterval or an ISO 8601 duration such as "PT1S". Better than a byte threshold for a
     * progress bar: a fixed number of bytes fires thousands of times a second on a local disk and twice
     * a second on a slow upload.
     */
    public function progressEveryInterval(DateInterval|string $interval): static
    {
        $this->progressEvery = ProgressInterval::every($interval);

        return $this;
    }

    /**
     * The queue this archive is built from, for a subclass that needs to look at it.
     */
    protected function pending(): Pending
    {
        return $this->pending;
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
     * Attach whatever the application needs on every event about this archive.
     *
     * Merged into Context::$data. For something belonging to a single entry, use context() on the
     * entry instead - it comes back on the events about that entry.
     *
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): static
    {
        $state = $this->events->state();

        $state->data = [...$state->data, ...$context];

        return $this;
    }

    /**
     * Stop the archive after the entry being streamed right now.
     *
     * Meant to be called from an event handler. Remaining entries are skipped and ProcessAborted fires.
     * The archive is then finished, so what has been written stays a valid - if incomplete - zip.
     *
     * With $discard the archive is thrown away instead: an S3 multipart upload is aborted, a file this
     * call created is removed, and saveToDisk()/saveToLocal() return null. Use it when the archive is
     * not wanted at all - a cancelled download - rather than when it is wanted short.
     *
     * A response cannot be recalled, so there abort() and abort(discard: true) both simply stop
     * writing. toStream() and toString() have nothing to clean up, and let the discard surface as
     * ArchiveDiscardedException.
     */
    public function abort(bool $discard = false): static
    {
        $this->pending->abort($discard);

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

        return $this->withKnownSize($enabled);
    }

    /**
     * Work out the archive size before writing it, so progress knows what it is working towards.
     *
     * Lands on Context::$bytes->total, which is null without it. The size can only be known when every
     * entry is stored with a known exactSize; it stays null otherwise. Costs one pass over the entries
     * and no I/O.
     */
    public function withKnownSize(bool $enabled = true): static
    {
        $this->withKnownSize = $enabled;

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

    /**
     * Add every file under a prefix, with the sizes the listing already carries.
     *
     * One listing instead of a request per file: with exact sizes in hand, withContentLength() and
     * withKnownSize() work without asking the disk anything further. Entries out of a listing are known
     * to exist, so they skip verification.
     *
     * @param  string|null  $destination  The folder inside the archive. Defaults to the source's own name.
     * @param  callable(DiskFile): void|null  $modify
     */
    public function fromDiskDirectory(
        string|FilesystemAdapter $disk,
        string $source,
        ?string $destination = null,
        bool $recursive = true,
        ?callable $modify = null,
    ): static {
        $disk = is_string($disk) ? $this->filesystemManager->disk($disk) : $disk;

        $source = trim($source, '/');
        $destination = trim($destination ?? basename($source), '/');
        $modify = $this->resolveModifierCallback($modify);

        foreach ($disk->getDriver()->listContents($source, $recursive) as $attributes) {
            if (!$attributes->isFile()) {
                continue;
            }

            $file = DiskFile::make(
                $disk,
                $attributes->path(),
                trim($destination . '/' . Str::after($attributes->path(), $source), '/'),
            );

            if ($attributes->fileSize() !== null) {
                $file->exactSize($attributes->fileSize());
            }

            if ($attributes->lastModified() !== null) {
                $file->lastModified($attributes->lastModified());
            }

            $this->pending->add(tap($file, $modify), verify: false);
        }

        return $this;
    }

    public function fromLocal(
        string $source,
        ?string $destination = null,
        ?callable $modify = null,
    ): static {
        return $this->add(LocalFile::make($source, $destination), $modify);
    }

    /**
     * Add every file under a local directory, with the sizes the filesystem already knows.
     *
     * The local counterpart of fromDiskDirectory(): one walk, no request per file, and exact sizes
     * ready for withContentLength() and withKnownSize().
     *
     * @param  string|null  $destination  The folder inside the archive. Defaults to the directory's own name.
     * @param  callable(LocalFile): void|null  $modify
     */
    public function fromLocalDirectory(
        string $source,
        ?string $destination = null,
        bool $recursive = true,
        ?callable $modify = null,
    ): static {
        $source = rtrim($source, '/\\');
        $destination = trim($destination ?? basename($source), '/');
        $modify = $this->resolveModifierCallback($modify);

        $files = $recursive ? Filesystem::allFiles($source) : Filesystem::files($source);

        foreach ($files as $file) {
            $entry = LocalFile::make(
                $file->getPathname(),
                trim($destination . '/' . str_replace('\\', '/', $file->getRelativePathname()), '/'),
            );

            $entry->exactSize($file->getSize())->lastModified($file->getMTime());

            $this->pending->add(tap($entry, $modify), verify: false);
        }

        return $this;
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
     * The archive as a stream, built while it is read.
     *
     * Read once, front to back: it is not seekable, and getSize() is null until it has been read.
     */
    public function toStream(): StreamInterface
    {
        return $this->lazyArchive();
    }

    /**
     * The whole archive as a string.
     *
     * Holds it in memory, so it is for the small ones - everything else has a destination to stream to.
     */
    public function toString(): string
    {
        return $this->toStream()->getContents();
    }

    public function saveToLocal(string $path): ?int
    {
        $directory = dirname($path);

        // makeDirectory() warns about a directory that is already there, which a strict error handler turns into an error.
        is_dir($directory) || Filesystem::makeDirectory($directory, 0755, true, true);

        $this->resolveKnownSize();

        $this->events->dispatch(SavingToFilesystem::class, $path);

        // Built beside the target and moved into place, so a failure leaves whatever was there
        // untouched rather than truncated. rename() is atomic within a filesystem.
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.part';
        $stream = new Stream(fopen($temporary, 'w+b'));
        $failed = true;

        try {
            $zipStream = $this->prepareZipStream($stream);

            $this->pending->process($zipStream, $this->events, $this->getZipOptions());

            $this->finishArchive($zipStream);

            $size = $stream->getSize();
            $failed = false;
        } catch (ArchiveDiscardedException) {
            // Nothing to keep and nothing to report: the .part file goes in the finally below.
            return null;
        } finally {
            $stream->close();

            if ($failed) {
                unlink($temporary);
            }
        }

        rename($temporary, $path);

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

            if ($failure instanceof ArchiveDiscardedException) {
                return null;
            }

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
    /**
     * Give progress its target, where one can be had.
     */
    private function resolveKnownSize(): void
    {
        if (!$this->knownSizeResolved) {
            $this->knownSize = $this->withKnownSize ? $this->calculateSize() : null;
            $this->knownSizeResolved = true;
        }

        $this->events->state()->bytesTotal = $this->knownSize;
        $this->events->state()->bytesDone = 0;
    }

    private function finishArchive(ZipStream $zipStream): ?int
    {
        $size = $zipStream->finish();

        if ($this->flushProgress !== null) {
            ($this->flushProgress)();
        }

        return $size;
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

        if ($this->withContentLength && ($size = $this->resolvedSize()) !== null) {
            $headers['Content-Length'] = $size;
        }

        return new SymfonyStreamedResponse(
            function () {
                $this->resolveKnownSize();

                $this->events->dispatch(StreamingResponse::class);

                $stream = $this->prepareZipStream();

                try {
                    $this->pending->process($stream, $this->events, $this->getZipOptions());

                    $this->finishArchive($stream);
                } catch (ArchiveDiscardedException) {
                    // The bytes already sent cannot be recalled, so stopping is all there is to do.
                    return;
                }

                $this->events->dispatch(StreamedResponse::class);
            },
            200,
            $headers,
        );
    }

    private function resolvedSize(): ?int
    {
        if (!$this->knownSizeResolved) {
            $this->knownSize = $this->calculateSize();
            $this->knownSizeResolved = true;
        }

        return $this->knownSize;
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
