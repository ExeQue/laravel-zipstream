<?php

declare(strict_types=1);

namespace Tests\Unit;

use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Content\DiskFile;
use ExeQue\ZipStream\Content\LocalFile;
use ExeQue\ZipStream\Content\Raw;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Mockery;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\AssertableZipFile;
use Tests\Support\Invader;
use ZipStream\CompressionMethod;

covers(Builder::class);

beforeEach(function () {
    $this->filesystemManager = Mockery::mock(Factory::class);
    $this->config = Mockery::mock(Repository::class);
    $this->config->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);
    $this->builder = new Builder(
        $this->filesystemManager,
        $this->config,
    );
});

describe(Builder::class, function () {
    it('can set the filename', function () {
        $this->builder->as('test.zip');

        expect(Invader::make($this->builder)->filename)->toBe('test.zip');
    });

    it('automatically adds .zip extension if missing', function () {
        $this->builder->as('test');

        expect(Invader::make($this->builder)->filename)->toBe('test.zip');
    });

    it('can add content via add()', function () {
        $raw = Raw::make('test.txt', 'content');
        $this->builder->add($raw);

        $pending = Invader::make($this->builder)->pending;
        $entries = Invader::make($pending)->entries;

        expect($entries)->toContain($raw);
    });

    it('can add content from disk', function () {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturnTrue();
        $disk->shouldReceive('allDirectories')->andReturn([]);
        $this->filesystemManager->shouldReceive('disk')->with('s3')->andReturn($disk);

        $this->builder->fromDisk('s3', 'path/to/file.txt', 'dest.txt');

        $pending = Invader::make($this->builder)->pending;
        $entries = Invader::make($pending)->entries;

        $exists = collect($entries)->contains(fn ($entry) => $entry instanceof DiskFile && $entry->destination() === 'dest.txt');
        expect($exists)->toBeTrue();
    });

    it('can add content from disk using default destination', function () {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturnTrue();
        $disk->shouldReceive('allDirectories')->andReturn([]);
        $this->filesystemManager->shouldReceive('disk')->with('s3')->andReturn($disk);

        $this->builder->fromDisk('s3', 'path/to/file.txt');

        $pending = Invader::make($this->builder)->pending;
        $entries = Invader::make($pending)->entries;

        $exists = collect($entries)->contains(fn ($entry) => $entry->destination() === 'file.txt');
        expect($exists)->toBeTrue();
    });

    it('can add content from local path using default destination', function () {
        $this->builder->fromLocal(__FILE__);

        $pending = Invader::make($this->builder)->pending;
        $entries = Invader::make($pending)->entries;

        $exists = collect($entries)->contains(fn ($entry) => $entry->destination() === basename(__FILE__));
        expect($exists)->toBeTrue();
    });

    it('can add content from local path', function () {
        $this->builder->fromLocal(__FILE__, 'test.php');

        $pending = Invader::make($this->builder)->pending;
        $entries = Invader::make($pending)->entries;

        $exists = collect($entries)->contains(fn ($entry) => $entry instanceof LocalFile && $entry->destination() === 'test.php');
        expect($exists)->toBeTrue();
    });

    it('can add content from string', function () {
        $this->builder->fromRaw('test.txt', 'hello world');

        $pending = Invader::make($this->builder)->pending;
        $entries = Invader::make($pending)->entries;

        $exists = collect($entries)->contains(fn ($entry) => $entry instanceof Raw && $entry->destination() === 'test.txt');
        expect($exists)->toBeTrue();
    });

    it('can add empty directory', function () {
        $this->builder->emptyDirectory('empty-dir');

        $pending = Invader::make($this->builder)->pending;
        $entries = Invader::make($pending)->entries;

        $exists = collect($entries)->contains(fn ($entry) => $entry instanceof Directory && $entry->destination() === 'empty-dir');
        expect($exists)->toBeTrue();
    });

    it('can set ZIP options fluently', function () {
        $this->builder
            ->compressionMethod(CompressionMethod::STORE)
            ->deflateLevel(9)
            ->zeroHeader(true);

        $options = $this->builder->getZipOptions();

        expect($options->compressionMethod)->toBe(CompressionMethod::STORE)
            ->and($options->deflateLevel)->toBe(9)
            ->and($options->enableZeroHeader)->toBeTrue();
    });

    it('can use store() and deflate() shortcuts', function () {
        $this->builder->store();
        expect($this->builder->getZipOptions()->compressionMethod)->toBe(CompressionMethod::STORE);

        $this->builder->deflate();
        expect($this->builder->getZipOptions()->compressionMethod)->toBe(CompressionMethod::DEFLATE);
    });

    it('can use zeroHeader shortcuts', function () {
        $this->builder->withoutZeroHeader();
        expect($this->builder->getZipOptions()->enableZeroHeader)->toBeFalse();

        $this->builder->withZeroHeader();
        expect($this->builder->getZipOptions()->enableZeroHeader)->toBeTrue();
    });

    it('can output as string', function () {
        $this->builder->fromRaw('test.txt', 'content');
        $output = $this->builder->output();

        expect($output)->toBeString()
            ->and($output)->not->toBeEmpty();
    });

    it('can output as stream', function () {
        $this->builder->fromRaw('test.txt', 'content');
        $output = $this->builder->output(true);

        expect($output)->toBeInstanceOf(StreamInterface::class);
    });

    it('can save to local path', function () {
        $path = $this->createTestFile();
        // We don't close the stream here because TestCase::tearDown will try to close it.
        // Also Builder::saveToLocal will open the file in 'w+b' mode which might conflict if already open on some OS,
        // but here we just want to ensure it works.

        $this->builder->fromRaw('test.txt', 'content');
        $size = $this->builder->saveToLocal($path);

        expect($size)->toBeGreaterThan(0)
            ->and(file_exists($path))->toBeTrue();
    });

    it('can save to disk', function () {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('writeStream')->once()->with('archive.zip', Mockery::any());

        $this->builder->fromRaw('test.txt', 'content');
        $size = $this->builder->saveToDisk($disk, 'archive.zip');

        expect($size)->toBeGreaterThan(0);
    });

    it('can return a response', function () {
        $this->builder->as('test.zip');
        $response = $this->builder->toResponse(null);

        expect($response)->toBeInstanceOf(StreamedResponse::class)
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="test.zip"')
            ->and($response->headers->get('Content-Type'))->toBe('application/x-zip');
    });

    it('streams the content of the response', function () {
        $this->builder
            ->as('test.zip')
            ->fromRaw('hello.txt', 'Hello World!');

        $response = $this->builder->toResponse(new Request());

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        expect($content)->not->toBeEmpty();

        // Verify the ZIP content
        $tmpFile = $this->createTestFile();
        file_put_contents($tmpFile, $content);

        $zip = new AssertableZipFile($tmpFile);

        $zip
            ->path('hello.txt')
            ->exists()
            ->contains('Hello World!');
    });
});
