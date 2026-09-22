<?php

declare(strict_types=1);

use ExeQue\ZipStream\Exceptions\InvalidProgressIntervalException;
use ExeQue\ZipStream\Options\ProgressInterval;

covers(ProgressInterval::class);

describe(ProgressInterval::class, function () {
    it('reads a number from the config as bytes', function () {
        $interval = ProgressInterval::fromConfig(4 * 1024 * 1024);

        expect($interval->bytes)->toBe(4 * 1024 * 1024)
            ->and($interval->isTimeBased())->toBeFalse();
    });

    it('reads a numeric string from the config as bytes', function () {
        // Anything coming from the environment is a string.
        expect(ProgressInterval::fromConfig('4194304')->bytes)->toBe(4 * 1024 * 1024);
    });

    it('reads a duration from the config as time', function () {
        $interval = ProgressInterval::fromConfig('PT1S');

        expect($interval->seconds)->toBe(1.0)
            ->and($interval->isTimeBased())->toBeTrue();
    });

    it('defaults to once per second', function () {
        $interval = ProgressInterval::fromConfig(null);

        expect($interval->seconds)->toBe(1.0)
            ->and($interval->isTimeBased())->toBeTrue();
    });

    it('allows zero, which reports every write', function () {
        $interval = ProgressInterval::fromConfig(0);

        expect($interval->bytes)->toBe(0)
            ->and($interval->isTimeBased())->toBeFalse();
    });

    it('accepts a threshold at PHP\'s write size', function () {
        expect(ProgressInterval::fromConfig(8192)->bytes)->toBe(8192);
    });

    it('rejects a small number, which is a duration in disguise', function () {
        // 'progress_every' => 1 almost certainly means one second, and as bytes it reports every write.
        expect(fn () => ProgressInterval::fromConfig(1))
            ->toThrow(InvalidProgressIntervalException::class, 'number of bytes')
            ->and(fn () => ProgressInterval::fromConfig(8191))
            ->toThrow(InvalidProgressIntervalException::class);
    });

    it('reads a relative duration, which can go below a second', function () {
        // ISO 8601 has no fractional seconds, so PT0.5S is not a thing.
        expect(ProgressInterval::fromConfig('500 milliseconds')->seconds)->toBe(0.5)
            ->and(ProgressInterval::fromConfig('250ms')->seconds)->toBe(0.25);
    });

    it('takes sub-second precision from a DateInterval', function () {
        $interval = new DateInterval('PT0S');
        $interval->f = 0.1;

        expect(ProgressInterval::every($interval)->seconds)->toBe(0.1);
    });

    it('rejects a string that is not a duration', function () {
        expect(fn () => ProgressInterval::fromConfig('every second'))
            ->toThrow(InvalidProgressIntervalException::class, 'not a duration');
    });

    it('rejects a negative byte count', function () {
        expect(fn () => ProgressInterval::bytes(-1))->toThrow(InvalidProgressIntervalException::class);
    });

    it('converts a longer duration to seconds', function () {
        expect(ProgressInterval::every('PT2M30S')->seconds)->toBe(150.0);
    });
});
