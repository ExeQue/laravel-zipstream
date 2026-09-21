<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events\Data;

/**
 * How much of the archive has been written, as it stood when the event was dispatched.
 *
 * The total is null unless the size was worked out up front - see Builder::withKnownSize().
 */
final readonly class Bytes
{
    public function __construct(
        public int $done = 0,
        public ?int $total = null,
    ) {
    }

    /**
     * How far along, 0.0 to 100.0, or null while the total is unknown.
     */
    public function percentage(): ?float
    {
        if ($this->total === null || $this->total === 0) {
            return null;
        }

        return min(100.0, $this->done / $this->total * 100);
    }

    /**
     * False while the total is unknown: an archive that might still grow is not finished.
     */
    public function isComplete(): bool
    {
        return $this->total !== null && $this->done >= $this->total;
    }
}
