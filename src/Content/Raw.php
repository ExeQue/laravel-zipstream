<?php

namespace ExeQue\ZipStream\Content;

use ExeQue\ZipStream\Concerns\InteractsWithDestination;
use ExeQue\ZipStream\Concerns\InteractsWithFileOptions;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\RetainsStream;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Exceptions\UnsupportedInputException;
use ExeQue\ZipStream\Options\FileOptions;
use Psr\Http\Message\StreamInterface;

class Raw implements StreamableToZip, HasFileOptions, Verifiable, RetainsStream
{
    use InteractsWithFileOptions;
    use InteractsWithDestination;

    public function __construct(
        private string $destination,
        private mixed $content,
    ) {
        $this->prepareFileOptions();
    }

    public static function make(
        string $destination,
        mixed $content,
    ): static {
        return new static($destination, $content);
    }

    public function stream()
    {
        return $this->content;
    }

    public function getFileOptions(): FileOptions
    {
        if ($this->fileOptions->exactSize === null && is_string($this->content)) {
            $this->fileOptions->exactSize = strlen($this->content);
        }

        return $this->fileOptions;
    }

    public function verify(): void
    {
        if (is_string($this->content)) {
            return;
        }

        if (is_resource($this->content)) {
            return;
        }

        if (is_callable($this->content)) {
            return;
        }

        if ($this->content instanceof StreamInterface) {
            return;
        }

        UnsupportedInputException::forRaw($this->destination, $this->content);
    }
}
