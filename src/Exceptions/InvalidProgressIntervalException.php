<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Exceptions;

use InvalidArgumentException;

class InvalidProgressIntervalException extends InvalidArgumentException implements ZipStreamExceptionInterface
{
    public static function forNegativeBytes(int $bytes): never
    {
        throw new static("Progress cannot be reported every [$bytes] bytes.");
    }

    public static function forDuration(string $duration): never
    {
        throw new static("[$duration] is not a duration. Expected something like [PT1S] or [500 milliseconds].");
    }

    public static function forAmbiguousConfig(mixed $value): never
    {
        throw new static(
            sprintf(
                'The [progress_every] config value is a number of bytes, and [%s] of them reports every write. '
                . 'For a time interval, give a duration string such as [PT1S].',
                get_debug_type($value) === 'string' ? $value : var_export($value, true),
            ),
        );
    }
}
