---
name: laravel-zipstream
description: Generate and stream ZIP archives in Laravel using a fluent, memory-efficient API.
---

# Laravel ZipStream

## When to use this skill
Use this skill when a Laravel application needs to create ZIP files from various sources (disks, local paths, or raw content) and either stream them to the browser, save them to a disk, or obtain them as a string/stream. It wraps the `maennchen/zipstream-php` library to provide a Laravel-friendly interface.

## Benefits
- **Memory Efficiency**: Streams content directly to the output without loading the entire ZIP into memory.
- **Fluent API**: Clean, chainable methods for adding files and configuring options.
- **Integration**: Works seamlessly with Laravel's filesystem disks and responses.

## Definitions
- **Fluent API**: A method of designing object-oriented APIs that relies on method chaining to provide more readable code.
- **Streamed Response**: A response that sends content to the client in chunks, reducing server memory usage for large files.
- **Zip Facade**: The primary entry point (`ExeQue\ZipStream\Facades\Zip`) for interacting with the library.
- **Deflate**: The standard compression method used in ZIP files.
- **Store**: A ZIP method that adds files without any compression.
- **Zero Header**: A ZIP feature that allows streaming by providing file information at the end of the file instead of the beginning.
- **Event**: A readonly object reporting something the builder did. A handler is registered by type-hinting the event it wants.

## Principles
1. **Prefer Streaming**: Always use `toResponse()` or `saveToDisk()` to handle large archives without exhausting memory.
2. **Explicit Destinations**: Clearly define the path within the ZIP to maintain a clean archive structure.
3. **Smart Compression**: Use `store()` for already compressed formats (images, videos) and `deflate()` for text-based content to optimize performance.
4. **Fluent Chaining**: Build the archive by chaining methods starting from the `Zip` facade.
5. **Type-Hinted Events**: Register handlers with `on(fn (SomeEvent $event) => ...)`. The type hint is the registration - never pass an event name.

## Basic Archive Creation
To create a simple ZIP and stream it to the browser:

```php
use ExeQue\ZipStream\Facades\Zip;

return Zip::as('archive_name.zip')
    ->fromDisk('public', 'path/to/file.jpg')
    ->toResponse();
```

## Adding Various Content Sources
The library supports multiple source types:

```php
// From a Laravel Disk
Zip::fromDisk('s3', 'source/path.pdf', 'internal/name.pdf');

// From a Local File Path
Zip::fromLocal('/absolute/path/to/file.log', 'logs/app.log');

// From Raw String/Stream Content
Zip::fromRaw('notes.txt', 'This is the content of the file.');

// From Custom Classes (Contracts)
// Individual models implementing StreamableToZip
Zip::add($mediaModel);

// Collections implementing CanStreamToZip
Zip::add($mediaCollection);

// Create an Empty Directory
Zip::emptyDirectory('empty_folder');
```

## Using Custom Classes (Contracts)
Implement contracts on your models or collections to integrate them directly:

### StreamableToZip
Ideal for objects representing a single file (e.g., a `Media` model).
```php
use ExeQue\ZipStream\Contracts\StreamableToZip;

class Media extends Model implements StreamableToZip {
    public function stream() { return Storage::disk($this->disk)->readStream($this->path); }
    public function destination(): string { return "media/{$this->name}"; }
}
```

### CanStreamToZip
Ideal for objects representing multiple files (e.g., a custom collection).
```php
use ExeQue\ZipStream\Contracts\CanStreamToZip;

class MediaCollection extends Collection implements CanStreamToZip {
    public function getStreamableToZip(): iterable { return $this->all(); }
}
```

## Extending via Macros
The `Zip` facade and `Builder` are `Macroable`:

```php
Zip::macro('fromS3', function (string $path, ?string $destination = null) {
    return $this->fromDisk('s3', $path, $destination);
});

// Usage
Zip::fromS3('report.pdf')->toResponse();
```

## Configuring File-Specific Options
Use a callback to customize individual files:

```php
use ExeQue\ZipStream\Content\LocalFile;

Zip::fromLocal('file.txt', 'file.txt', function (LocalFile $file) {
    $file->comment('Important file')
         ->deflate()
         ->deflateLevel(9);
});
```

## Global Archive Options
Set options that apply to the entire ZIP:

```php
Zip::as('optimized.zip')
    ->store() // Use 'STORE' method for all files by default
    ->withZeroHeader()
    ->fromDisk('public', 'video.mp4')
    ->toResponse();
```

## Handling the Output
Choose the appropriate output method:

```php
// 1. Stream as Laravel Response
return Zip::toResponse();

// 2. Save to Local Path
Zip::saveToLocal('/path/to/archive.zip');

// 3. Save to Laravel Disk - built while it uploads, never buffered locally
Zip::saveToDisk('s3', 'backups/archive.zip');

// S3 caps a multipart upload at 10,000 parts, so raise part_size above ~50 GB
Zip::saveToDisk('s3', 'backups/huge.zip', ['part_size' => 64 * 1024 * 1024]);

// 4. Get as String
$content = Zip::output();

// 5. Get as PSR-7 Stream - built as it is read, so read it once, front to back.
// It is not seekable and getSize() is null.
$stream = Zip::output(true);
```

If `saveToDisk()` or `saveToLocal()` fails part-way, it deletes the target path, but only if that call created
it - a file that was already there survives. On S3, `saveToDisk()` also aborts the multipart upload.

## Events
A handler declares what it listens for with its first parameter. There is no event name to pass:

```php
use ExeQue\ZipStream\Events\SavedToDisk;
use ExeQue\ZipStream\Events\SavedToFilesystem;
use ExeQue\ZipStream\Events\StreamedBytes;
use ExeQue\ZipStream\Events\StreamedFile;

Zip::as('archive.zip')
    ->on(fn (StreamedFile $event) => Log::info("Added {$event->file->destination()}"))
    ->on(fn (StreamedBytes $event) => Cache::put("zip:{$event->id}", $event->total))
    ->on(fn (SavedToDisk|SavedToFilesystem $event) => Log::info("Saved to {$event->path}"))
    ->fromDisk('public', 'images/photo1.jpg')
    ->saveToDisk('s3', 'archive.zip');
```

Every event carries `$id`, the same value for every event of one archive. A union listens for several at once. A
handler with no type-hinted first parameter throws `InvalidEventHandlerException` when registered.

### Interfaces
Listening for an interface means listening for every event implementing it.

| Interface | Covers |
|---|---|
| `Event` | Everything below |
| `LifecycleEvent` | Everything except byte progress - use this for logging |
| `StreamingToZip` | `StreamingFile`, `StreamingDirectory` |
| `StreamedToZip` | `StreamedFile`, `StreamedDirectory` |
| `ProgressEvent` | `StreamedBytes` |

### Events
All live in `ExeQue\ZipStream\Events`.

| Event | Properties | Fires |
|---|---|---|
| `ProcessStarted` | - | Before the entries are streamed |
| `ProcessFinished` | - | After the last entry |
| `ProcessAborted` | - | After `abort()` or a closed connection |
| `ProcessError` | `exception` | When streaming an entry throws |
| `StreamingFile` | `file`, `options` | Before a file entry |
| `StreamedFile` | `file`, `options` | After a file entry |
| `StreamingDirectory` | `directory`, `options` | Before a directory entry |
| `StreamedDirectory` | `directory`, `options` | After a directory entry |
| `StreamedBytes` | `written`, `total` | Every write of archive bytes |
| `SavingToDisk` | `disk`, `path` | Before `saveToDisk()` writes |
| `SavedToDisk` | `disk`, `path`, `size` | After `saveToDisk()` |
| `SavingToFilesystem` | `path` | Before `saveToLocal()` writes |
| `SavedToFilesystem` | `path`, `size` | After `saveToLocal()` |
| `StreamingResponse` | - | Before the response streams |
| `StreamedResponse` | - | After the response streams |

`StreamedBytes` counts the archive's own output on every destination, so a single huge entry still reports
progress. `written` is the bytes since the previous event, `total` the archive so far.

It is throttled to at most one event per second, since PHP writes in 8 KB chunks. The last event always carries
the finished size.

```php
Zip::progressEveryBytes(8 * 1024 * 1024)   // every 8 MB, 0 for every write
Zip::progressEveryInterval('PT1S')         // at most once per second - better for a progress bar
Zip::progressEveryInterval('250ms')        // ISO 8601 has no fractional seconds
```

The application default is `progress_every` in the config. The unit comes from the type:

| Value | Read as |
|---|---|
| `int` | Bytes, `0` for every write |
| Numeric string | Bytes - what an env var gives |
| String starting with `P` | ISO 8601 duration: `'PT1S'` |
| Any other string | Relative duration: `'500 milliseconds'`, `'250ms'` |
| `DateInterval` | The interval, `$f` included |
| `null` | The default, `PT1S` |

`'PT0.5S'` is not valid ISO 8601 - write sub-second throttles relatively. A number between 1 and 8191 throws
`InvalidProgressIntervalException`, since `1` reads as one byte when one second was meant.

## Errors and Stopping Early
`ProcessError` covers the entry being streamed, not exceptions from your own handlers. Without a handler for it,
the exception is thrown instead of reported.

```php
use ExeQue\ZipStream\Events\ProcessError;
use ExeQue\ZipStream\Exceptions\FileUnavailableException;

$zip = Zip::as('archive.zip');

$zip->on(function (ProcessError $event) use ($zip) {
    if (! $event->exception instanceof FileUnavailableException) {
        throw $event->exception;   // abort on anything unexpected
    }

    report($event->exception);     // log and skip the missing entry
    $zip->abort();                 // or give up on the rest of the archive
});
```

`abort()` stops after the entry being streamed right now and still finishes the archive, so what was written
stays a valid zip. Throwing from a handler reaches the caller instead, which is no use once a response has
started writing bytes. `stopOnConnectionAborted()` does the same when the client hangs up.
