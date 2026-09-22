<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Exceptions;

use InvalidArgumentException;

class InvalidFilenameException extends InvalidArgumentException implements ZipStreamExceptionInterface
{
    public static function forFilename(string $filename): never
    {
        throw new static(
            sprintf('The archive name [%s] cannot contain line breaks.', addcslashes($filename, "\r\n")),
        );
    }
}
