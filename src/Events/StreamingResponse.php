<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * Before the archive is streamed to the browser.
 */
final readonly class StreamingResponse implements LifecycleEvent
{
    public function __construct(
        public string $id,
    ) {
    }
}
