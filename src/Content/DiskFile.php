<?php

namespace ExeQue\ZipStream\Content;

use ExeQue\ZipStream\Concerns\InteractsWithDestination;
use ExeQue\ZipStream\Concerns\InteractsWithFileOptions;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Exceptions\FileNotFoundException;
use ExeQue\ZipStream\Exceptions\FileUnavailableException;
use Illuminate\Filesystem\FilesystemAdapter;

class DiskFile implements StreamableToZip, HasFileOptions, Verifiable
{
    use InteractsWithFileOptions;
    use InteractsWithDestination;

    private function __construct(
        private FilesystemAdapter $disk,
        private string $source,
        private string $destination,
    ) {
        $this->prepareFileOptions();
    }

    public static function make(
        FilesystemAdapter $disk,
        string $source,
        ?string $destination = null,
    ): static {
        $destination ??= basename($source);

        return new self($disk, $source, $destination);
    }

    public function stream()
    {
        $stream = $this->disk->readStream($this->source);

        if ($stream === null) {
            FileUnavailableException::forDisk($this);
        }

        return $stream;
    }

    public function verify(): void
    {
        // One request, and false for a directory: exists() plus a directories() listing needed two
        // per entry, which on S3 is 1000 requests for a 500 file archive before a byte is read.
        if (!$this->disk->fileExists($this->source)) {
            FileNotFoundException::forDisk($this->source);
        }
    }
}
