<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Events\Contracts\ProgressEvent;
use ExeQue\ZipStream\Events\Data\Context;

/**
 * Bytes of the archive since the previous report.
 *
 * The archive so far, and its total where one is known, are on $context->bytes. Reports are
 * throttled - see progressEveryBytes() and progressEveryInterval().
 */
final readonly class StreamedBytes implements ProgressEvent
{
    public function __construct(
        public Context $context,
        public int $written,
    ) {
    }
}
