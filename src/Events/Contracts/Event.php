<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events\Contracts;

use ExeQue\ZipStream\Events\Data\Context;

/**
 * Anything the builder reports while it works.
 *
 * Handlers are registered by type-hinting what they want: on(fn (Event $e) => ...) sees everything,
 * a concrete class sees only that one, and a union sees each of them.
 */
interface Event
{
    public Context $context { get; }
}
