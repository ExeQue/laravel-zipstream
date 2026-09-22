<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Exceptions;

use ExeQue\ZipStream\Events\Contracts\Event;
use InvalidArgumentException;

class InvalidEventHandlerException extends InvalidArgumentException implements ZipStreamExceptionInterface
{
    public static function forUntyped(): never
    {
        throw new static(
            sprintf(
                'An event handler declares what it listens for: type-hint its first parameter with %s, '
                . 'one of its implementations, or a union of them.',
                Event::class,
            ),
        );
    }

    public static function forType(string $type): never
    {
        throw new static("[$type] is not an event type. Expected " . Event::class . ' or an implementation of it.');
    }
}
