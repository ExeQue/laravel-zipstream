<?php

namespace ExeQue\ZipStream\Exceptions;

use ExeQue\ZipStream\Content\DiskFile;
use ExeQue\ZipStream\Content\LocalFile;
use RuntimeException;

class FileUnavailableException extends RuntimeException implements ZipStreamExceptionInterface
{
    private function __construct(
        public readonly DiskFile|LocalFile $entry,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forDisk(DiskFile $file): never
    {
        throw new self($file, "File [{$file->destination()}] unavailable on disk.");
    }

    public static function forLocal(LocalFile $file): never
    {
        throw new self($file, "File [{$file->destination()}] unavailable on local filesystem.");
    }
}
