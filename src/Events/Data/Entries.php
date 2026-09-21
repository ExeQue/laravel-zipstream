<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events\Data;

use Illuminate\Support\Number;

/**
 * How many entries of the archive are done, as it stood when the event was dispatched.
 *
 * The total is counted before the first byte is written, so it is always known. It counts entries in
 * the archive rather than whatever they were built from: an archive that adds a caption file beside
 * each photo has two entries per photo, which is worth knowing before showing the number to a user.
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

    /**
     * How many of how many: "10 of 125 files".
     */
    public function toHuman(): string
    {
        return trans_choice('laravel-zipstream::progress.entries', $this->total, [
            'done'  => Number::format($this->done),
            'total' => Number::format($this->total),
        ]);
    }

    /**
     * "8%". Always a value, since the total is counted before the first byte.
     */
    public function percentageToHuman(int $precision = 0): string
    {
        return Number::percentage($this->percentage(), $precision);
    }
}
