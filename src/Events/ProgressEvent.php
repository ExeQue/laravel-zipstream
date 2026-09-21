<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * Progress while the archive is written.
 *
 * Kept apart from LifecycleEvent because it fires at PHP's write size (8 KB): a handler on the
 * whole lifecycle should not be flooded by it.
 */
interface ProgressEvent extends Event
{
}
