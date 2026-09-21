<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Before the archive is written to a Laravel disk.
 */
final readonly class SavingToDisk implements LifecycleEvent
{
    public function __construct(
        public string $id,
        public FilesystemAdapter $disk,
        public string $path,
    ) {
    }
}
