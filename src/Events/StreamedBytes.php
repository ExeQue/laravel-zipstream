<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * Bytes of the archive itself, every time they are written. Fires at PHP's 8 KB write size, so throttle a handler doing real work.
 */
final readonly class StreamedBytes implements ProgressEvent
{
    public function __construct(
        public string $id,
        public int $written,
        public int $total,
    ) {
    }
}
