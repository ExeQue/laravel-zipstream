<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * Before the entries are streamed into the archive.
 */
final readonly class ProcessStarted implements LifecycleEvent
{
    public function __construct(
        public string $id,
    ) {
    }
}
