<?php

namespace ExeQue\ZipStream;

use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Contracts\CanStreamToZip;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Events\EventQueue;
use ExeQue\ZipStream\Events\EventType;
use ExeQue\ZipStream\Options\FileOptions;
use ExeQue\ZipStream\Options\ZipOptions;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class Pending
{
    /** @var StreamableToZip[]|Directory[] */
    private array $entries = [];

    private bool $stopOnConnectionAborted = false;

    private bool $verify = true;

    public function add(StreamableToZip|CanStreamToZip|Directory $streamable): static
    {
        if ($this->verify && $streamable instanceof Verifiable) {
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

    public function stopOnConnectionAborted(): static
    {
        $this->stopOnConnectionAborted = true;

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
        $entries = collect($this->entries);

        $events->call(EventType::ProcessStarted);

        $directories = $entries->filter(fn ($entry) => $entry instanceof Directory);
        $files = $entries->filter(fn ($entry) => $entry instanceof StreamableToZip);

        $directories->each(function (Directory $directory) use ($stream, $events) {
            if ($this->aborted()) {
                $events->call(EventType::ProcessAborted);

                return false;
            }

            try {
                $options = $directory->getFileOptions();

                $events->call([
                    EventType::StreamingDirectory,
                    EventType::StreamingToZip,
                ], $directory, $options);

                $stream->addDirectory(
                    fileName: $directory->destination(),
                    comment: $options->comment,
                    lastModificationDateTime: $options->lastModified,
                );

                $events->call([
                    EventType::StreamedDirectory,
                    EventType::StreamedToZip,
                ], $directory, $options);
            } catch (\Throwable $e) {
                if (!$events->hasHandler(EventType::ProcessError)) {
                    throw $e;
                }

                $events->call(EventType::ProcessError, $e);
            }
        });

        $files->each(function (StreamableToZip $file) use ($stream, $events, $zipOptions) {
            if ($this->aborted()) {
                $events->call(EventType::ProcessAborted);

                return false;
            }

            try {
                $options = $file instanceof HasFileOptions
                    ? $file->getFileOptions()
                    : new FileOptions();

                $events->call([
                    EventType::StreamingFile,
                    EventType::StreamingToZip,
                ], $file, $options);

                $stream->addFileFromCallback(
                    fileName: $file->destination(),
                    callback: fn () => $file->stream(),
                    comment: $options->comment,
                    compressionMethod: $options->compressionMethod,
                    deflateLevel: $options->deflateLevel,
                    lastModificationDateTime: $options->lastModified,
                    maxSize: $this->sizeLimitsApply($options, $zipOptions) ? $options->maxSize : null,
                    exactSize: $this->sizeLimitsApply($options, $zipOptions) ? $options->exactSize : null,
                    enableZeroHeader: $options->enableZeroHeader,
                );

                $events->call([
                    EventType::StreamedFile,
                    EventType::StreamedToZip,
                ], $file, $options);
            } catch (\Throwable $e) {
                if (!$events->hasHandler(EventType::ProcessError)) {
                    throw $e;
                }

                $events->call(EventType::ProcessError, $e);
            }
        });

        $events->call(EventType::ProcessFinished);
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
        return $this->stopOnConnectionAborted && connection_aborted();
    }
}
