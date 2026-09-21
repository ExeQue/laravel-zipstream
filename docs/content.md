<!-- Part of the Laravel ZipStream documentation - see ../README.md -->

# Adding Content

<!-- toc -->

- [From Laravel Disks](#from-laravel-disks)
- [From a Whole Prefix or Directory](#from-a-whole-prefix-or-directory)
- [From Local Path](#from-local-path)
- [From Raw Content](#from-raw-content)
- [From Custom Classes (Contracts)](#from-custom-classes-contracts)
- [Recognising an Entry Again](#recognising-an-entry-again)
- [Empty Directories](#empty-directories)
- [Skipping Verification](#skipping-verification)
- [Entry Sizes](#entry-sizes)

<!-- /toc -->

## From Laravel Disks
Add files stored on any of your configured Laravel filesystems.

```php
Zip::fromDisk('s3', 'exports/data.csv');

// With custom destination path in ZIP
Zip::fromDisk('s3', 'exports/data.csv', '2023/report.csv');
```

## From a Whole Prefix or Directory

```php
// Everything below a prefix on a disk
Zip::fromDiskDirectory('s3', 'events/2026/gala', 'gala');
Zip::fromDiskDirectory('s3', 'thumbnails', recursive: false);   // just the top level

// The same, from the local filesystem
Zip::fromLocalDirectory('/var/exports/2026', 'exports');

// Each entry can still be modified as it is added
Zip::fromDiskDirectory('s3', 'raw', 'raw', modify: fn (DiskFile $file) => $file->store());
```

- `$source` - The prefix or directory to add
- `$destination` - The folder inside the archive. Defaults to the source's own name. Pass `''` for the archive root
- `$recursive` - `true` by default. `false` takes only the top level
- `$modify` - Called with each entry, like the callback on `fromDisk()` and `fromLocal()`

One listing - or one walk - instead of a request per file, and `exactSize` and `lastModified` come with it, so
`withContentLength()` and `withKnownSize()` work without asking the disk anything further. The folder structure
below the source is kept, and these entries skip verification, since the listing is already proof that they
exist.

## From Local Path
Add files from the local filesystem.

```php
Zip::fromLocal('/tmp/temp-file.log');

// With custom destination path in ZIP
Zip::fromLocal('/tmp/temp-file.log', 'logs/system.log');
```

## From Raw Content
Add content directly from a string, resource, or stream.

```php
Zip::fromRaw('hello.txt', 'Hello World');
```

## From Custom Classes (Contracts)

You can implement `StreamableToZip` or `CanStreamToZip` on your custom classes (e.g., a `Media` model or `MediaCollection`) to easily add them to the ZIP archive.

#### StreamableToZip

The `StreamableToZip` contract is ideal for individual models that represent a file.

```php
use ExeQue\ZipStream\Contracts\StreamableToZip;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Media extends Model implements StreamableToZip
{
    public function stream()
    {
        // Return resource, string, StreamInterface, or a callable that returns one of these.
        return Storage::disk($this->disk)->readStream($this->path);
    }

    public function destination(): string
    {
        return "{$this->collection_name}/{$this->file_name}";
    }
}

Zip::add(Media::first());
```

#### CanStreamToZip

The `CanStreamToZip` contract is useful for classes that represent a collection of files, such as a `MediaCollection`.

```php
use ExeQue\ZipStream\Contracts\CanStreamToZip;
use Illuminate\Database\Eloquent\Collection;

class MediaCollection extends Collection implements CanStreamToZip
{
    public function getStreamableToZip(): iterable
    {
        return $this->all();
    }
}

$media = Media::where('collection_name', 'avatars')->get();
$collection = new MediaCollection($media);

Zip::add($collection);
```

## Recognising an Entry Again

An entry can carry whatever the application needs to identify it, handed back on every event about it - which
saves keeping a map from the path inside the archive back to a record:

```php
Zip::fromDisk('s3', $media->path, $media->name, fn (DiskFile $file) => $file->context(['media' => $media->id]));

Zip::on(fn (ProcessError $event) => Log::error('Entry failed', $event->context->entryData()));
```

`Raw`, `LocalFile`, `DiskFile` and `Directory` all take one. An entry of your own does too, by implementing
`HasContext` - or using the `InteractsWithContext` trait. See [Context](events.md#context).

## Empty Directories
Create an empty directory within the ZIP.

```php
Zip::emptyDirectory('backups');
```
# Customizing Files

You can pass a callback as the last argument to any of the `from*` methods to customize file-specific options.

```php
use ExeQue\ZipStream\Content\LocalFile;

Zip::fromLocal('/path/file.txt', 'file.txt', function (LocalFile $file) {
    $file->comment('This is a important file')
         ->deflate()
         ->deflateLevel(9);
});
```

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
# Stream Ownership

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
