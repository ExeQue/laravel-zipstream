<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\Data\Context;

/**
 * Before the archive is written to a local path.
 */
final readonly class SavingToFilesystem implements LifecycleEvent
{
    public function __construct(
        public Context $context,
        public string $path,
    ) {
    }
}
