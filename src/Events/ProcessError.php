<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\Data\Context;
use Throwable;

/**
 * When streaming an entry throws. Without a handler for it, the exception is thrown instead.
 */
final readonly class ProcessError implements LifecycleEvent
{
    public function __construct(
        public Context $context,
        public Throwable $exception,
    ) {
    }
}
