<!-- Part of the Laravel ZipStream documentation - see ../README.md -->

# Adding Content

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

## Empty Directories
Create an empty directory within the ZIP.

```php
Zip::emptyDirectory('backups');
```

---

Next: [Entry options](entries.md) - sizes, context and compression per entry.

Back to the [documentation index](README.md).
