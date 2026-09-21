<!-- Part of the Laravel ZipStream documentation - see ../README.md -->

# Events

A handler declares what it listens for with its first parameter. There is no event name to pass and nothing to
keep in sync: the type is the registration.

```php
use ExeQue\ZipStream\Events\ProcessStarted;
use ExeQue\ZipStream\Events\StreamedFile;
use ExeQue\ZipStream\Events\Contracts\StreamingToZip;
use ExeQue\ZipStream\Facades\Zip;

Zip::as('archive.zip')
    ->on(fn (ProcessStarted $event) => Log::info("Zip {$event->context->id} started"))
    ->on(fn (StreamedFile $event) => Log::info("Added {$event->file->destination()}"))
    ->on(fn (StreamingToZip $event) => $this->touch())          // files and directories
    ->fromDisk('public', 'images/photo1.jpg')
    ->toResponse();
```

A union listens for several at once:

```php
->on(fn (SavedToDisk|SavedToFilesystem $event) => Log::info("Saved to {$event->path}"))
```

Every event carries a `$context` describing the archive it belongs to. The rest of its properties are named after
what they are, so there is no argument order to remember.

## Context

```php
$zip->on(function (StreamedBytes $event) {
    $context = $event->context;

    Cache::put("zip:{$context->id}", [
        'file'    => $context->entry?->destination(),
        'files'   => "{$context->entries->done}/{$context->entries->total}",
        'bytes'   => $context->bytes->done,
        'percent' => $context->entries->percentage(),
    ]);
});
```

- `id` - The same value for every event of one archive
- `entry` - What is being streamed right now, or `null` between entries and while the archive is closed
- `entries` - An `Entries`: `done`, `total`, `percentage()`, `isComplete()`. The total is counted before the first byte
- `bytes` - A `Bytes`: the same, except `total` is null and `percentage()` with it unless the size is known
- `data` - Whatever was handed to `withContext()`
- `entryData()` - The context set on the current entry, as an array

Each event gets its own frozen snapshot, so a handler can hold on to it, and nothing a handler does reaches
back into the archive. `withKnownSize()` is what gives `bytes->total` a value; without it the byte percentage
is null, while the entry percentage always has one.

**Per-entry context** saves an archive built from database rows from keeping its own map back from destination
to record:

```php
$zip->fromDisk('s3', $media->path, $media->name, fn (DiskFile $file) => $file->context(['media' => $media->id]));

$zip->on(fn (ProcessError $event) => Log::error('Entry failed', $event->context->entryData()));
```

`withContext()` does the same for the archive as a whole, and lands in `$context->data`.

## What listens for what

The interfaces are the groups. Listening for one means listening for every event that implements it.

Everything below lives in `ExeQue\ZipStream\Events`: the events themselves at its root, the interfaces under
`Events\Contracts`, and `Context`, `Entries` and `Bytes` under `Events\Data`.

| Interface | Covers |
|---|---|
| `Event` | Everything below. |
| `LifecycleEvent` | Every event except byte progress. This is the one to use for logging. |
| `StreamingToZip` | `StreamingFile`, `StreamingDirectory` |
| `StreamedToZip` | `StreamedFile`, `StreamedDirectory` |
| `ProgressEvent` | `StreamedBytes` |

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
| `StreamedBytes` | `written`, `total` | As archive bytes are written, throttled |
| `SavingToDisk` | `disk`, `path` | Before `saveToDisk()` writes |
| `SavedToDisk` | `disk`, `path`, `size` | After `saveToDisk()` |
| `SavingToFilesystem` | `path` | Before `saveToLocal()` writes |
| `SavedToFilesystem` | `path`, `size` | After `saveToLocal()` |
| `StreamingResponse` | - | Before the response streams |
| `StreamedResponse` | - | After the response streams |

`StreamedBytes` is deliberately outside `LifecycleEvent`, since it fires at PHP's 8 KB write size - see
[Progress](#progress). `fn (Event $event)` does include it.

An event is only built when something listens for it, so handlers you don't register cost one array lookup.

## Progress

`StreamedBytes` fires every time bytes of the archive are written, on every destination - response, local path
or disk.

```php
Zip::store()
    ->on(function (StreamedBytes $event) {
        // The package throttles this one, so a display can be driven straight off it.
        Cache::put("zip:{$event->context->id}", [
            'files' => "{$event->context->entriesDone}/{$event->context->entriesTotal}",
            'file'  => $event->context->entry?->destination(),
            'bytes' => $event->total,
        ]);
    })
    ->fromDisk('s3', 'huge.mp4')
    ->saveToDisk('s3', 'archives/huge.zip');
```

It counts the archive's own output, not the bytes read from the sources, so a single huge entry still reports
progress while it is being streamed. The first bytes are written before the first entry is finished, so a
counter driven by `StreamedFile` alongside it starts at zero rather than one.

`written` is the bytes since the previous report - not the size of one write, since reports are throttled -
and `total` is the archive so far. By default an event is dispatched at most once per second, because PHP writes
in 8 KB chunks and few progress bars want 128 updates per megabyte. The context carries the entry counts and the
entry being written, so one handler covers files and bytes both; the flush at the end makes sure the last report
lands.

#### Throttling

Throttle by bytes or by time - the method name says which, so a bare number is never mistaken for seconds:

```php
Zip::progressEveryBytes(8 * 1024 * 1024)               // every 8 MB written
Zip::progressEveryBytes(0)                             // every write, unthrottled
Zip::progressEveryInterval('PT1S')                     // ISO 8601 duration
Zip::progressEveryInterval('500 milliseconds')         // relative duration
Zip::progressEveryInterval('250ms')                    // same, shorter
Zip::progressEveryInterval(new DateInterval('PT1S'))   // or the object itself
```

An interval is the default: a byte threshold is a guess at throughput, where 1 MB is a couple of events per
second on an upload and a thousand on a local disk. The last event always carries the finished size, whatever
the throttle.

Set the default for the application with `progress_every` in the config, or `ZIPSTREAM_PROGRESS_EVERY`. There
the unit comes from the type - **a number is bytes, a string is a duration**:

```php
'progress_every' => 1048576,             // every 1 MB
'progress_every' => 0,                   // every write
'progress_every' => 'PT1S',              // at most once per second
'progress_every' => '500 milliseconds',  // below a second
'progress_every' => null,                // the default, PT1S
```

#### Every accepted format

| Value | Read as | Example |
|---|---|---|
| `int` | Bytes | `progressEveryBytes(1048576)`, `'progress_every' => 1048576` |
| `0` | Every write | `progressEveryBytes(0)`, `'progress_every' => 0` |
| Numeric string | Bytes - this is what `ZIPSTREAM_PROGRESS_EVERY=1048576` gives | `'progress_every' => '1048576'` |
| String starting with `P` | ISO 8601 duration | `'PT1S'`, `'PT2M30S'`, `'PT0S'` |
| Any other string | Relative duration, via `DateInterval::createFromDateString()` | `'500 milliseconds'`, `'250ms'`, `'1 second'` |
| `DateInterval` | The interval, including its `$f` microseconds | `new DateInterval('PT1S')` |
| `null` (config only) | The default | `PT1S` |

ISO 8601 has no fractional seconds, so `'PT0.5S'` is a parse error. Write sub-second throttles relatively
(`'500 milliseconds'`) or set `$f` on a `DateInterval` yourself.

These throw `InvalidProgressIntervalException`:

| Value | Why |
|---|---|
| A number from 1 to 8191 | Reads as a byte count that reports on every write, and is almost always a duration someone wrote as a number - `'progress_every' => 1` meaning one second |
| A negative number | Not a threshold |
| A string that is neither an ISO 8601 nor a relative duration | `'every second'`, `'banana'` |

This throttle is also why `LifecycleEvent` leaves the event out: a handler on the whole lifecycle should not have
to know about it.

## Stopping Early

`abort()` stops the archive after the entry being streamed right now. Remaining entries are skipped,
`ProcessAborted` fires and the archive is finished, so what has been written is still a valid - if incomplete -
zip. It is meant to be called from a handler:

```php
$zip = Zip::as('archive.zip');

$zip->on(function (ProcessError $event) use ($zip) {
    report($event->exception);

    $zip->abort(); // give up on the rest of the archive
})
    ->fromDisk('public', 'images/photo1.jpg')
    ->fromDisk('public', 'images/photo2.jpg')
    ->toResponse();
```

`stopOnConnectionAborted()` does the same thing when the client hangs up.

**`abort(discard: true)`** throws the archive away instead of finishing it - for a cancelled download, where a
half archive left in a bucket is billed for and could still be handed out:

| | `abort()` | `abort(discard: true)` |
|---|---|---|
| `saveToDisk()` | Returns the size of what was written | Aborts the multipart upload, deletes the key if this call created it, returns `null` |
| `saveToLocal()` | Returns the size of what was written | Removes the file it was building, returns `null` |
| `toResponse()` | Stops writing | Stops writing - sent bytes cannot be recalled |
| `output()` | Returns what was written | Throws `ArchiveDiscardedException`, since there is nothing to clean up |

A file that was already at the target path is left alone either way.

## Handling Errors

If streaming an entry throws (e.g. a file that disappeared on disk between verification and streaming), the exception is passed to any handler registered for `ProcessError`. If no handler is registered, the exception is simply thrown. A handler is responsible for re-throwing if it wants processing to stop; otherwise, processing continues with the next entry.

```php
use ExeQue\ZipStream\Events\ProcessError;
use ExeQue\ZipStream\Exceptions\FileUnavailableException;
use Throwable;

Zip::as('archive.zip')
    ->on(function (ProcessError $event) {
        if (!$event->exception instanceof FileUnavailableException) {
            throw $event->exception; // abort on anything unexpected
        }

        report($event->exception); // log and skip the missing file
    })
    ->fromDisk('public', 'images/photo1.jpg')
    ->toResponse();
```

`DiskFile::stream()` and `LocalFile::stream()` throw `FileUnavailableException` (carrying the failing `$entry`) if the underlying disk/filesystem fails to open a read stream, even after passing verification.

`ProcessError` covers the entry itself. An exception thrown by one of your own event handlers is not reported
there - it keeps bubbling up to the caller. To stop an archive without an exception reaching a response that is
already writing bytes, call `abort()` instead.

When `saveToDisk()` or `saveToLocal()` fails part-way through, it leaves the destination as it found it: the
target path is only deleted if this call created it, so a file that was already there is left alone. That costs
one `exists()` call per `saveToDisk()`. On S3, `saveToDisk()` also aborts the multipart upload, so no parts are
left billed.

> A disk that writes in place, such as the local one, has already overwritten the previous file by the time the
> failure happens. Only an S3-style multipart upload keeps the old object intact until it completes.

---

Next: [Testing](testing.md) - asserting what an archive contained, without storage.

Back to the [documentation index](README.md).
