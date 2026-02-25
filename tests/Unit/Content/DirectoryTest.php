<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use DateTimeImmutable;
use ExeQue\ZipStream\Concerns\InteractsWithDestination;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Contracts\HasComment;
use ExeQue\ZipStream\Contracts\HasLastModified;

covers(Directory::class);

describe(Directory::class, function () {
    arch('Implements interfaces')->expect(Directory::class)->toImplement([
        HasLastModified::class,
        HasComment::class,
    ]);

    arch('Uses traits')->expect(Directory::class)->toUseTraits([
        InteractsWithDestination::class,
    ]);

    it('sets the destination', function () {
        $destination = 'path/to/directory';

        $directory = Directory::make($destination);

        expect($directory->destination())->toBe($destination);
    });

    it('can change the destination', function () {
        $directory = Directory::make('old/path');

        $directory->as('new/path');

        expect($directory->destination())->toBe('new/path');
    });

    it('can set the comment', function () {
        $directory = Directory::make('path');

        $directory->comment('Directory comment');

        expect($directory->getFileOptions()->comment)->toBe('Directory comment');
    });

    it('can set the last modified timestamp', function (mixed $input, DateTimeImmutable $expected) {
        $directory = Directory::make('path');

        $directory->lastModified($input);

        expect($directory->getFileOptions()->lastModified->format('U'))->toEqual($expected->format('U'));
    })->with('timestamps');
});
