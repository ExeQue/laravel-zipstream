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
use ZipStream\ZipStream;

class Builder implements Responsable, HasZipOptions
{
    use InteractsWithZipOptions;
    use Macroable;

    private string $filename;

    private Pending $pending;

    public function __construct(
        private Factory $filesystemManager,
        Repository $config,
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

        $zipStream = $this->prepareZipStream($output, false);

        $this->pending->process($zipStream);

        $zipStream->finish();

        $output->rewind();

        return $stream ? $output : $output->getContents();
    }

    public function saveToLocal(string $path): ?int
    {
        Filesystem::makeDirectory(dirname($path), 0755, true, true);

        $stream = new Stream(fopen($path, 'w+b'));

        $zipStream = $this->prepareZipStream($stream, false);

        $this->pending->process($zipStream);

        $zipStream->finish();

        $size = $stream->getSize();

        $stream->close();

        return $size;
    }

    public function saveToDisk(string|FilesystemAdapter $disk, string $path): ?int
    {
        $disk = is_string($disk) ? $this->filesystemManager->disk($disk) : $disk;
        $stream = new Stream(fopen('php://temp', 'w+b'));

        $zipStream = $this->prepareZipStream($stream, false);

        $this->pending->process($zipStream);

        $zipStream->finish();

        $size = $stream->getSize();

        $fh = $stream->detach();

        $disk->writeStream($path, $fh);

        fclose($fh);

        return $size;
    }

    private function prepareZipStream(mixed $outputStream = null, bool $flush = false): ZipStream
    {
        $options = $this->getZipOptions();

        $outputStream ??= fopen('php://output', 'w+b');

        return new ZipStream(
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
        return new StreamedResponse(
            function () {
                $stream = $this->prepareZipStream();

                $this->pending->process($stream);

                $stream->finish();
            },
            200,
            [
                'X-Accel-Buffering'   => 'no',
                'Content-Type'        => 'application/x-zip',
                'Content-Disposition' => "attachment; filename=\"$this->filename\"",
            ],
        );
    }

    private function resolveModifierCallback(?callable $modify): Closure
    {
        return ($modify ?? static fn ($optionable) => null)(...);
    }
}
