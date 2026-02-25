<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Exceptions;

use Illuminate\Support\Arr;
use RuntimeException;

class UnsupportedInputException extends RuntimeException implements ZipStreamExceptionInterface
{
    public static function forRaw(string $destination, mixed $content): never
    {
        $type = get_debug_type($content);

        $expected = Arr::join(['string', 'resource', 'callable', 'StreamInterface'], ', ', ' or ');

        throw new static("Unsupported input type [$type] for [$destination]. Expected one of: $expected.");
    }
}
