<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\Data\Context;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Before the archive is written to a Laravel disk.
 */
final readonly class SavingToDisk implements LifecycleEvent
{
    public function __construct(
        public Context $context,
        public FilesystemAdapter $disk,
        public string $path,
    ) {
    }
}
