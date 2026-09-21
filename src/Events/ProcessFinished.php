<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * After every entry has been streamed into the archive.
 */
final readonly class ProcessFinished implements LifecycleEvent
{
    public function __construct(
        public string $id,
    ) {
    }
}
