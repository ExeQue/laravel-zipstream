<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\Data\Context;

/**
 * Before the entries are streamed into the archive.
 */
final readonly class ProcessStarted implements LifecycleEvent
{
    public function __construct(
        public Context $context,
    ) {
    }
}
