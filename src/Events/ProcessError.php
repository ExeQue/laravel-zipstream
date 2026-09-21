<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use Throwable;

/**
 * When streaming an entry throws. Without a handler for it, the exception is thrown instead.
 */
final readonly class ProcessError implements LifecycleEvent
{
    public function __construct(
        public string $id,
        public Throwable $exception,
    ) {
    }
}
