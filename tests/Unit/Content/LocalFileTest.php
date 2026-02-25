<?php

declare(strict_types=1);

use ExeQue\ZipStream\Concerns\InteractsWithDestination;
use ExeQue\ZipStream\Concerns\InteractsWithFileOptions;
use ExeQue\ZipStream\Content\LocalFile;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Exceptions\FileNotFoundException;

covers(LocalFile::class);

describe(LocalFile::class, function () {
    arch('Implements interfaces')->expect(LocalFile::class)->toImplement([
        StreamableToZip::class,
        HasFileOptions::class,
        Verifiable::class,
    ]);

    arch('Uses traits')->expect(LocalFile::class)->toUseTraits([
        InteractsWithFileOptions::class,
        InteractsWithDestination::class,
    ]);

    it('sets the destination based on source if omitted', function () {
        $source = 'path/to/file.txt';

        $file = LocalFile::make($source);

        expect($file->destination())->toBe('file.txt');
    });

    it('uses the given destination', function () {
        $source = 'path/to/file.txt';
        $destination = 'foo.bar';

        $file = LocalFile::make($source, $destination);

        expect($file->destination())->toBe('foo.bar');
    });

    it('verifies the source path', function (string $source, bool $fail) {
        $file = LocalFile::make($source);

        if ($fail) {
            expect(static fn () => $file->verify())->toThrow(FileNotFoundException::class);
        } else {
            $file->verify();
            $this->expectNotToPerformAssertions();
        }
    })->with([
        'non existent file'  => [
            'source' => 'path/to/file.txt',
            'fail'   => true,
        ],
        'existing directory' => [
            'source' => __DIR__,
            'fail'   => true,
        ],
        'existing file'      => [
            'source' => __FILE__,
            'fail'   => false,
        ],
    ]);

    it('returns a stream resource', function () {
        $file = LocalFile::make(__FILE__);

        $output = $file->stream();

        expect($output)->toBeResource();
    });

    fileTests(fn () => LocalFile::make(__FILE__));
});
