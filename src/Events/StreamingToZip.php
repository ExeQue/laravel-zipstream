<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * Before an entry - file or directory - is streamed into the archive.
 */
interface StreamingToZip extends LifecycleEvent
{
}
