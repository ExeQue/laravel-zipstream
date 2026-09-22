<?php

namespace ExeQue\ZipStream;

use ExeQue\ZipStream\Concerns\AddsContent;
use ExeQue\ZipStream\Concerns\InteractsWithKnownSize;
use ExeQue\ZipStream\Concerns\InteractsWithOutputLayer;
use ExeQue\ZipStream\Concerns\InteractsWithProgress;
use ExeQue\ZipStream\Concerns\InteractsWithZipOptions;
use ExeQue\ZipStream\Concerns\ProducesArchiveStreams;
use ExeQue\ZipStream\Concerns\SavesToDisk;
use ExeQue\ZipStream\Contracts\ArchiveBuilder;
use ExeQue\ZipStream\Events\EventQueue;
use ExeQue\ZipStream\Events\SavedToFilesystem;
use ExeQue\ZipStream\Events\SavingToFilesystem;
use ExeQue\ZipStream\Events\StreamedResponse;
use ExeQue\ZipStream\Events\StreamingResponse;
use ExeQue\ZipStream\Exceptions\ArchiveDiscardedException;
use ExeQue\ZipStream\Exceptions\InvalidFilenameException;
use ExeQue\ZipStream\Options\ProgressInterval;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse as SymfonyStreamedResponse;
use ZipStream\OperationMode;
use ZipStream\ZipStream;

class Builder implements ArchiveBuilder
{
    // In the order an archive goes through them.
    use AddsContent;
    use InteractsWithZipOptions;
    use InteractsWithKnownSize;
    use InteractsWithProgress;
    use ProducesArchiveStreams;
    use SavesToDisk;
    use InteractsWithOutputLayer;
    use Macroable;

    private string $filename;

    private Pending $pending;

    public function __construct(
        private Factory $filesystemManager,
        Repository $config,
        private EventQueue $events = new EventQueue(),
    ) {
        $this->pending = new Pending();

        // A handler may ask for the archive it belongs to, after the event.
        $this->events->for($this);

        $this->progressEvery = ProgressInterval::fromConfig($config->get('laravel-zipstream.progress_every'));
        $this->managesOutput = (bool) ($config->get('laravel-zipstream.manage_output') ?? true);

        $this->prepareZipOptions($config);
        $this->as('archive');
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

    public function withContext(array $context): static
    {
        $state = $this->events->state();

        $state->data = [...$state->data, ...$context];

        return $this;
    }

    public function withoutContext(): static
    {
        $this->events->state()->data = [];

        return $this;
    }

    public function withVerification(): static
    {
        $this->pending->withVerification();

        return $this;
    }

    public function withoutVerification(): static
    {
        $this->pending->withoutVerification();

        return $this;
    }

    public function abort(bool $discard = false): static
    {
        $this->pending->abort($discard);

        return $this;
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

    public function toResponse($request): SymfonyStreamedResponse
    {
        $headers = [
            'X-Accel-Buffering'   => 'no',
            'Content-Type'        => 'application/zip',
            // nginx's gzip filter buffers whatever X-Accel-Buffering says, unless an encoding is declared.
            ...($this->managesOutput ? ['Content-Encoding' => 'identity'] : []),
            // Quotes and non-ASCII in a filename need escaping and an RFC 6266 fallback.
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $this->filename,
                Str::ascii($this->filename) ?: 'archive.zip',
            ),
        ];

        if ($this->withContentLength && ($size = $this->resolvedSize()) !== null) {
            $headers['Content-Length'] = $size;

            // The body is written when the response is sent, which is later than this. An entry added
            // in between would make the header a lie, and a lie about a length truncates a download.
            $this->pending->freeze();
        }

        return new SymfonyStreamedResponse(
            function () {
                $restore = $this->clearTheWay();

                $this->resolveKnownSize();

                $this->events->dispatch(StreamingResponse::class);

                $stream = $this->prepareZipStream();

                try {
                    $this->pending->process($stream, $this->events, $this->getZipOptions());

                    $this->finishArchive($stream);
                } catch (ArchiveDiscardedException) {
                    // The bytes already sent cannot be recalled, so stopping is all there is to do.
                    return;
                } finally {
                    $restore();
                }

                $this->events->dispatch(StreamedResponse::class);
            },
            200,
            $headers,
        );
    }

    public function on(callable $handler): static
    {
        $this->events->add($handler);

        return $this;
    }
}
