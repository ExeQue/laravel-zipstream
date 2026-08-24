<?php

namespace ExeQue\ZipStream\Content;

use ExeQue\ZipStream\Concerns\InteractsWithDestination;
use ExeQue\ZipStream\Concerns\InteractsWithFileOptions;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Exceptions\FileNotFoundException;
use ExeQue\ZipStream\Exceptions\FileUnavailableException;
use Illuminate\Contracts\Filesystem\Filesystem;

class DiskFile implements StreamableToZip, HasFileOptions, Verifiable
{
    use InteractsWithFileOptions;
    use InteractsWithDestination;

    private function __construct(
        private Filesystem $disk,
        private string $source,
        private string $destination,
    ) {
        $this->prepareFileOptions();
    }

    public static function make(
        Filesystem $disk,
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
        if (!$this->disk->exists($this->source)) {
            FileNotFoundException::forDisk($this->source);
        }

        $directory = dirname($this->source);

        if (in_array($this->source, $this->disk->directories($directory === '.' ? '' : $directory))) {
            FileNotFoundException::forDisk($this->source);
        }
    }
}
