<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * Before the archive is written to a local path.
 */
final readonly class SavingToFilesystem implements LifecycleEvent
{
    public function __construct(
        public string $id,
        public string $path,
    ) {
    }
}
