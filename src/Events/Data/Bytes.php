<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events\Data;

use Illuminate\Support\Number;

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

    /**
     * What has been written, against the total where one is known: "5.00 KB of 64.00 MB".
     *
     * Without a total there is no sentence to build, so it is the size on its own.
     *
     * @param  int|null  $precision  Decimals to render. Defaults to the size_precision config value.
     */
    public function toHuman(?int $precision = null): string
    {
        if ($this->total === null) {
            return $this->doneToHuman($precision);
        }

        return trans('laravel-zipstream::progress.bytes', [
            'done'  => $this->doneToHuman($precision),
            'total' => $this->totalToHuman($precision),
        ]);
    }

    /**
     * Reduced to the largest unit it fits in: 5120 becomes "5.00 KB".
     */
    public function doneToHuman(?int $precision = null): string
    {
        return Number::fileSize($this->done, $this->precision($precision));
    }

    public function totalToHuman(?int $precision = null): ?string
    {
        return $this->total === null ? null : Number::fileSize($this->total, $this->precision($precision));
    }

    private function precision(?int $precision): int
    {
        return $precision ?? (int) config('laravel-zipstream.size_precision', 2);
    }

    /**
     * "42%", or null while the total is unknown.
     */
    public function percentageToHuman(int $precision = 0): ?string
    {
        $percentage = $this->percentage();

        return $percentage === null ? null : Number::percentage($percentage, $precision);
    }
}
