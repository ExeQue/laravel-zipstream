<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * When the archive was stopped early by abort() or a closed connection.
 */
final readonly class ProcessAborted implements LifecycleEvent
{
    public function __construct(
        public string $id,
    ) {
    }
}
