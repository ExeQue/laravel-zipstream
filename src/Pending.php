<?php

namespace ExeQue\ZipStream;

use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Contracts\CanStreamToZip;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\RetainsStream;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Events\EventQueue;
use ExeQue\ZipStream\Events\ProcessAborted;
use ExeQue\ZipStream\Events\ProcessError;
use ExeQue\ZipStream\Events\ProcessFinished;
use ExeQue\ZipStream\Events\ProcessStarted;
use ExeQue\ZipStream\Events\StreamedDirectory;
use ExeQue\ZipStream\Events\StreamedFile;
use ExeQue\ZipStream\Events\StreamingDirectory;
use ExeQue\ZipStream\Events\StreamingFile;
use ExeQue\ZipStream\Exceptions\ArchiveDiscardedException;
use ExeQue\ZipStream\Options\FileOptions;
use ExeQue\ZipStream\Options\ZipOptions;
use Psr\Http\Message\StreamInterface;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class Pending
{
    /** @var StreamableToZip[]|Directory[] */
    private array $entries = [];

    private bool $stopOnConnectionAborted = false;

    private bool $aborted = false;

    private bool $discard = false;

    private bool $verify = true;

    /**
     * @param  bool  $verify  Pass false for an entry already known to exist, such as one out of a listing.
     */
    public function add(StreamableToZip|CanStreamToZip|Directory $streamable, bool $verify = true): static
    {
        if ($verify && $this->verify && $streamable instanceof Verifiable) {
            $streamable->verify();
        }

        if ($streamable instanceof CanStreamToZip) {
            $entries = $streamable->getStreamableToZip();

            if (is_iterable($entries)) {
                foreach ($entries as $entry) {
                    $this->add($entry);
                }

                return $this;
            }

            return $this->add($entries);
        }

        $this->entries[] = $streamable;

        return $this;
    }

    /**
     * The entries queued so far, in the order they were added.
     *
     * @return array<int, StreamableToZip|Directory>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function stopOnConnectionAborted(): static
    {
        $this->stopOnConnectionAborted = true;

        return $this;
    }

    /**
     * Stop after the entry being streamed right now.
     *
     * Remaining entries are skipped and the archive is finished, so what has been written stays a
     * valid - if incomplete - zip.
     */
    public function abort(bool $discard = false): static
    {
        $this->aborted = true;
        $this->discard = $this->discard || $discard;

        return $this;
    }

    public function withoutVerification(): static
    {
        $this->verify = false;

        return $this;
    }

    /** @noinspection PhpInconsistentReturnPointsInspection */
    public function process(
        ZipStream $stream,
        EventQueue $events = new EventQueue(),
        ?ZipOptions $zipOptions = null,
    ): void {
        // A builder is reusable, so a previous abort must not silently empty the next archive.
        $this->aborted = false;
        $this->discard = false;

        $entries = collect($this->entries);

        $state = $events->state();
        $state->entriesTotal = $entries->count();
        $state->entriesDone = 0;
        $state->entry = null;

        $events->dispatch(ProcessStarted::class);

        $directories = $entries->filter(fn ($entry) => $entry instanceof Directory);
        $files = $entries->filter(fn ($entry) => $entry instanceof StreamableToZip);

        $directories->each(function (Directory $directory) use ($stream, $events, $state) {
            if ($this->aborted()) {
                $this->stop($events);

                return false;
            }

            $state->entry = $directory;
            $options = $directory->getFileOptions();

            $events->dispatch(StreamingDirectory::class, $directory, $options);

            try {
                $stream->addDirectory(
                    fileName: $directory->destination(),
                    comment: $options->comment,
                    lastModificationDateTime: $options->lastModified,
                );
            } catch (\Throwable $e) {
                $this->reportOrThrow($events, $e);

                return null;
            }

            $state->entriesDone++;

            $events->dispatch(StreamedDirectory::class, $directory, $options);

            $state->entry = null;
        });

        $files->each(function (StreamableToZip $file) use ($stream, $events, $zipOptions, $state) {
            if ($this->aborted()) {
                $this->stop($events);

                return false;
            }

            $state->entry = $file;
            $opened = null;

            try {
                $options = $file instanceof HasFileOptions
                    ? $file->getFileOptions()
                    : new FileOptions();

                $events->dispatch(StreamingFile::class, $file, $options);

                try {
                    $stream->addFileFromCallback(
                        fileName: $file->destination(),
                        callback: function () use ($file, &$opened) {
                            return $opened = $file->stream();
                        },
                        comment: $options->comment,
                        compressionMethod: $options->compressionMethod,
                        deflateLevel: $options->deflateLevel,
                        lastModificationDateTime: $options->lastModified,
                        maxSize: $this->sizeLimitsApply($options, $zipOptions) ? $options->maxSize : null,
                        exactSize: $this->sizeLimitsApply($options, $zipOptions) ? $options->exactSize : null,
                        enableZeroHeader: $options->enableZeroHeader,
                    );
                } catch (\Throwable $e) {
                    $this->reportOrThrow($events, $e);

                    return null;
                }

                $state->entriesDone++;

                $events->dispatch(StreamedFile::class, $file, $options);
            } finally {
                // Between entries nothing is being written, and the archive is closed with none open.
                $state->entry = null;

                if (!$file instanceof RetainsStream) {
                    $this->closeStream($opened);
                }
            }
        });

        $events->dispatch(ProcessFinished::class);
    }

    /**
     * Leave the archive: kept as it stands, or thrown away.
     */
    private function stop(EventQueue $events): void
    {
        $events->dispatch(ProcessAborted::class);

        if ($this->discard) {
            // Unwinds past finish(), so the destination never sees a complete archive to keep.
            throw ArchiveDiscardedException::make();
        }
    }

    /**
     * Route a failure from streaming an entry to a ProcessError handler, or out to the caller.
     *
     * Only the entry itself is covered: an exception from an event handler is the caller's own
     * code failing, so it keeps bubbling up rather than being reported as a streaming error.
     */
    private function reportOrThrow(EventQueue $events, \Throwable $e): void
    {
        if (!$events->hasHandlerFor(ProcessError::class)) {
            throw $e;
        }

        $events->dispatch(ProcessError::class, $e);
    }

    /**
     * Release the stream an entry handed us.
     *
     * Entries are held for the lifetime of the archive, so an entry that memoises its own
     * handle keeps it open for every remaining entry - N files means N concurrent handles.
     * Nothing downstream closes it: zipstream-php never calls fclose().
     */
    private function closeStream(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);

            return;
        }

        if ($stream instanceof StreamInterface) {
            $stream->close();
        }
    }

    /**
     * Size limits are only safe for stored entries.
     *
     * With DEFLATE, maxSize/exactSize end zipstream-php's read loop before feof(), so
     * deflate_add() is never called with ZLIB_FINISH and the buffered compressed tail is
     * dropped - producing a silently empty entry. See ZipStream\File::readStream().
     */
    private function sizeLimitsApply(FileOptions $options, ?ZipOptions $zipOptions): bool
    {
        $method = $options->compressionMethod ?? $zipOptions?->compressionMethod;

        return $method === CompressionMethod::STORE;
    }

    private function aborted(): bool
    {
        return $this->aborted || ($this->stopOnConnectionAborted && connection_aborted());
    }
}
