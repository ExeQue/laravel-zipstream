<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Options;

use DateInterval;
use DateTimeImmutable;
use ExeQue\ZipStream\Exceptions\InvalidProgressIntervalException;
use Throwable;

/**
 * How often progress is reported: every so many bytes, or every so much time.
 *
 * Only one of the two is ever set, so there is no question of which wins.
 */
final class ProgressInterval
{
    /** A second is a cadence a progress bar can use, whatever the archive is being written to. */
    public const DEFAULT = 'PT1S';

    /** PHP's write size: a smaller threshold reports on every write anyway, so it is a mistaken duration. */
    private const SUSPICIOUS_BELOW = 8192;

    private function __construct(
        public readonly int $bytes = 0,
        public readonly float $seconds = 0.0,
    ) {
    }

    /**
     * Report once this many bytes have been written. Zero reports every write.
     */
    public static function bytes(int $bytes): self
    {
        if ($bytes < 0) {
            InvalidProgressIntervalException::forNegativeBytes($bytes);
        }

        return new self(bytes: $bytes);
    }

    /**
     * Report at most this often.
     *
     * Takes a DateInterval, an ISO 8601 duration such as "PT1S", or a relative string such as
     * "500 milliseconds". ISO 8601 has no fractional seconds, so anything below a second has to be
     * written the relative way - or set on a DateInterval's $f directly.
     */
    public static function every(DateInterval|string $interval): self
    {
        if (is_string($interval)) {
            try {
                $interval = str_starts_with(strtoupper($interval), 'P')
                    ? new DateInterval($interval)
                    : DateInterval::createFromDateString($interval);
            } catch (Throwable) {
                InvalidProgressIntervalException::forDuration($interval);
            }
        }

        $now = new DateTimeImmutable('@0');

        return new self(seconds: (float) $now->add($interval)->format('U.u'));
    }

    /**
     * Read the configured value, which is bytes as a number and a duration as a string.
     */
    public static function fromConfig(mixed $value): self
    {
        if ($value === null) {
            return self::every(self::DEFAULT);
        }

        if (is_string($value) && !is_numeric($value)) {
            return self::every($value);
        }

        $bytes = (int) $value;

        // Catches 'progress_every' => 1 meant as one second: as bytes it reports every single write.
        if ($bytes > 0 && $bytes < self::SUSPICIOUS_BELOW) {
            InvalidProgressIntervalException::forAmbiguousConfig($value);
        }

        return self::bytes($bytes);
    }

    public function isTimeBased(): bool
    {
        return $this->seconds > 0.0;
    }
}
