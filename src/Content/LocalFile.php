<?php

namespace ExeQue\ZipStream\Content;

use ExeQue\ZipStream\Concerns\InteractsWithDestination;
use ExeQue\ZipStream\Concerns\InteractsWithFileOptions;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Exceptions\FileNotFoundException;
use ExeQue\ZipStream\Exceptions\FileUnavailableException;
use ExeQue\ZipStream\Options\FileOptions;

class LocalFile implements StreamableToZip, HasFileOptions, Verifiable
{
    use InteractsWithFileOptions;
    use InteractsWithDestination;

    private function __construct(
        private string $source,
        private string $destination
    ) {
        $this->prepareFileOptions();
    }

    public static function make(
        string  $source,
        ?string $destination = null,
    ): static {
        $destination ??= basename($source);

        return new static($source, $destination);
    }

    /**
     * @return resource
     */
    public function stream()
    {
        $stream = fopen($this->source, 'rb');

        if ($stream === false) {
            FileUnavailableException::forLocal($this);
        }

        return $stream;
    }

    public function verify(): void
    {
        if (!is_file($this->source)) {
            FileNotFoundException::forLocal($this->source);
        }
    }

    public function getFileOptions(): FileOptions
    {
        if ($this->fileOptions->exactSize === null && is_file($this->source)) {
            $size = filesize($this->source);

            if ($size !== false) {
                $this->fileOptions->exactSize = $size;
            }
        }

        return $this->fileOptions;
    }
}
