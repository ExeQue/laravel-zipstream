<!-- Part of the Laravel ZipStream documentation - see ../README.md -->

# Entry Options

What can be set on a single entry, whatever it was added from.

## Customizing Files

You can pass a callback as the last argument to any of the `from*` methods to customize file-specific options.

```php
use ExeQue\ZipStream\Content\LocalFile;

Zip::fromLocal('/path/file.txt', 'file.txt', function (LocalFile $file) {
    $file->comment('This is a important file')
         ->deflate()
         ->deflateLevel(9);
});
```

### Skipping Verification

Every entry that can be checked is, as it is added: `fromDisk()` asks the disk whether the file is there, and
`fromLocal()` stats it. That is one request per entry on a remote disk, and it is what turns a missing file into
a `FileNotFoundException` at composition time rather than a broken archive later.

```php
Zip::withoutVerification()->fromDisk('s3', 'huge/listing.csv');
```

`fromDiskDirectory()` and `fromLocalDirectory()` skip it on their own, since the listing they walk is already
proof. An entry that disappears between verification and streaming still raises `FileUnavailableException` -
see [Handling errors](events.md#handling-errors).

### Entry Sizes

Telling the package how large an entry is turns a *silently truncated* file into a catchable
error. When a remote body (S3, HTTP) dies mid-read, `fread()` returns `''` rather than `false`,
the read loop simply ends, and the entry is closed as if it were complete. With `exactSize()`
set, `zipstream-php` throws `FileSizeIncorrectException` instead, which is routed to
`ProcessError` (see [Handling errors](events.md#handling-errors)).

```php
Zip::store()
    ->fromDisk('s3', $media->path, $media->name, function (DiskFile $file) use ($media) {
        $file->exactSize($media->size);
    });
```

- `Raw` defaults `exactSize` to `strlen()` when the content is a string.
- `LocalFile` defaults it to `filesize()`.
- `DiskFile` has **no** default — resolving it would cost a `HEAD` request per entry. Supply it
  from your own metadata, as above.
- `maxSize()` caps how much is read from an entry.
- Pass `null` to opt out (e.g. a log file that legitimately grows while it is being streamed).

> **Size limits only apply to stored entries.** `zipstream-php` ends its read loop as soon as
> `exactSize`/`maxSize` is reached, which happens *before* `feof()`, so `deflate_add()` is never
> called with `ZLIB_FINISH` and the buffered compressed tail is dropped. To avoid producing empty
> entries, both values are ignored unless the entry's effective compression method is
> `CompressionMethod::STORE`. Use `->store()` when you want short-read detection — which is the
> right choice for already-compressed media anyway.

## Entry Sizes

Telling the package how large an entry is turns a *silently truncated* file into a catchable
error. When a remote body (S3, HTTP) dies mid-read, `fread()` returns `''` rather than `false`,
the read loop simply ends, and the entry is closed as if it were complete. With `exactSize()`
set, `zipstream-php` throws `FileSizeIncorrectException` instead, which is routed to
`ProcessError` (see [Handling errors](events.md#handling-errors)).

```php
Zip::store()
    ->fromDisk('s3', $media->path, $media->name, function (DiskFile $file) use ($media) {
        $file->exactSize($media->size);
    });
```

- `Raw` defaults `exactSize` to `strlen()` when the content is a string.
- `LocalFile` defaults it to `filesize()`.
- `DiskFile` has **no** default — resolving it would cost a `HEAD` request per entry. Supply it
  from your own metadata, as above.
- `maxSize()` caps how much is read from an entry.
- Pass `null` to opt out (e.g. a log file that legitimately grows while it is being streamed).

> **Size limits only apply to stored entries.** `zipstream-php` ends its read loop as soon as
> `exactSize`/`maxSize` is reached, which happens *before* `feof()`, so `deflate_add()` is never
> called with `ZLIB_FINISH` and the buffered compressed tail is dropped. To avoid producing empty
> entries, both values are ignored unless the entry's effective compression method is
> `CompressionMethod::STORE`. Use `->store()` when you want short-read detection — which is the
> right choice for already-compressed media anyway.

## Recognising an Entry Again

An entry can carry whatever the application needs to identify it, handed back on every event about it - which
saves keeping a map from the path inside the archive back to a record:

```php
Zip::fromDisk('s3', $media->path, $media->name, fn (DiskFile $file) => $file->context(['media' => $media->id]));

Zip::on(fn (ProcessError $event) => Log::error('Entry failed', $event->context->entryData()));
```

`Raw`, `LocalFile`, `DiskFile` and `Directory` all take one. An entry of your own does too, by implementing
`HasContext` - or using the `InteractsWithContext` trait. See [Context](progress.md#context).

## Skipping Verification

Every entry that can be checked is, as it is added: `fromDisk()` asks the disk whether the file is there, and
`fromLocal()` stats it. That is one request per entry on a remote disk, and it is what turns a missing file into
a `FileNotFoundException` at composition time rather than a broken archive later.

```php
Zip::withoutVerification()->fromDisk('s3', 'huge/listing.csv');
```

`fromDiskDirectory()` and `fromLocalDirectory()` skip it on their own, since the listing they walk is already
proof. An entry that disappears between verification and streaming still raises `FileUnavailableException` -
see [Handling errors](events.md#handling-errors).

## Stream Ownership

The package closes the stream an entry hands it, as soon as that entry has been written to the
archive. `zipstream-php` never calls `fclose()`, and entries are held for the lifetime of the
archive — so an entry that memoises its own handle would otherwise keep it open for every
remaining entry. An archive of N files would hold N concurrent file handles or S3 connections.

That means the common implementation is safe to write:

```php
class Media extends Model implements StreamableToZip
{
    private $handle = null;

    public function stream()
    {
        return $this->handle ??= Storage::disk($this->disk)->readStream($this->path);
    }
}
```

If `stream()` returns a resource that was opened elsewhere and is still needed afterwards,
implement `RetainsStream` to keep it open:

```php
use ExeQue\ZipStream\Contracts\RetainsStream;

class BorrowedHandle implements StreamableToZip, RetainsStream
{
    // ...
}
```

`Raw` implements it already — its content is supplied by the caller, so the caller keeps
ownership:

```php
$handle = fopen('report.csv', 'rb');

Zip::fromRaw('report.csv', $handle)->saveToLocal($path);

fclose($handle); // still yours to close
```

---

Next: [Output](output.md) - where the archive goes, and [Events](events.md) - what it reports while it works.

Back to the [documentation index](README.md).

---

Next: [Output](output.md) - where the archive goes.

Back to the [documentation index](README.md).
