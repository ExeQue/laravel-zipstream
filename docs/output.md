<!-- Part of the Laravel ZipStream documentation - see ../README.md -->

# Output Options

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
known `exactSize` (see [Entry sizes](entries.md#entry-sizes)). When it cannot be determined, the header is
silently omitted and the response falls back to chunked.

Entries added by hand carry no size, so when either of these is on the package fills them in first: local files
are stat'd, and disk entries are grouped by directory and each directory listed once - the same cost as
[`fromDiskDirectory()`](content.md#from-a-whole-prefix-or-directory), rather than a request per entry. An entry
that sets its own `exactSize` is left alone.

A length is a promise about the body, so anything that cuts the body short breaks it: `abort()` from a handler
leaves the response short of what the header declared, and a browser treats that as a failed transfer rather
than as a short archive. Leave the header off when a partial download should still be openable - see
[Stopping early](events.md#stopping-early).

Once a length has been sent, the archive is closed to further entries: `add()` and everything built on it throw
`ArchiveFrozenException`. The header is worked out when the response is made and the body is written when it is
sent, so an entry slipped in between would make the two disagree - and a download that claims a length it does
not deliver is the failure `withContentLength()` exists to prevent. Add everything first, or leave the header
off and stream chunked.

`withKnownSize()` runs the same simulation without sending a header. It is what fills in
`Context::$bytes->total`, so a progress percentage works on any destination - `withContentLength()` turns it on
by itself.

#### What the response does to PHP

A streamed response only streams if nothing above it is holding the bytes, so `toResponse()` clears the way
before the first entry:

- `zlib.output_compression` off, since it would hold the whole archive to recompute `Content-Length`.
- Every output buffer above this one flushed - ZipStream's own `ob_flush()` reaches the topmost one only.
- `Content-Encoding: identity`, because nginx's gzip filter buffers regardless of `X-Accel-Buffering` unless the
  response already declares an encoding.
- No execution time limit, restored to what it was once the response is done.

None of it applies under the CLI SAPI, where a test harness or a worker has its own reasons for the buffers it
opened.

```php
Zip::doesntManageOutput()    // an application that manages its own buffering
Zip::timeLimit(600)          // a limit of your own, in seconds
Zip::timeLimit(null)         // leave PHP's setting alone
```

Both the compression setting and the time limit are put back once the response is done, since a worker that
survives the request would otherwise carry them into the next one.

**A managed response runs without a time limit.** That is deliberate: `max_execution_time` is sized for a page,
and an archive compressed as it is sent would be killed part way through. On Unix that limit counts CPU time
rather than time spent waiting for the client, so it was never the thing holding a slow reader in check -
php-fpm's `request_terminate_timeout` is, and nothing here touches it. Set your own with `timeLimit(600)`, or
leave PHP's alone with `timeLimit(null)`.

`manage_output` in the config sets the default for the application.

#### Streaming from S3

Laravel defaults S3 disks to `'stream_reads' => false`, which makes every entry buffer in memory before it is
read. Turn it on, and see [S3](drivers/s3.md) for the rest - part sizes, options and what a failed upload leaves
behind.

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
// The whole archive in memory
$content = Zip::fromRaw('a.txt', '...')->toString();

// A PSR-7 stream, built as it is read
$stream = Zip::fromRaw('a.txt', '...')->toStream();
```

`toStream()` builds the archive as it is read, so it can be read once, front to back. It is not seekable, and
`getSize()` is null. `toString()` reads it to the end for you, which means the whole archive is in memory -
fine for a handful of small entries, and the wrong tool for anything else on this page.

Both are the only destinations with nothing to clean up, so `abort(discard: true)` surfaces there as
`ArchiveDiscardedException` rather than returning null.

---

Next: [Events](events.md) - progress, errors and stopping early.

Back to the [documentation index](README.md).
