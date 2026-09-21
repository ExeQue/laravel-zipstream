<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events\Contracts;

/**
 * After an entry - file or directory - has been streamed into the archive.
 */
interface StreamedToZip extends LifecycleEvent
{
}
