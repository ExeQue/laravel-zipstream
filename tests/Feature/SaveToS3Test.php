<?php

declare(strict_types=1);

namespace Tests\Feature;

use Aws\S3\S3Client;
use ErrorException;
use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Facades\Zip;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertableZipFile;

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
        ->toThrow(ErrorException::class);

    $dangling = $client->listMultipartUploads(['Bucket' => 'zipstream'])->get('Uploads') ?? [];

    expect($dangling)->toBeEmpty()
        ->and($this->disk->get($this->path))->toBe('previous archive');
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
