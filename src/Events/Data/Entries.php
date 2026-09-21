<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events\Data;

/**
 * How many entries of the archive are done, as it stood when the event was dispatched.
 *
 * The total is counted before the first byte is written, so it is always known.
 */
final readonly class Entries
{
    public function __construct(
        public int $done = 0,
        public int $total = 0,
    ) {
    }

    /**
     * How far along, 0.0 to 100.0. An archive with no entries is done before it starts.
     */
    public function percentage(): float
    {
        if ($this->total === 0) {
            return 100.0;
        }

        return min(100.0, $this->done / $this->total * 100);
    }

    public function isComplete(): bool
    {
        return $this->done >= $this->total;
    }
}
