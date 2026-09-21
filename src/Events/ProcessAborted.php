<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\Data\Context;

/**
 * When the archive was stopped early by abort() or a closed connection.
 */
final readonly class ProcessAborted implements LifecycleEvent
{
    public function __construct(
        public Context $context,
    ) {
    }
}
