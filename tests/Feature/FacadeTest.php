<?php

declare(strict_types=1);

use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Facades\Zip;

covers(Zip::class);

describe(Zip::class, function () {
    it('accesses the builder', function () {
        expect(Zip::getFacadeRoot())->toBeInstanceOf(Builder::class);
    });

    it('does not resolve a singleton', function () {
        expect(Zip::getFacadeRoot())->not->toBe(Zip::getFacadeRoot());
    });
});
