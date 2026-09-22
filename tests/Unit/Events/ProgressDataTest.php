<?php

declare(strict_types=1);

use ExeQue\ZipStream\Events\Data\Bytes;
use ExeQue\ZipStream\Events\Data\Entries;

covers(Entries::class);
covers(Bytes::class);

describe(Entries::class, function () {
    it('always has a percentage, since the total is counted up front', function () {
        expect((new Entries(1, 4))->percentage())->toBe(25.0)
            ->and((new Entries(4, 4))->percentage())->toBe(100.0);
    });

    it('reads as how many of how many files', function () {
        expect((new Entries(10, 125))->toHuman())->toBe('10 of 125 files')
            ->and((new Entries(1, 1))->toHuman())->toBe('1 of 1 file')
            ->and((new Entries(10, 125))->percentageToHuman())->toBe('8%')
            ->and((new Entries(10, 125))->percentageToHuman(1))->toBe('8.0%');
    });

    it('renders in the application locale', function () {
        app()->setLocale('da');

        // The sentence is translated; the numbers follow Laravel's Number locale, which the
        // application sets for itself with Number::useLocale().
        expect((new Entries(10, 125))->toHuman())->toBe('10 af 125 filer')
            ->and((new Bytes(5120, 67108864))->toHuman())->toBe('5.00 KB af 64.00 MB');

        app()->setLocale('en');
    });

    it('calls an archive with no entries done', function () {
        $progress = new Entries();

        expect($progress->percentage())->toBe(100.0)
            ->and($progress->isComplete())->toBeTrue();
    });
});

describe(Bytes::class, function () {
    it('has no percentage while the total is unknown', function () {
        $progress = new Bytes(1024);

        expect($progress->total)->toBeNull()
            ->and($progress->percentage())->toBeNull()
            // An archive that might still grow is not finished.
            ->and($progress->isComplete())->toBeFalse();
    });

    it('reduces bytes to the largest unit they fit in', function () {
        expect((new Bytes(5120, 67108864))->toHuman())->toBe('5.00 KB of 64.00 MB')
            // Without a total there is nothing to compare against.
            ->and((new Bytes(5120))->toHuman())->toBe('5.00 KB')
            ->and((new Bytes(5120))->totalToHuman())->toBeNull()
            ->and((new Bytes(1536, 4096))->toHuman(0))->toBe('2 KB of 4 KB')
            ->and((new Bytes(1536, 4096))->toHuman(1))->toBe('1.5 KB of 4.0 KB');
    });

    it('takes the default precision from the config', function () {
        config()->set('laravel-zipstream.size_precision', 0);

        expect((new Bytes(5120, 67108864))->toHuman())->toBe('5 KB of 64 MB')
            // An explicit precision still wins.
            ->and((new Bytes(5120, 67108864))->toHuman(2))->toBe('5.00 KB of 64.00 MB');

        config()->set('laravel-zipstream.size_precision', 2);
    });

    it('renders a percentage only once the total is known', function () {
        expect((new Bytes(512, 2048))->percentageToHuman())->toBe('25%')
            ->and((new Bytes(512, 2048))->percentageToHuman(1))->toBe('25.0%')
            ->and((new Bytes(512))->percentageToHuman())->toBeNull();
    });

    it('has a percentage once the size is known', function () {
        expect((new Bytes(512, 2048))->percentage())->toBe(25.0)
            ->and((new Bytes(2048, 2048))->isComplete())->toBeTrue();
    });
});
