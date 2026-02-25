<?php

namespace ExeQue\ZipStream\Content;

use ExeQue\ZipStream\Concerns\InteractsWithDestination;
use ExeQue\ZipStream\Concerns\InteractsWithFileOptions;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Exceptions\FileNotFoundException;
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
        return $this->disk->readStream($this->source);
    }

    public function verify(): void
    {
        if (!$this->disk->exists($this->source)) {
            FileNotFoundException::forDisk($this->source);
        }

        if (in_array($this->source, $this->disk->allDirectories())) {
            FileNotFoundException::forDisk($this->source);
        }
    }
}
