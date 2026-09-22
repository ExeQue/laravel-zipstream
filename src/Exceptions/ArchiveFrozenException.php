<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Exceptions;

use RuntimeException;

class ArchiveFrozenException extends RuntimeException implements ZipStreamExceptionInterface
{
    public static function whileStreaming(string $destination): never
    {
        throw new static(
            "[$destination] cannot be added while the archive is being written: the entries were taken when "
            . 'it started, so this one would not reach the archive being produced - it would sit in the '
            . 'builder and turn up in whatever it is used for next.',
        );
    }

    public static function forContentLength(string $destination): never
    {
        throw new static(
            "[$destination] cannot be added: the archive has already been measured for its Content-Length, "
            . 'and the response would then send a length that disagrees with the body. Add every entry before '
            . 'calling toResponse(), or drop withContentLength().',
        );
    }
}
