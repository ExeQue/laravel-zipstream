<?php

declare(strict_types=1);

use ExeQue\ZipStream\Concerns\InteractsWithDestination;
use ExeQue\ZipStream\Concerns\InteractsWithFileOptions;
use ExeQue\ZipStream\Content\DiskFile;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Exceptions\FileNotFoundException;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use League\Flysystem\Filesystem;

covers(DiskFile::class);

beforeEach(function () {
    $adapter = new \League\Flysystem\Local\LocalFilesystemAdapter(__DIR__ . '/../../');

    $this->disk = new LocalFilesystemAdapter(
        new Filesystem($adapter),
        $adapter,
    );
});

describe(DiskFile::class, function () {
    arch('Implements interfaces')->expect(DiskFile::class)->toImplement([
        StreamableToZip::class,
        HasFileOptions::class,
        Verifiable::class,
    ]);

    arch('Uses traits')->expect(DiskFile::class)->toUseTraits([
        InteractsWithFileOptions::class,
        InteractsWithDestination::class,
    ]);

    it('sets the destination based on source if omitted', function () {
        $source = 'path/to/file.txt';

        $file = DiskFile::make($this->disk, $source);

        expect($file->destination())->toBe('file.txt');
    });

    it('uses the given destination', function () {
        $source = 'path/to/file.txt';
        $destination = 'foo.bar';

        $file = DiskFile::make($this->disk, $source, $destination);

        expect($file->destination())->toBe('foo.bar');
    });

    it('verifies the source path', function (string $source, bool $fail) {
        $file = DiskFile::make($this->disk, $source);

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
            'source' => 'Unit',
            'fail'   => true,
        ],
        'existing file'      => [
            'source' => 'Unit/Content/DiskFileTest.php',
            'fail'   => false,
        ],
    ]);

    it('returns a stream resource', function () {
        $file = DiskFile::make($this->disk, 'Unit/Content/DiskFileTest.php');

        expect($file->stream())->toBeResource();
    });

    fileTests(function ($self) {
        return DiskFile::make($self->disk, 'path/to/file.txt');
    });
});
