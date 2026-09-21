<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\Data\Context;

/**
 * After every entry has been streamed into the archive.
 */
final readonly class ProcessFinished implements LifecycleEvent
{
    public function __construct(
        public Context $context,
    ) {
    }
}
