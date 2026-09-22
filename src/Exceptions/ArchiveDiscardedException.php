<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Exceptions;

use RuntimeException;

/**
 * Thrown inside the package when abort(discard: true) is called, to unwind the archive.
 *
 * Every destination catches it, cleans up what it had started and returns null, so it does not reach
 * the caller - it is a way of leaving, not a failure to report.
 */
class ArchiveDiscardedException extends RuntimeException implements ZipStreamExceptionInterface
{
    public static function make(): self
    {
        return new self('The archive was discarded.');
    }
}
