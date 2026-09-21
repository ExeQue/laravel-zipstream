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

    it('has a percentage once the size is known', function () {
        expect((new Bytes(512, 2048))->percentage())->toBe(25.0)
            ->and((new Bytes(2048, 2048))->isComplete())->toBeTrue();
    });
});
