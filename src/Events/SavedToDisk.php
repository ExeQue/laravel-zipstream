<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use Illuminate\Filesystem\FilesystemAdapter;

/**
 * After the archive has been written to a Laravel disk.
 */
final readonly class SavedToDisk implements LifecycleEvent
{
    public function __construct(
        public string $id,
        public FilesystemAdapter $disk,
        public string $path,
        public ?int $size,
    ) {
    }
}
