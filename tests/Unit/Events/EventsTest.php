<?php

declare(strict_types=1);

use ExeQue\ZipStream\Pending;
use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Content\Raw;
use ExeQue\ZipStream\Events\EventType;
use Tests\Support\EventQueueSpy;
use ZipStream\ZipStream;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\FilesystemAdapter;
use Mockery;

covers(Pending::class);

describe('Event chains via Any listener', function () {
    it('pending: directory chain emits events in order and passes expected args (captured via Any)', function () {
        $pending = new Pending();
        $dir = Directory::make('dir1')->comment('dir comment');
        $file = Raw::make('file.txt', 'content');
        // Add directory then file (directory should stream first)
        $pending->add($dir);
        $pending->add($file);

        $spy = new Tests\Support\EventQueueSpy();

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addDirectory')->once();
        $stream->shouldReceive('addFileFromCallback')->once();

        $pending->process($stream, $spy);

        $spy->assertTypes([
                EventType::ProcessStarted,
                EventType::StreamingDirectory,
                EventType::StreamedDirectory,
                EventType::StreamingFile,
                EventType::StreamedFile,
                EventType::ProcessFinished,
            ])
            ->assertAt(0, function ($type, ...$args) {
                // ProcessStarted -> single id arg
                expect(count($args))->toBe(1)
                    ->and(is_string($args[0]))->toBeTrue();
            })
            ->assertAt(1, function ($type, ...$args) {
                // StreamingDirectory -> Directory, FileOptions, id
                expect(count($args))->toBe(3)
                    ->and($args[0])->toBeInstanceOf(Directory::class)
                    ->and($args[1])->toBeInstanceOf(\ExeQue\ZipStream\Options\FileOptions::class)
                    ->and(is_string($args[2]))->toBeTrue();
            })
            ->assertAt(3, function ($type, ...$args) {
                // StreamingFile -> Raw, FileOptions, id
                expect(count($args))->toBe(3)
                    ->and($args[0])->toBeInstanceOf(Raw::class)
                    ->and($args[1])->toBeInstanceOf(\ExeQue\ZipStream\Options\FileOptions::class)
                    ->and(is_string($args[2]))->toBeTrue();
            })
            ->assertAt(5, function ($type, ...$args) {
                // ProcessFinished
                expect(count($args))->toBe(1)
                    ->and(is_string($args[0]))->toBeTrue();
            });
    });

    it('pending: file chain emits events in order and passes expected args (captured via Any)', function () {
        $pending = new Pending();
        $dir = Directory::make('dir1')->comment('dir comment');
        $file = Raw::make('file.txt', 'content');

        // Add directory then file (directory should stream first)
        $pending->add($dir);
        $pending->add($file);

        $spy = new Tests\Support\EventQueueSpy();

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addDirectory')->once();
        $stream->shouldReceive('addFileFromCallback')->once();

        $pending->process($stream, $spy);

        $spy->assertTypes([
                EventType::ProcessStarted,
                EventType::StreamingDirectory,
                EventType::StreamedDirectory,
                EventType::StreamingFile,
                EventType::StreamedFile,
                EventType::ProcessFinished,
            ])
            ->assertAt(0, function ($type, ...$args) {
                expect(count($args))->toBe(1)
                    ->and(is_string($args[0]))->toBeTrue();
            })
            ->assertAt(1, function ($type, ...$args) {
                expect(count($args))->toBe(3)
                    ->and($args[0])->toBeInstanceOf(Directory::class)
                    ->and($args[1])->toBeInstanceOf(\ExeQue\ZipStream\Options\FileOptions::class)
                    ->and(is_string($args[2]))->toBeTrue();
            })
            ->assertAt(3, function ($type, ...$args) {
                expect(count($args))->toBe(3)
                    ->and($args[0])->toBeInstanceOf(Raw::class)
                    ->and($args[1])->toBeInstanceOf(\ExeQue\ZipStream\Options\FileOptions::class)
                    ->and(is_string($args[2]))->toBeTrue();
            })
            ->assertAt(5, function ($type, ...$args) {
                expect(count($args))->toBe(1)
                    ->and(is_string($args[0]))->toBeTrue();
            });
    });

    it('builder.saveToLocal: emits saving/process/streaming/finished/saved chains and args via Any', function () {
        $filesystem = Mockery::mock(Factory::class);
        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->andReturnUsing(fn ($k, $d = null) => $d);

        $spy = new Tests\Support\EventQueueSpy();
        $builder = new Builder($filesystem, $config, $spy);
        // Add directory then file (directory should stream first)
        $builder->emptyDirectory('dir1');
        $builder->fromRaw('hello.txt', 'Hello World!');

        $path = $this->createTestFile();

        // also register a no-op to mirror normal usage
        $builder->on(EventType::Any, fn (...$args) => null);

        $size = $builder->saveToLocal($path);
        expect($size)->toBeGreaterThan(0);

        // Assertions against spy calls: SavingToFilesystem, ProcessStarted, StreamingDirectory, StreamedDirectory, StreamingFile, StreamedFile, ProcessFinished, SavedToFilesystem
        $spy->assertTypes([
                EventType::SavingToFilesystem,
                EventType::ProcessStarted,
                EventType::StreamingDirectory,
                EventType::StreamedDirectory,
                EventType::StreamingFile,
                EventType::StreamedFile,
                EventType::ProcessFinished,
                EventType::SavedToFilesystem,
            ]);

        // SavingToFilesystem -> first call
        $spy->assertAt(0, function ($type, ...$args) use ($path) {
            // SavingToFilesystem call -> args: path, id
            expect($args[0])->toBe($path)
                ->and(is_string($args[1]))->toBeTrue();
        });

        // ProcessStarted -> single id
        $spy->assertAt(1, function ($type, ...$args) {
            expect(count($args))->toBe(1)
                ->and(is_string($args[0]))->toBeTrue();
        });

        // SavedToFilesystem -> last call
        $spy->assertAt(7, function ($type, ...$args) use ($path, $size) {
            // SavedToFilesystem -> args: path, size, id
            expect($args[0])->toBe($path)
                ->and($args[1])->toBe($size)
                ->and(is_string($args[2]))->toBeTrue();
        });
    });

    it('builder.saveToDisk: emits saving/process/streaming/finished/saved chains and args via Any', function () {
        $filesystem = Mockery::mock(Factory::class);
        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->andReturnUsing(fn ($k, $d = null) => $d);

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('writeStream')->once()->with('archive.zip', Mockery::any());

        $spy = new EventQueueSpy();
        $builder = new Builder($filesystem, $config, $spy);
        // Add directory then file (directory should stream first)
        $builder->emptyDirectory('dir1');
        $builder->fromRaw('hello.txt', 'Hello World!');

        $builder->on(EventType::Any, fn (...$args) => null);

        $size = $builder->saveToDisk($disk, 'archive.zip');
        expect($size)->toBeGreaterThan(0);

        // Assert types sequence and basic checks for specific calls
        $spy->assertTypes([
            EventType::SavingToDisk,
            EventType::ProcessStarted,
            EventType::StreamingDirectory,
            EventType::StreamedDirectory,
            EventType::StreamingFile,
            EventType::StreamedFile,
            EventType::ProcessFinished,
            EventType::SavedToDisk,
        ]);

        // SavingToDisk -> first call
        $spy->assertAt(0, function ($type, ...$args) {
            expect(count($args) >= 2)->toBeTrue()
                ->and($args[1])->toBe('archive.zip')
                ->and(is_string($args[count($args) - 1]))->toBeTrue();
        });

        // SavedToDisk -> last call
        $spy->assertAt(7, function ($type, ...$args) use ($size) {
            // args: disk, path, size, id
            expect($args[2])->toBe($size)
                ->and(is_string($args[3]))->toBeTrue();
        });

        // Ensure there is a ProcessStarted call with a single string id
        $spy->assertAt(1, function ($type, ...$args) {
            expect(count($args))->toBe(1)
                ->and(is_string($args[0]))->toBeTrue();
        });
    });

    it('builder.toResponse: emits streaming/processing/finished chains and args via Any when response is sent', function () {
        $filesystem = Mockery::mock(Factory::class);
        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->andReturnUsing(fn ($k, $d = null) => $d);

        $spy = new EventQueueSpy();
        $builder = new Builder($filesystem, $config, $spy);
        // Add directory then file (directory should stream first)
        $builder->emptyDirectory('dir1');
        $builder->fromRaw('hello.txt', 'Hello World!');

        // register a no-op Any listener to mirror usage
        $builder->on(EventType::Any, fn (...$args) => null);

        $response = $builder->toResponse(null);

        // Execute the streamed response callback to trigger streaming
        $response->sendContent();

        // Expect StreamingResponse -> ProcessStarted -> StreamingDirectory -> StreamedDirectory -> StreamingFile -> StreamedFile -> ProcessFinished -> StreamedResponse
        $spy->assertTypes([
            EventType::StreamingResponse,
            EventType::ProcessStarted,
            EventType::StreamingDirectory,
            EventType::StreamedDirectory,
            EventType::StreamingFile,
            EventType::StreamedFile,
            EventType::ProcessFinished,
            EventType::StreamedResponse,
        ]);

        // Basic argument checks
        $spy->assertAt(0, function ($type, ...$args) {
            expect(count($args))->toBe(1)
                ->and(is_string($args[0]))->toBeTrue();
        });

        $spy->assertAt(7, function ($type, ...$args) {
            expect(count($args))->toBe(1)
                ->and(is_string($args[0]))->toBeTrue();
        });
    });
});
