<?php

declare(strict_types=1);

namespace Tests\Feature;

use Aws\S3\S3Client;
use ExeQue\ZipStream\Exceptions\FileUnavailableException;
use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Facades\Zip;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertableZipFile;
use Tests\Support\Invader;

covers(Builder::class);

// Runs against MinIO (see docker-compose.yml); skipped when no endpoint is configured.
beforeEach(function () {
    $endpoint = getenv('ZIPSTREAM_S3_ENDPOINT');

    if (! $endpoint) {
        $this->markTestSkipped('ZIPSTREAM_S3_ENDPOINT is not set.');
    }

    $config = [
        'driver'                  => 's3',
        'key'                     => 'minioadmin',
        'secret'                  => 'minioadmin',
        'region'                  => 'us-east-1',
        'bucket'                  => 'zipstream',
        'endpoint'                => $endpoint,
        'use_path_style_endpoint' => true,
        'throw'                   => true,
    ];

    $client = new S3Client([
        'version'                 => 'latest',
        'region'                  => $config['region'],
        'endpoint'                => $endpoint,
        'use_path_style_endpoint' => true,
        'credentials'             => ['key' => $config['key'], 'secret' => $config['secret']],
    ]);

    if (! $client->doesBucketExistV2($config['bucket'])) {
        $client->createBucket(['Bucket' => $config['bucket']]);
    }

    /** @var FilesystemAdapter $disk */
    $this->disk = Storage::build($config);
    $this->path = 'tests/' . uniqid() . '.zip';
});

afterEach(function () {
    if (isset($this->disk)) {
        $this->disk->delete($this->path);
    }
});

it('aborts the multipart upload and keeps the previous object when building fails', function () {
    $client = $this->disk->getClient();

    $this->disk->put($this->path, 'previous archive');

    $source = tempnam(sys_get_temp_dir(), 'ziptest');
    file_put_contents($source, random_bytes(1024 * 1024));

    $zip = Zip::store();

    for ($i = 0; $i < 8; $i++) {
        $zip->fromLocal($source, "file-$i.bin");
    }

    // Removed after the upload has started, so it fails in the middle of a multipart upload.
    $zip->fromLocal($source, 'gone.bin');
    unlink($source);

    expect(fn () => $zip->saveToDisk($this->disk, $this->path, ['part_size' => 5 * 1024 * 1024]))
        ->toThrow(FileUnavailableException::class);

    $dangling = $client->listMultipartUploads(['Bucket' => 'zipstream'])->get('Uploads') ?? [];

    expect($dangling)->toBeEmpty()
        ->and($this->disk->get($this->path))->toBe('previous archive');
});

it('adds a whole prefix from one listing, sizes included', function () {
    foreach (['a.txt' => 'one', 'nested/b.txt' => 'two', 'nested/deep/c.txt' => 'three'] as $path => $content) {
        $this->disk->put("source/$path", $content);
    }

    $zip = Zip::store()->fromDiskDirectory($this->disk, 'source', 'files');

    $entries = Invader::make(Invader::make($zip)->pending)->entries;

    expect($entries)->toHaveCount(3);

    // The listing carries the sizes, so nothing else has to ask for them.
    foreach ($entries as $entry) {
        expect($entry->getFileOptions()->exactSize)->toBeGreaterThan(0);
    }

    $size = $zip->withContentLength()->saveToDisk($this->disk, $this->path);

    $local = $this->createTestFile();
    file_put_contents($local, $this->disk->get($this->path));

    (new AssertableZipFile($local))
        ->path('files/a.txt')->contains('one')
        ->and('files/nested/b.txt')->contains('two')
        ->and('files/nested/deep/c.txt')->contains('three');

    expect($this->disk->size($this->path))->toBe($size);

    $this->disk->deleteDirectory('source');
});

it('fills in the sizes a known size needs, from one listing per directory', function () {
    foreach (['a.txt' => 'one', 'b.txt' => 'two', 'nested/c.txt' => 'three'] as $path => $content) {
        $this->disk->put("sized/$path", $content);
    }

    $zip = Zip::store()
        ->withKnownSize()
        // Handpicked and renamed: nothing here carries a size of its own.
        ->fromDisk($this->disk, 'sized/a.txt', 'IMG-0001.txt')
        ->fromDisk($this->disk, 'sized/b.txt', 'IMG-0002.txt')
        ->fromDisk($this->disk, 'sized/nested/c.txt', 'raw/IMG-0003.txt');

    $entries = Invader::make(Invader::make($zip)->pending)->entries;

    expect(collect($entries)->every(fn ($entry) => $entry->getFileOptions()->exactSize === null))->toBeTrue();

    $size = $zip->saveToDisk($this->disk, $this->path);

    // The listing filled them in, so the size was known before a byte was written.
    expect(collect($entries)->map(fn ($entry) => $entry->getFileOptions()->exactSize)->all())->toBe([3, 3, 5])
        ->and($this->disk->size($this->path))->toBe($size);

    $this->disk->deleteDirectory('sized');
});

it('streams a multipart upload to S3', function () {
    $source = $this->createTestFile();
    file_put_contents($source, random_bytes(1024 * 1024));

    $zip = Zip::store();

    // 24 MB is above Flysystem's 16 MB multipart threshold, and three 8 MB parts.
    for ($i = 0; $i < 24; $i++) {
        $zip->fromLocal($source, "file-$i.bin");
    }

    $size = $zip->saveToDisk($this->disk, $this->path, ['part_size' => 8 * 1024 * 1024]);

    expect($this->disk->size($this->path))->toBe($size);

    $local = $this->createTestFile();
    file_put_contents($local, $this->disk->get($this->path));

    $archive = new AssertableZipFile($local);

    for ($i = 0; $i < 24; $i++) {
        $archive->path("file-$i.bin")->exists()->contains(file_get_contents($source));
    }
});
