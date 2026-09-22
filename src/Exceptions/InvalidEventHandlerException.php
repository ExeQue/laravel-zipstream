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

    public static function forUnresolvable(string $parameter): never
    {
        throw new static(
            "The handler's [$parameter] parameter has no type the container can build, and no default. "
            . 'After the event, a handler may ask for the archive or for anything the container resolves.',
        );
    }

    public static function forContainerOutsideBuilder(string $class): never
    {
        throw new static(
            "This handler asked for [$class], but the events it listens to were dispatched by a queue with "
            . 'no container to resolve it from. Only a queue built by the application has one.',
        );
    }

    public static function forArchiveOutsideBuilder(): never
    {
        throw new static(
            'This handler asked for the archive, but the events it listens to were dispatched without one. '
            . 'Only a queue handed to a Builder can supply it.',
        );
    }

    public static function forType(string $type): never
    {
        throw new static("[$type] is not an event type. Expected " . Event::class . ' or an implementation of it.');
    }
}
