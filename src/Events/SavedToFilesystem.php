<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * After the archive has been written to a local path.
 */
final readonly class SavedToFilesystem implements LifecycleEvent
{
    public function __construct(
        public string $id,
        public string $path,
        public ?int $size,
    ) {
    }
}
