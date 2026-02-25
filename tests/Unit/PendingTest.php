<?php

declare(strict_types=1);

use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Contracts\CanStreamToZip;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Options\FileOptions;
use ExeQue\ZipStream\Pending;
use Tests\Support\Invader;
use ZipStream\CompressionMethod;
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
});
