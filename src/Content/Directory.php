<?php

namespace ExeQue\ZipStream\Content;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use ExeQue\ZipStream\Concerns\InteractsWithDestination;
use ExeQue\ZipStream\Contracts\HasComment;
use ExeQue\ZipStream\Contracts\HasLastModified;
use ExeQue\ZipStream\Options\FileOptions;

class Directory implements HasLastModified, HasComment
{
    use InteractsWithDestination;

    private FileOptions $fileOptions;

    public function __construct(
        private string $destination
    ) {
        $this->fileOptions = new FileOptions();
    }

    public static function make(
        string $destination,
    ): static {
        return new static($destination);
    }

    public function comment(string $comment): static
    {
        $this->fileOptions->comment = $comment;

        return $this;
    }

    public function lastModified(null|DateTimeInterface|int|string $timestamp): static
    {
        if (is_int($timestamp)) {
            $timestamp = '@' . $timestamp;
        }

        if (!is_null($timestamp)) {
            $timestamp = new CarbonImmutable($timestamp);
        }

        $this->fileOptions->lastModified = $timestamp;

        return $this;
    }

    public function getFileOptions(): FileOptions
    {
        return $this->fileOptions;
    }
}
