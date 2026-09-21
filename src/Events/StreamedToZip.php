<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

/**
 * After an entry - file or directory - has been streamed into the archive.
 */
interface StreamedToZip extends LifecycleEvent
{
}
