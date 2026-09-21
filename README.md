![Laravel ZipStream](img/laravel-zipstream.jpg)

# Laravel ZipStream

A fluent Laravel wrapper for [maennchen/zipstream-php](https://github.com/maennchen/zipstream-php) to easily generate and stream ZIP archives.

## Installation

```bash
composer require exeque/laravel-zipstream
```

The service provider registers itself. Upgrading from 0.x? See the [upgrade guide](UPGRADE.md).

## Basic Usage

```php
use ExeQue\ZipStream\Facades\Zip;

// Stream an archive to the browser
return Zip::as('gallery.zip')
    ->fromDisk('s3', 'events/2026/photo.jpg')
    ->fromLocalDirectory('/var/exports/2026', 'exports')
    ->fromRaw('README.txt', 'Thanks for downloading!')
    ->toResponse();

// Or build it straight to a disk, while it uploads
Zip::fromDiskDirectory('s3', 'events/2026', 'gala')
    ->saveToDisk('s3', 'archives/gala.zip');
```

Nothing is buffered: an archive of any size streams at constant memory, whether it goes to the browser, a disk or
a local path.

## Documentation

The full documentation is in [docs/](docs/README.md).

- [Adding content](docs/content.md) - Disks, local paths, whole directories, raw content, your own models, and per-entry options
- [Output](docs/output.md) - Response, local path, disk, string or stream - and what happens when one fails
- [Events](docs/events.md) - Progress, context, error handling and stopping an archive early
- [Configuration](docs/configuration.md) - Config file, fluent defaults and macros
- [Testing](docs/testing.md) - `Zip::fake()` and asserting what an archive contained
- [Upgrade guide](UPGRADE.md) - Moving between major versions

## Testing

```bash
composer test
```

The S3 test for `saveToDisk()` is skipped unless an S3-compatible server is available. See
[Testing against S3 with MinIO](contributing/testing-with-minio.md) to run it locally.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
