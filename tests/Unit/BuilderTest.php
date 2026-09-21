<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateInterval;
use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Content\DiskFile;
use ExeQue\ZipStream\Content\LocalFile;
use ExeQue\ZipStream\Content\Raw;
use ExeQue\ZipStream\Events\Contracts\Event;
use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\ProcessAborted;
use ExeQue\ZipStream\Events\StreamedBytes;
use ExeQue\ZipStream\Events\StreamingFile;
use ExeQue\ZipStream\Exceptions\FileUnavailableException;
use ExeQue\ZipStream\Exceptions\InvalidFilenameException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Mockery;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\AssertableZipFile;
use Tests\Support\Invader;
use ZipArchive;
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
        $disk->shouldReceive('fileExists')->andReturnTrue();
        $this->filesystemManager->shouldReceive('disk')->with('s3')->andReturn($disk);

        $this->builder->fromDisk('s3', 'path/to/file.txt', 'dest.txt');

        $pending = Invader::make($this->builder)->pending;
        $entries = Invader::make($pending)->entries;

        $exists = collect($entries)->contains(fn ($entry) => $entry instanceof DiskFile && $entry->destination() === 'dest.txt');
        expect($exists)->toBeTrue();
    });

    it('can add content from disk using default destination', function () {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('fileExists')->andReturnTrue();
        $this->filesystemManager->shouldReceive('disk')->with('s3')->andReturn($disk);

        $this->builder->fromDisk('s3', 'path/to/file.txt');

        $pending = Invader::make($this->builder)->pending;
        $entries = Invader::make($pending)->entries;

        $exists = collect($entries)->contains(fn ($entry) => $entry->destination() === 'file.txt');
        expect($exists)->toBeTrue();
    });

    it('adds every file under a local directory, sizes included', function () {
        $root = sys_get_temp_dir() . '/' . uniqid('zipdir');
        mkdir($root . '/nested', 0777, true);
        file_put_contents($root . '/a.txt', 'one');
        file_put_contents($root . '/nested/b.txt', 'two');

        $this->builder->fromLocalDirectory($root, 'files');

        $entries = collect(Invader::make($this->builder)->pending->entries());

        expect($entries->map(fn ($entry) => $entry->destination())->sort()->values()->all())
            ->toBe(['files/a.txt', 'files/nested/b.txt'])
            ->and($entries->every(fn ($entry) => $entry->getFileOptions()->exactSize > 0))->toBeTrue();

        // Not recursive: only the top level.
        $shallow = new Builder($this->filesystemManager, $this->config);
        $shallow->fromLocalDirectory($root, recursive: false);

        expect(Invader::make($shallow)->pending->entries())->toHaveCount(1);

        array_map('unlink', [$root . '/a.txt', $root . '/nested/b.txt']);
        rmdir($root . '/nested');
        rmdir($root);
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

    it('can set withoutVerification', function () {
        $this->builder->withoutVerification();

        $pending = Invader::make($this->builder)->pending;
        expect(Invader::make($pending)->verify)->toBeFalse();
    });

    it('withoutVerification returns the same instance', function () {
        expect($this->builder->withoutVerification())->toBe($this->builder);
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

        $path = $this->createTestFile();
        file_put_contents($path, $output->getContents());

        (new AssertableZipFile($path))->path('test.txt')->exists()->contains('content');
    });

    it('builds the output stream as it is read', function () {
        $source = $this->createTestFile();
        file_put_contents($source, random_bytes(1024 * 1024));

        for ($i = 0; $i < 64; $i++) {
            $this->builder->fromLocal($source, "file-$i.bin");
        }

        $output = $this->builder->store()->output(true);

        memory_reset_peak_usage();
        $baseline = memory_get_usage();
        $read = 0;

        while (! $output->eof()) {
            $read += strlen($output->read(256 * 1024));
        }

        expect($read)->toBeGreaterThan(64 * 1024 * 1024)
            ->and(memory_get_peak_usage() - $baseline)->toBeLessThan(8 * 1024 * 1024);
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
        $path = $this->createTestFile();

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturnFalse();
        $disk->shouldReceive('writeStream')
            ->once()
            ->with('archive.zip', Mockery::any(), ['part_size' => 1024])
            ->andReturnUsing(fn ($target, $handle) => stream_copy_to_stream($handle, fopen($path, 'w+b')) !== false);

        $this->builder->fromRaw('test.txt', 'content');
        $size = $this->builder->saveToDisk($disk, 'archive.zip', ['part_size' => 1024]);

        expect($size)->toBe(filesize($path));

        (new AssertableZipFile($path))->path('test.txt')->exists()->contains('content');
    });

    it('streams to disk without buffering the whole archive', function () {
        $source = $this->createTestFile();
        file_put_contents($source, random_bytes(1024 * 1024));

        for ($i = 0; $i < 64; $i++) {
            $this->builder->fromLocal($source, "file-$i.bin");
        }

        $peak = null;
        $read = 0;

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturnFalse();
        $disk->shouldReceive('writeStream')->once()->andReturnUsing(function ($target, $handle) use (&$peak, &$read) {
            memory_reset_peak_usage();
            $baseline = memory_get_usage();

            while (! feof($handle)) {
                $read += strlen(fread($handle, 256 * 1024));
            }

            $peak = memory_get_peak_usage() - $baseline;

            return true;
        });

        $size = $this->builder->store()->saveToDisk($disk, 'archive.zip');

        // Bounded by the chunk plus the rewindable head, not by the 64 MB archive.
        expect($size)->toBeGreaterThan(64 * 1024 * 1024)
            ->and($read)->toBe($size)
            ->and($peak)->toBeLessThan(16 * 1024 * 1024);
    });

    it('rethrows a failure while building and removes the partial archive', function () {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with('archive.zip')->andReturnFalse();
        $disk->shouldReceive('writeStream')->once()->andReturnUsing(function ($target, $handle) {
            // Mimic a disk that is not set to throw: the failure is swallowed.
            try {
                stream_get_contents($handle);
            } catch (\Throwable) {
                return false;
            }

            return true;
        });
        $disk->shouldReceive('delete')->once()->with('archive.zip');

        $source = tempnam(sys_get_temp_dir(), 'ziptest');
        $this->builder->fromRaw('first.txt', 'content')->fromLocal($source, 'gone.txt');
        unlink($source);

        expect(fn () => $this->builder->saveToDisk($disk, 'archive.zip'))->toThrow(FileUnavailableException::class);
    });

    it('keeps a file that was already on the disk when building fails', function () {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with('archive.zip')->andReturnTrue();
        $disk->shouldReceive('writeStream')->once()->andReturnUsing(function ($target, $handle) {
            try {
                stream_get_contents($handle);
            } catch (\Throwable) {
                return false;
            }

            return true;
        });
        $disk->shouldNotReceive('delete');

        $source = tempnam(sys_get_temp_dir(), 'ziptest');
        $this->builder->fromRaw('first.txt', 'content')->fromLocal($source, 'gone.txt');
        unlink($source);

        expect(fn () => $this->builder->saveToDisk($disk, 'archive.zip'))->toThrow(FileUnavailableException::class);
    });

    it('reports archive bytes as they are written', function () {
        $chunks = [];

        $this->builder
            ->on(function (StreamedBytes $event) use (&$chunks) {
                $chunks[] = [$event->written, $event->context->bytes->done];
            })
            ->fromLocal(__FILE__, 'builder.php');

        $size = $this->builder->saveToLocal($this->createTestFile());

        expect($chunks)->not->toBeEmpty()
            ->and(array_sum(array_column($chunks, 0)))->toBe($size)
            ->and(end($chunks)[1])->toBe($size);
    });

    it('collects bytes before reporting progress', function () {
        $source = $this->createTestFile();
        file_put_contents($source, random_bytes(4 * 1024 * 1024));

        $events = [];

        $this->builder
            ->store()
            ->progressEveryBytes(1024 * 1024)
            ->on(function (StreamedBytes $event) use (&$events) {
                $events[] = $event;
            })
            ->fromLocal($source, 'payload.bin');

        $size = $this->builder->saveToLocal($this->createTestFile());

        $written = array_map(fn (StreamedBytes $event) => $event->written, $events);

        // Four full chunks, plus whatever the central directory adds on the end.
        expect($events)->toHaveCount(5)
            ->and(array_sum($written))->toBe($size)
            ->and(end($events)->context->bytes->done)->toBe($size)
            // Every event but the last carries at least the threshold.
            ->and(array_slice($written, 0, -1))->each->toBeGreaterThanOrEqual(1024 * 1024);
    });

    it('reports every write when the threshold is zero', function () {
        $source = $this->createTestFile();
        file_put_contents($source, random_bytes(1024 * 1024));

        $calls = 0;

        $this->builder
            ->store()
            ->progressEveryBytes(0)
            ->on(function (StreamedBytes $event) use (&$calls) {
                $calls++;
            })
            ->fromLocal($source, 'payload.bin');

        $size = $this->builder->saveToLocal($this->createTestFile());

        // PHP hands the stream 8 KB at a time.
        expect($calls)->toBeGreaterThan($size / (16 * 1024));
    });

    it('reports the tail of the archive even below the threshold', function () {
        $last = null;

        $this->builder
            ->progressEveryBytes(64 * 1024 * 1024)
            ->on(function (StreamedBytes $event) use (&$last) {
                $last = $event;
            })
            ->fromRaw('test.txt', 'content');

        $size = $this->builder->saveToLocal($this->createTestFile());

        expect($last)->not->toBeNull()
            ->and($last->context->bytes->done)->toBe($size);
    });

    it('reports progress no more often than the interval', function () {
        $source = $this->createTestFile();
        file_put_contents($source, random_bytes(8 * 1024 * 1024));

        $stamps = [];

        $this->builder
            ->store()
            ->progressEveryInterval('PT1S')
            ->on(function (StreamedBytes $event) use (&$stamps) {
                $stamps[] = microtime(true);
            })
            ->fromLocal($source, 'payload.bin');

        $this->builder->saveToLocal($this->createTestFile());

        // 8 MB is written well inside a second, so only the flush at the end reports.
        expect($stamps)->toHaveCount(1);
    });

    it('takes an interval as a DateInterval too', function () {
        $this->builder
            ->progressEveryInterval(new DateInterval('PT1S'))
            ->fromRaw('test.txt', 'content');

        $interval = Invader::make($this->builder)->progressEvery;

        expect($interval->isTimeBased())->toBeTrue()
            ->and($interval->seconds)->toBe(1.0);
    });

    it('names the entry being written on the progress event', function () {
        $seen = [];

        $this->builder
            ->progressEveryBytes(0)
            ->on(function (StreamedBytes $event) use (&$seen) {
                $seen[] = [$event->context->entry?->destination(), $event->context->entryData()];
            })
            ->fromRaw('first.txt', 'content')
            ->add(Raw::make('second.txt', 'more content')->context(['media' => 7]));

        $this->builder->saveToLocal($this->createTestFile());

        $entries = array_column($seen, 0);

        expect($entries)->toContain('first.txt', 'second.txt')
            // The central directory is written with no entry open.
            ->and(end($entries))->toBeNull()
            ->and(array_column($seen, 1))->toContain(['media' => 7]);
    });

    it('reports archive bytes on every output path', function (string $path) {
        $total = 0;

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturnFalse();
        $disk->shouldReceive('writeStream')->andReturnUsing(
            fn ($target, $handle) => stream_get_contents($handle) !== false,
        );

        $this->builder
            ->on(function (StreamedBytes $event) use (&$total) {
                $total = $event->context->bytes->done;
            })
            ->fromRaw('test.txt', 'content');

        match ($path) {
            'output'      => $this->builder->output(),
            'outputTrue'  => $this->builder->output(true)->getContents(),
            'saveToLocal' => $this->builder->saveToLocal($this->createTestFile()),
            'saveToDisk'  => $this->builder->saveToDisk($disk, 'archive.zip'),
            'toResponse'  => captureStreamedOutput(fn () => $this->builder->toResponse(new Request())->sendContent()),
        };

        expect($total)->toBeGreaterThan(0);
    })->with(['output', 'outputTrue', 'saveToLocal', 'saveToDisk', 'toResponse']);

    it('does not report archive bytes to a lifecycle handler', function () {
        $types = [];

        $this->builder
            ->on(function (LifecycleEvent $event) use (&$types) {
                $types[] = $event::class;
            })
            ->fromLocal(__FILE__, 'builder.php')
            ->saveToLocal($this->createTestFile());

        expect($types)->not->toBeEmpty();
    });

    it('can be aborted from an event handler', function () {
        $aborted = false;

        $this->builder
            ->on(function (StreamingFile $event) {
                if ($event->file->destination() === 'second.txt') {
                    $this->builder->abort();
                }
            })
            ->on(function (ProcessAborted $event) use (&$aborted) {
                $aborted = true;
            })
            ->fromRaw('first.txt', 'one')
            ->fromRaw('second.txt', 'two')
            ->fromRaw('third.txt', 'three');

        $path = $this->createTestFile();
        $this->builder->saveToLocal($path);

        $archive = new ZipArchive();
        $archive->open($path);

        // The entry being streamed when abort() was called still finishes; the rest is skipped.
        expect($archive->numFiles)->toBe(2)
            ->and($archive->getFromName('second.txt'))->toBe('two')
            ->and($archive->getFromName('third.txt'))->toBeFalse()
            ->and($aborted)->toBeTrue();

        $archive->close();
    });

    it('discards a local archive it was building', function () {
        $path = sys_get_temp_dir() . '/' . uniqid('ziptest') . '.zip';

        $this->builder
            ->on(function (StreamingFile $event) {
                if ($event->file->destination() === 'second.txt') {
                    $this->builder->abort(discard: true);
                }
            })
            ->fromRaw('first.txt', 'one')
            ->fromRaw('second.txt', 'two')
            ->fromRaw('third.txt', 'three');

        expect($this->builder->saveToLocal($path))->toBeNull()
            ->and(file_exists($path))->toBeFalse()
            ->and(glob(sys_get_temp_dir() . '/ziptest*.part'))->toBeEmpty();
    });

    it('discards an archive it was writing to a disk', function () {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturnFalse();
        $disk->shouldReceive('writeStream')->once()->andReturnUsing(function ($target, $handle) {
            try {
                stream_get_contents($handle);
            } catch (\Throwable) {
                return false;
            }

            return true;
        });
        $disk->shouldReceive('delete')->once()->with('archive.zip');

        $this->builder
            ->on(function (StreamingFile $event) {
                if ($event->file->destination() === 'second.txt') {
                    $this->builder->abort(discard: true);
                }
            })
            ->fromRaw('first.txt', 'one')
            ->fromRaw('second.txt', 'two')
            ->fromRaw('third.txt', 'three');

        expect($this->builder->saveToDisk($disk, 'archive.zip'))->toBeNull();
    });

    it('keeps what it wrote when aborting without discarding', function () {
        $path = $this->createTestFile();

        $this->builder
            ->on(function (StreamingFile $event) {
                if ($event->file->destination() === 'second.txt') {
                    $this->builder->abort();
                }
            })
            ->fromRaw('first.txt', 'one')
            ->fromRaw('second.txt', 'two')
            ->fromRaw('third.txt', 'three');

        expect($this->builder->saveToLocal($path))->toBeGreaterThan(0);

        $archive = new ZipArchive();
        $archive->open($path);

        // The entry that was streaming when abort() was called still finishes; the third is skipped.
        expect($archive->numFiles)->toBe(2);

        $archive->close();
    });

    it('does not warn when the local directory already exists', function () {
        $path = $this->createTestFile();
        $warnings = [];

        set_error_handler(function (int $severity, string $message) use (&$warnings) {
            $warnings[] = $message;

            return true;
        }, E_WARNING);

        try {
            $this->builder->fromRaw('test.txt', 'content')->saveToLocal($path);
        } finally {
            restore_error_handler();
        }

        expect($warnings)->toBeEmpty();
    });

    it('escapes a filename with quotes and non-ascii characters', function () {
        $this->builder->as('Årsrapport "2026"');

        $disposition = $this->builder->toResponse(null)->headers->get('Content-Disposition');

        expect($disposition)->toContain('filename="Arsrapport \\"2026\\".zip"')
            ->and($disposition)->toContain("filename*=utf-8''%C3%85rsrapport%20%222026%22.zip");
    });

    it('rejects a filename containing line breaks', function () {
        expect(fn () => $this->builder->as("archive.zip\r\nX-Injected: 1"))
            ->toThrow(InvalidFilenameException::class);
    });

    it('removes a partial local archive when building fails', function () {
        $path = sys_get_temp_dir() . '/' . uniqid('ziptest') . '.zip';

        $source = tempnam(sys_get_temp_dir(), 'ziptest');
        $this->builder->fromRaw('first.txt', 'content')->fromLocal($source, 'gone.txt');
        unlink($source);

        expect(fn () => $this->builder->saveToLocal($path))->toThrow(FileUnavailableException::class)
            ->and(file_exists($path))->toBeFalse();
    });

    it('leaves a local file that was already there intact when building fails', function () {
        $path = $this->createTestFile();
        file_put_contents($path, 'previous archive');

        $source = tempnam(sys_get_temp_dir(), 'ziptest');
        $this->builder->fromRaw('first.txt', 'content')->fromLocal($source, 'gone.txt');
        unlink($source);

        expect(fn () => $this->builder->saveToLocal($path))->toThrow(FileUnavailableException::class);

        // The archive is built beside the target, so the previous one is neither truncated nor removed.
        expect(file_get_contents($path))->toBe('previous archive')
            ->and(glob(dirname($path) . '/*.part'))->toBeEmpty();
    });

    it('builds a full archive again after an abort', function () {
        $this->builder
            ->on(function (StreamingFile $event) {
                if ($event->file->destination() === 'second.txt') {
                    $this->builder->abort();
                }
            })
            ->fromRaw('first.txt', 'one')
            ->fromRaw('second.txt', 'two')
            ->fromRaw('third.txt', 'three');

        $aborted = $this->createTestFile();
        $this->builder->saveToLocal($aborted);

        $again = $this->createTestFile();
        $this->builder->saveToLocal($again);

        $first = new ZipArchive();
        $first->open($aborted);
        $second = new ZipArchive();
        $second->open($again);

        // The abort belongs to the run it was called in, not to the builder.
        expect($first->numFiles)->toBe(2)
            ->and($second->numFiles)->toBe(2);

        $first->close();
        $second->close();
    });

    it('can return a response', function () {
        $this->builder->as('test.zip');
        $response = $this->builder->toResponse(null);

        expect($response)->toBeInstanceOf(StreamedResponse::class)
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=test.zip')
            ->and($response->headers->get('Content-Type'))->toBe('application/zip');
    });

    it('streams the content of the response', function () {
        $this->builder
            ->as('test.zip')
            ->fromRaw('hello.txt', 'Hello World!');

        $response = $this->builder->toResponse(new Request());

        $content = captureStreamedOutput(fn () => $response->sendContent());

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

    it('only flushes output on the response path', function () {
        $invader = Invader::make($this->builder);

        $toOutput = $invader->prepareZipStream();
        $toStream = $invader->prepareZipStream(fopen('php://memory', 'w+b'));

        expect(Invader::make($toOutput)->flushOutput)->toBeTrue()
            ->and(Invader::make($toStream)->flushOutput)->toBeFalse();
    });

    it('omits Content-Length unless asked for it', function () {
        $this->builder->store()->fromRaw('test.txt', 'content');

        expect($this->builder->toResponse(new Request())->headers->has('Content-Length'))->toBeFalse();
    });

    it('adds a Content-Length matching the streamed archive', function () {
        $this->builder
            ->store()
            ->withContentLength()
            ->fromRaw('test.txt', 'content')
            ->fromLocal(__FILE__, 'builder.php')
            ->emptyDirectory('empty');

        $response = $this->builder->toResponse(new Request());
        $content = captureStreamedOutput(fn () => $response->sendContent());

        expect($response->headers->get('Content-Length'))->toBe((string) strlen($content));
    });

    it('omits Content-Length when a size cannot be known up front', function () {
        $this->builder
            ->deflate()
            ->withContentLength()
            ->fromRaw('test.txt', 'content');

        expect($this->builder->toResponse(new Request())->headers->has('Content-Length'))->toBeFalse();
    });

    it('does not fire user event handlers while calculating Content-Length', function () {
        $fired = [];

        $this->builder
            ->deflate()
            ->withContentLength()
            ->on(function (Event $event) use (&$fired) {
                $fired[] = $event;
            })
            ->fromRaw('test.txt', 'content');

        $this->builder->toResponse(new Request());

        expect($fired)->toBeEmpty();
    });
});
