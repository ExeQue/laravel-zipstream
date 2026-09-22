<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Exceptions;

use RuntimeException;

class ArchiveFrozenException extends RuntimeException implements ZipStreamExceptionInterface
{
    public static function forContentLength(string $destination): never
    {
        throw new static(
            "[$destination] cannot be added: the archive has already been measured for its Content-Length, "
            . 'and the response would then send a length that disagrees with the body. Add every entry before '
            . 'calling toResponse(), or drop withContentLength().',
        );
    }
}
