<?php

declare(strict_types=1);

use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Content\Raw;
use ExeQue\ZipStream\Contracts\CanStreamToZip;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Events\EventQueue;
use ExeQue\ZipStream\Events\EventType;
use ExeQue\ZipStream\Options\FileOptions;
use ExeQue\ZipStream\Options\ZipOptions;
use ExeQue\ZipStream\Pending;
use Tests\Support\Invader;
use ZipStream\CompressionMethod;
use ZipStream\Exception\FileSizeIncorrectException;
use ZipStream\ZipStream;

covers(Pending::class);

describe(Pending::class, function () {
    it('can add a StreamableToZip entry', function () {
        $pending = new Pending();
        $entry = Mockery::mock(StreamableToZip::class);
        $entry->shouldReceive('destination')->andReturn('test.txt');

        $pending->add($entry);

        $entries = Invader::make($pending)->entries;

        expect($entries)->toContain($entry);
    });

    it('can add a Directory entry', function () {
        $pending = new Pending();
        $directory = Directory::make('test-dir');

        $pending->add($directory);

        $entries = Invader::make($pending)->entries;

        expect($entries)->toContain($directory);
    });

    it('can add a CanStreamToZip entry', function () {
        $pending = new Pending();
        $entry = Mockery::mock(StreamableToZip::class);
        $entry->shouldReceive('destination')->andReturn('nested.txt');

        $canStream = Mockery::mock(CanStreamToZip::class);
        $canStream->shouldReceive('getStreamableToZip')->andReturn($entry);

        $pending->add($canStream);

        $entries = Invader::make($pending)->entries;

        expect($entries)->toContain($entry);
    });

    it('can add multiple entries from CanStreamToZip', function () {
        $pending = new Pending();
        $entry1 = Mockery::mock(StreamableToZip::class);
        $entry1->shouldReceive('destination')->andReturn('file1.txt');
        $entry2 = Mockery::mock(StreamableToZip::class);
        $entry2->shouldReceive('destination')->andReturn('file2.txt');

        $canStream = Mockery::mock(CanStreamToZip::class);
        $canStream->shouldReceive('getStreamableToZip')->andReturn([$entry1, $entry2]);

        $pending->add($canStream);

        $entries = Invader::make($pending)->entries;

        expect($entries)->toHaveCount(2)
            ->toContain($entry1)
            ->toContain($entry2);
    });

    it('verifies entry if it implements Verifiable', function () {
        $pending = new Pending();
        $entry = Mockery::mock(StreamableToZip::class, Verifiable::class);
        $entry->shouldReceive('destination')->andReturn('verifiable.txt');
        $entry->shouldReceive('verify')->once();

        $pending->add($entry);
    });

    it('does not verify entry if withoutVerification is called', function () {
        $pending = new Pending();
        $pending->withoutVerification();

        $entry = Mockery::mock(StreamableToZip::class, Verifiable::class);
        $entry->shouldReceive('destination')->andReturn('verifiable.txt');
        $entry->shouldReceive('verify')->never();

        $pending->add($entry);
    });

    it('withoutVerification returns the same instance', function () {
        $pending = new Pending();

        expect($pending->withoutVerification())->toBe($pending);
    });

    it('processes directory entries', function () {
        $pending = new Pending();
        $directory = Directory::make('dir1')->comment('dir comment');
        $pending->add($directory);

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addDirectory')->once()->with(
            'dir1',
            'dir comment',
            null,
        );

        $pending->process($stream);
    });

    it('processes file entries', function () {
        $pending = new Pending();
        $file = Mockery::mock(StreamableToZip::class);
        $file->shouldReceive('destination')->andReturn('file.txt');
        $file->shouldReceive('stream')->andReturn('content');
        $pending->add($file);

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addFileFromCallback')->once()->with(
            'file.txt',
            Mockery::on(fn ($cb) => $cb() === 'content'),
            '',
            null,
            null,
            null,
            null,
            null,
            null,
        );

        $pending->process($stream);
    });

    it('processes file entries with options', function () {
        $pending = new Pending();
        $file = Mockery::mock(StreamableToZip::class, HasFileOptions::class);
        $file->shouldReceive('destination')->andReturn('file-with-options.txt');
        $file->shouldReceive('stream')->andReturn('content');

        $options = new FileOptions(
            comment: 'file comment',
            compressionMethod: CompressionMethod::STORE,
            deflateLevel: 0,
            lastModified: new DateTimeImmutable('2023-01-01'),
            enableZeroHeader: true,
        );
        $file->shouldReceive('getFileOptions')->andReturn($options);

        $pending->add($file);

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addFileFromCallback')->once()->with(
            'file-with-options.txt',
            Mockery::on(fn ($cb) => $cb() === 'content'),
            'file comment',
            CompressionMethod::STORE,
            0,
            $options->lastModified,
            null,
            null,
            true,
        );

        $pending->process($stream);
    });

    it('forwards size limits for stored entries', function () {
        $pending = new Pending();
        $file = Mockery::mock(StreamableToZip::class, HasFileOptions::class);
        $file->shouldReceive('destination')->andReturn('stored.txt');
        $file->shouldReceive('stream')->andReturn('content');
        $file->shouldReceive('getFileOptions')->andReturn(new FileOptions(
            compressionMethod: CompressionMethod::STORE,
            maxSize: 100,
            exactSize: 7,
        ));
        $pending->add($file);

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addFileFromCallback')->once()->with(
            'stored.txt',
            Mockery::any(),
            '',
            CompressionMethod::STORE,
            null,
            null,
            100,
            7,
            null,
        );

        $pending->process($stream);
    });

    it('drops size limits for deflated entries', function () {
        $pending = new Pending();
        $file = Mockery::mock(StreamableToZip::class, HasFileOptions::class);
        $file->shouldReceive('destination')->andReturn('deflated.txt');
        $file->shouldReceive('stream')->andReturn('content');
        $file->shouldReceive('getFileOptions')->andReturn(new FileOptions(
            compressionMethod: CompressionMethod::DEFLATE,
            maxSize: 100,
            exactSize: 7,
        ));
        $pending->add($file);

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addFileFromCallback')->once()->with(
            'deflated.txt',
            Mockery::any(),
            '',
            CompressionMethod::DEFLATE,
            null,
            null,
            null,
            null,
            null,
        );

        $pending->process($stream);
    });

    it('falls back to the archive compression method when deciding on size limits', function () {
        $pending = new Pending();
        $file = Mockery::mock(StreamableToZip::class, HasFileOptions::class);
        $file->shouldReceive('destination')->andReturn('inherited.txt');
        $file->shouldReceive('stream')->andReturn('content');
        $file->shouldReceive('getFileOptions')->andReturn(new FileOptions(exactSize: 7));
        $pending->add($file);

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addFileFromCallback')->once()->with(
            'inherited.txt',
            Mockery::any(),
            '',
            null,
            null,
            null,
            null,
            7,
            null,
        );

        $zipOptions = Mockery::mock(ZipOptions::class);
        $zipOptions->compressionMethod = CompressionMethod::STORE;

        $pending->process($stream, new EventQueue(), $zipOptions);
    });

    it('routes a short read to ProcessError when exactSize is declared', function () {
        $pending = new Pending();
        $file = Raw::make('short.txt', 'content')->store()->exactSize(999);
        $pending->add($file);

        $zipOptions = Mockery::mock(ZipOptions::class);
        $zipOptions->compressionMethod = CompressionMethod::STORE;

        $errors = [];
        $events = new EventQueue();
        $events->add(EventType::ProcessError, function (Throwable $e) use (&$errors) {
            $errors[] = $e;
        });

        $stream = new ZipStream(
            outputStream: fopen('php://memory', 'w+b'),
            sendHttpHeaders: false,
        );

        $pending->process($stream, $events, $zipOptions);

        expect($errors)->toHaveCount(1)
            ->and($errors[0])->toBeInstanceOf(FileSizeIncorrectException::class);
    });

    it('can handle recursive CanStreamToZip entries', function () {
        $pending = new Pending();

        $file = Mockery::mock(StreamableToZip::class);
        $file->shouldReceive('destination')->andReturn('recursive.txt');

        $canStreamInner = Mockery::mock(CanStreamToZip::class);
        $canStreamInner->shouldReceive('getStreamableToZip')->andReturn($file);

        $canStreamOuter = Mockery::mock(CanStreamToZip::class);
        $canStreamOuter->shouldReceive('getStreamableToZip')->andReturn($canStreamInner);

        $pending->add($canStreamOuter);

        $entries = Invader::make($pending)->entries;

        expect($entries)->toContain($file);
    });

    it('rethrows an exception from stream() when no ProcessError handler is registered', function () {
        $pending = new Pending();
        $file = Mockery::mock(StreamableToZip::class);
        $file->shouldReceive('destination')->andReturn('file.txt');
        $file->shouldReceive('stream')->andThrow(new RuntimeException('boom'));
        $pending->add($file);

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addFileFromCallback')->once()->andReturnUsing(
            fn ($fileName, $callback) => $callback(),
        );

        expect(fn () => $pending->process($stream))->toThrow(RuntimeException::class, 'boom');
    });

    it('dispatches ProcessError to a registered handler and continues when it does not throw', function () {
        $pending = new Pending();

        $failing = Mockery::mock(StreamableToZip::class);
        $failing->shouldReceive('destination')->andReturn('failing.txt');
        $failing->shouldReceive('stream')->andThrow(new RuntimeException('boom'));
        $pending->add($failing);

        $ok = Mockery::mock(StreamableToZip::class);
        $ok->shouldReceive('destination')->andReturn('ok.txt');
        $ok->shouldReceive('stream')->andReturn('content');
        $pending->add($ok);

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addFileFromCallback')->twice()->andReturnUsing(
            fn ($fileName, $callback) => $callback(),
        );

        $events = new EventQueue();
        $caught = null;
        $events->add(EventType::ProcessError, function (Throwable $e) use (&$caught) {
            $caught = $e;
        });

        $pending->process($stream, $events);

        expect($caught)->toBeInstanceOf(RuntimeException::class)
            ->and($caught->getMessage())->toBe('boom');
    });

    it('propagates the exception when the ProcessError handler rethrows', function () {
        $pending = new Pending();
        $file = Mockery::mock(StreamableToZip::class);
        $file->shouldReceive('destination')->andReturn('file.txt');
        $file->shouldReceive('stream')->andThrow(new RuntimeException('boom'));
        $pending->add($file);

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addFileFromCallback')->once()->andReturnUsing(
            fn ($fileName, $callback) => $callback(),
        );

        $events = new EventQueue();
        $events->add(EventType::ProcessError, function (Throwable $e) {
            throw $e;
        });

        expect(fn () => $pending->process($stream, $events))->toThrow(RuntimeException::class, 'boom');
    });

    it('stops processing when connection is aborted and option enabled', function () {
        require_once __DIR__ . '/../Support/connection_abort.php';
        $GLOBALS['__zipstream_connection_aborted_override'] = true;

        $pending = new Pending();
        $pending->stopOnConnectionAborted();

        $directory = Directory::make('dir')->comment('dir comment');
        $pending->add($directory);

        $file = Mockery::mock(StreamableToZip::class);
        $file->shouldReceive('destination')->andReturn('file.txt');
        $file->shouldReceive('stream')->andReturn('content');
        $pending->add($file);

        $stream = Mockery::mock(ZipStream::class);
        $stream->shouldReceive('addDirectory')->never();
        $stream->shouldReceive('addFileFromCallback')->never();

        $pending->process($stream);

        unset($GLOBALS['__zipstream_connection_aborted_override']);
    });
});
