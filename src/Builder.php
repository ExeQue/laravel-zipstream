<?php

namespace ExeQue\ZipStream;

use Closure;
use ExeQue\ZipStream\Concerns\InteractsWithZipOptions;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Content\DiskFile;
use ExeQue\ZipStream\Content\LocalFile;
use ExeQue\ZipStream\Content\Raw;
use ExeQue\ZipStream\Contracts\CanStreamToZip;
use ExeQue\ZipStream\Contracts\HasZipOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Events\EventType;
use ExeQue\ZipStream\Events\EventQueue;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    public function output(bool $stream = false): string|StreamInterface
    {
        $output = new Stream(fopen('php://temp', 'w+b'));

        $zipStream = $this->prepareZipStream($output);

        $this->pending->process($zipStream, $this->events, $this->getZipOptions());

        $zipStream->finish();

        $output->rewind();

        if ($stream) {
            return $output;
        }

        $contents = $output->getContents();

        $output->close();

        return $contents;
    }

    public function saveToLocal(string $path): ?int
    {

        Filesystem::makeDirectory(dirname($path), 0755, true, true);

        $this->events->call(EventType::SavingToFilesystem, $path);

        $stream = new Stream(fopen($path, 'w+b'));

        $zipStream = $this->prepareZipStream($stream);

        $this->pending->process($zipStream, $this->events, $this->getZipOptions());

        $zipStream->finish();

        $size = $stream->getSize();

        $stream->close();

        $this->events->call(EventType::SavedToFilesystem, $path, $size);

        return $size;
    }

    public function saveToDisk(string|FilesystemAdapter $disk, string $path): ?int
    {
        $this->events->call(EventType::SavingToDisk, $disk, $path);

        $disk = is_string($disk) ? $this->filesystemManager->disk($disk) : $disk;
        $stream = new Stream(fopen('php://temp', 'w+b'));

        $zipStream = $this->prepareZipStream($stream);

        $this->pending->process($zipStream, $this->events, $this->getZipOptions());

        $zipStream->finish();

        $size = $stream->getSize();

        $fh = $stream->detach();

        $disk->writeStream($path, $fh);

        fclose($fh);

        $this->events->call(EventType::SavedToDisk, $disk, $path, $size);

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

        return new ZipStream(
            operationMode: $operationMode,
            comment: $options->comment,
            outputStream: Utils::streamFor($outputStream),
            defaultCompressionMethod: $options->compressionMethod,
            defaultDeflateLevel: $options->deflateLevel,
            defaultEnableZeroHeader: $options->enableZeroHeader,
            sendHttpHeaders: false,
            flushOutput: $flush,
        );
    }

    public function toResponse($request): StreamedResponse
    {
        $headers = [
            'X-Accel-Buffering'   => 'no',
            'Content-Type'        => 'application/x-zip',
            'Content-Disposition' => "attachment; filename=\"$this->filename\"",
        ];

        if ($this->withContentLength && ($size = $this->calculateSize()) !== null) {
            $headers['Content-Length'] = $size;
        }

        return new StreamedResponse(
            function () {
                $this->events->call(EventType::StreamingResponse);

                $stream = $this->prepareZipStream();

                $this->pending->process($stream, $this->events, $this->getZipOptions());

                $stream->finish();

                $this->events->call(EventType::StreamedResponse);
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

    public function on(EventType|array $type, callable $handler): static
    {
        $this->events->add($type, $handler);

        return $this;
    }
}
