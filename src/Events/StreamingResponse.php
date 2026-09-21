<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\Data\Context;

/**
 * Before the archive is streamed to the browser.
 */
final readonly class StreamingResponse implements LifecycleEvent
{
    public function __construct(
        public Context $context,
    ) {
    }
}
