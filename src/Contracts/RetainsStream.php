<?php

namespace ExeQue\ZipStream\Contracts;

/**
 * Marks an entry whose stream() returns something the caller owns.
 *
 * By default the package closes the stream an entry hands it once that entry has been
 * written to the archive - it asked for the stream, so it is responsible for releasing it.
 * Implement this interface when stream() returns a resource that was opened elsewhere and
 * is still needed afterwards.
 */
interface RetainsStream
{
}
