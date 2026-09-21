<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * After the archive has been streamed to the browser.
 */
final readonly class StreamedResponse implements LifecycleEvent
{
    public function __construct(
        public string $id,
    ) {
    }
}
