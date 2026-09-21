<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * A step of the archive: it starts, entries are streamed, it is saved.
 *
 * Fires a bounded number of times, so it is safe to hang real work off.
 */
interface LifecycleEvent extends Event
{
}
