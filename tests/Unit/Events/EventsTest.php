<?php

declare(strict_types=1);

use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Content\Raw;
use ExeQue\ZipStream\Events\Contracts\Event;
use ExeQue\ZipStream\Events\Contracts\StreamedToZip;
use ExeQue\ZipStream\Events\Data\Context;
use ExeQue\ZipStream\Events\ProcessFinished;
use ExeQue\ZipStream\Events\ProcessStarted;
use ExeQue\ZipStream\Events\SavedToDisk;
use ExeQue\ZipStream\Events\SavedToFilesystem;
use ExeQue\ZipStream\Events\SavingToDisk;
use ExeQue\ZipStream\Events\SavingToFilesystem;
use ExeQue\ZipStream\Events\StreamedDirectory;
use ExeQue\ZipStream\Events\StreamedFile;
use ExeQue\ZipStream\Events\StreamedResponse;
use ExeQue\ZipStream\Events\StreamingDirectory;
use ExeQue\ZipStream\Events\StreamingFile;
use ExeQue\ZipStream\Events\StreamingResponse;
use ExeQue\ZipStream\Options\FileOptions;
use ExeQue\ZipStream\Pending;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use Tests\Support\EventQueueSpy;
use ZipStream\ZipStream;

covers(Pending::class);

/** The entry chain every destination wraps its own events around. */
$entryChain = [
    ProcessStarted::class,
    StreamingDirectory::class,
    StreamedDirectory::class,
    StreamingFile::class,
    StreamedFile::class,
    ProcessFinished::class,
];

function builderWithSpy(EventQueueSpy $spy): Builder
{
    $config = Mockery::mock(Repository::class);
    $config->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);

    return (new Builder(Mockery::mock(Factory::class), $config, $spy))
        ->emptyDirectory('dir1')
        ->fromRaw('hello.txt', 'Hello World!');
}

describe('Event chains', function () use ($entryChain) {
    it('emits the entry chain in order, carrying the entry and its options', function () use ($entryChain) {
        $pending = new Pending();
        $pending->add(Directory::make('dir1')->comment('dir comment'));
        $pending->add(Raw::make('file.txt', 'content'));

        $spy = new EventQueueSpy();

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addDirectory')->once();
        $stream->shouldReceive('addFileFromCallback')->once();

        $pending->process($stream, $spy);

        $spy->assertTypes($entryChain)
            ->assertAt(1, function (StreamingDirectory $event) {
                expect($event->directory)->toBeInstanceOf(Directory::class)
                    ->and($event->options)->toBeInstanceOf(FileOptions::class);
            })
            ->assertAt(3, function (StreamingFile $event) {
                expect($event->file)->toBeInstanceOf(Raw::class)
                    ->and($event->options)->toBeInstanceOf(FileOptions::class);
            });
    });

    it('hands every event of an archive the same id, in its own snapshot', function () {
        $pending = new Pending();
        $pending->add(Raw::make('file.txt', 'content'));

        $spy = new EventQueueSpy();

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addFileFromCallback')->once();

        $pending->process($stream, $spy);

        $contexts = array_map(fn (Event $event) => $event->context, $spy->events());
        $ids = array_map(fn (Context $context) => $context->id, $contexts);

        expect(array_unique($ids))->toHaveCount(1)
            ->and($ids[0])->toBe($spy->context()->id)
            // Each event holds its own frozen copy, so a handler can keep it.
            ->and($contexts[0])->not->toBe($contexts[1]);
    });

    it('freezes the context it hands out', function () {
        $pending = new Pending();
        $pending->add(Raw::make('file.txt', 'content'));

        $spy = new EventQueueSpy();

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addFileFromCallback')->once();

        $pending->process($stream, $spy);

        $context = $spy->events()[0]->context;

        expect(fn () => $context->entry = null)->toThrow(Error::class)
            ->and(fn () => $context->entries->done = 99)->toThrow(Error::class);
    });

    it('reports the entry being streamed and how far along the archive is', function () {
        $pending = new Pending();
        $pending->add(Directory::make('dir1'));
        $pending->add($file = Raw::make('file.txt', 'content'));

        $spy = new EventQueueSpy();
        $seen = [];

        $spy->add(function (StreamedToZip $event) use (&$seen) {
            $seen[] = [
                $event->context->entry?->destination(),
                $event->context->entries->done,
                $event->context->entries->total,
            ];
        });

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addDirectory')->once();
        $stream->shouldReceive('addFileFromCallback')->once();

        $pending->process($stream, $spy);

        expect($seen)->toBe([
            ['dir1', 1, 2],
            ['file.txt', 2, 2],
        ]);

        // Nothing is being written once the archive is done.
        expect($spy->context()->entry)->toBeNull();
    });

    it('carries the context set on an entry', function () {
        $pending = new Pending();
        $pending->add(Raw::make('file.txt', 'content')->context(['media' => 42]));

        $spy = new EventQueueSpy();
        $seen = null;

        $spy->add(function (StreamedFile $event) use (&$seen) {
            $seen = $event->context->entryData();
        });

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addFileFromCallback')->once();

        $pending->process($stream, $spy);

        expect($seen)->toBe(['media' => 42]);
    });

    it('wraps the entry chain in the filesystem events', function () use ($entryChain) {
        $spy = new EventQueueSpy();
        $builder = builderWithSpy($spy);

        $path = $this->createTestFile();
        $size = $builder->saveToLocal($path);

        $spy->assertTypes([SavingToFilesystem::class, ...$entryChain, SavedToFilesystem::class])
            ->assertAt(0, fn (SavingToFilesystem $event) => expect($event->path)->toBe($path))
            ->assertAt(7, function (SavedToFilesystem $event) use ($path, $size) {
                expect($event->path)->toBe($path)
                    ->and($event->size)->toBe($size);
            });
    });

    it('wraps the entry chain in the disk events', function () use ($entryChain) {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturnFalse();
        $disk->shouldReceive('writeStream')->once()->with('archive.zip', Mockery::any(), [])
            ->andReturnUsing(fn ($path, $handle) => stream_get_contents($handle) !== false);

        $spy = new EventQueueSpy();
        $size = builderWithSpy($spy)->saveToDisk($disk, 'archive.zip');

        $spy->assertTypes([SavingToDisk::class, ...$entryChain, SavedToDisk::class])
            ->assertAt(0, function (SavingToDisk $event) use ($disk) {
                expect($event->path)->toBe('archive.zip')
                    ->and($event->disk)->toBe($disk);
            })
            ->assertAt(7, function (SavedToDisk $event) use ($size) {
                expect($event->size)->toBe($size);
            });
    });

    it('announces the disk it resolved, not the name it was given', function () {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturnFalse();
        $disk->shouldReceive('writeStream')->once()
            ->andReturnUsing(fn ($path, $handle) => stream_get_contents($handle) !== false);

        $filesystem = Mockery::mock(Factory::class);
        $filesystem->shouldReceive('disk')->with('archives')->andReturn($disk);

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);

        $spy = new EventQueueSpy();

        (new Builder($filesystem, $config, $spy))
            ->fromRaw('hello.txt', 'Hello World!')
            ->saveToDisk('archives', 'archive.zip');

        $spy->assertAt(0, fn (SavingToDisk $event) => expect($event->disk)->toBe($disk));
    });

    it('wraps the entry chain in the response events', function () use ($entryChain) {
        $spy = new EventQueueSpy();

        $response = builderWithSpy($spy)->toResponse(null);

        captureStreamedOutput(fn () => $response->sendContent());

        $spy->assertTypes([StreamingResponse::class, ...$entryChain, StreamedResponse::class]);
    });
});
