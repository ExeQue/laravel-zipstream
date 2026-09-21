<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\Data\Context;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * After the archive has been written to a Laravel disk.
 */
final readonly class SavedToDisk implements LifecycleEvent
{
    public function __construct(
        public Context $context,
        public FilesystemAdapter $disk,
        public string $path,
        public ?int $size,
    ) {
    }
}
