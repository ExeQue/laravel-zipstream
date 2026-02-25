<?php

namespace ExeQue\ZipStream\Exceptions;

use RuntimeException;

class FileNotFoundException extends RuntimeException implements ZipStreamExceptionInterface
{
    public static function forDisk(string $source): never
    {
        throw new static("File [$source] not found on disk.");
    }

    public static function forLocal(string $source): never
    {
        throw new static("File [$source] not found on local filesystem.");
    }
}
