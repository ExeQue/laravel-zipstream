<!-- Part of the Laravel ZipStream documentation - see ../README.md -->

# Output Options

<!-- toc -->

- [Naming the Archive](#naming-the-archive)
- [Stream to Browser (Response)](#stream-to-browser-response)
- [Save to Local Path](#save-to-local-path)
- [Save to Laravel Disk](#save-to-laravel-disk)
- [Get as String or Stream](#get-as-string-or-stream)

<!-- /toc -->

## Naming the Archive

```php
Zip::as('gallery');        // gallery.zip - the extension is added when missing
Zip::as('gallery.zip');
```

The name ends up in `Content-Disposition` when the archive is streamed, escaped and with an RFC 6266 fallback for
non-ASCII, so `Årsrapport "2026".zip` needs no handling of its own. A name containing CR or LF throws
`InvalidFilenameException`.

## Stream to Browser (Response)
Returns a `Symfony\Component\HttpFoundation\StreamedResponse`.

```php
return Zip::as('download.zip')
    ->fromDisk('public', 'large-file.mp4')
    ->toResponse();
```

The response is streamed and flushed as it is produced, so bytes reach the client immediately
rather than sitting in an output buffer.

#### Content-Length

By default the response is chunked. Opt in to a real `Content-Length` — which lets clients show
progress and detect a truncated archive — with `withContentLength()`:

```php
return Zip::as('download.zip')
    ->store()
    ->withContentLength()
    ->fromDisk('s3', $media->path, $media->name, fn (DiskFile $f) => $f->exactSize($media->size))
    ->toResponse();
```

The size is computed by replaying the archive in `OperationMode::SIMULATE_STRICT`, which performs
no I/O at all. It only succeeds when **every** entry uses `CompressionMethod::STORE` *and* has a
known `exactSize` (see [Entry sizes](content.md#entry-sizes)). When it cannot be determined, the header is
silently omitted and the response falls back to chunked.

`withKnownSize()` runs the same simulation without sending a header. It is what fills in
`Context::$bytes->total`, so a progress percentage works on any destination - `withContentLength()` turns it on
by itself.

#### Streaming from S3

Laravel defaults S3 disks to `'stream_reads' => false`. Flysystem then omits `@http.stream`, and
Guzzle buffers each whole object in memory before `readStream()` returns. Every entry becomes a
long stall with zero bytes sent — which is exactly what trips `fastcgi_read_timeout` and kills
large downloads mid-transfer. Enable real streaming on any S3 disk used with this package:

```php
// config/filesystems.php
's3' => [
    'driver'       => 's3',
    // ...
    'stream_reads' => true,
],
```

## Save to Local Path
```php
Zip::fromRaw('test.txt', 'content')
    ->saveToLocal('/path/to/save/archive.zip');
```

## Save to Laravel Disk
```php
Zip::fromRaw('test.txt', 'content')
    ->saveToDisk('s3', 'backups/today.zip');
```

The archive is uploaded while it is built, so it is never buffered locally. Options are passed on to the disk - S3
caps a multipart upload at 10,000 parts, so raise `part_size` for archives above 50 GB:

```php
Zip::fromDisk('s3', 'huge.bin')
    ->saveToDisk('s3', 'backups/huge.zip', ['part_size' => 64 * 1024 * 1024]); // up to 640 GB
```

## Get as String or Stream
```php
// Get as string
$content = Zip::fromRaw('a.txt', '...')->output();

// Get as PSR-7 Stream
$stream = Zip::fromRaw('a.txt', '...')->output(true);
```

The stream builds the archive as it is read, so it can be read once, front to back, and is not seekable.

---

Next: [Events](events.md) - progress, errors and stopping early.

Back to the [documentation index](README.md).
