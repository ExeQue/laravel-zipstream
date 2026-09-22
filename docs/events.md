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

### What a handler is handed

The event comes first. After it, a handler may ask for the archive it belongs to, or for anything the container
can build:

```php
use ExeQue\ZipStream\Contracts\ArchiveBuilder;

$zip->on(function (ProcessError $event, ArchiveBuilder $archive, LoggerInterface $log) {
    $log->warning('Entry failed', $event->context->entryData());

    $archive->abort();
});
```

That saves closing over the builder to reach `abort()`, and saves a handler reaching into the container itself.
Parameters are resolved by type: `ArchiveBuilder` is the archive, a class the container knows is built, and
anything else needs a default or the handler is refused when it is registered. That includes the container
itself, for a handler that would rather resolve things on its own terms:

```php
$zip->on(fn (StreamedBytes $event, Container $app) => $app->make(ProgressStore::class)->put($event->context));
```

Resolution happens per dispatch, so a scoped binding is honoured - worth remembering on `StreamedBytes`, which
fires as often as the throttle allows.

**The archive cannot be restructured from a handler.** The entries are taken when a run starts, so one added
while it is writing would never reach the archive being produced - `add()` and everything built on it throw
`ArchiveFrozenException` until the run is over. Reading, `abort()` and `withContext()` all work.

A union listens for several at once:

```php
->on(fn (SavedToDisk|SavedToFilesystem $event) => Log::info("Saved to {$event->path}"))
```

Every event carries a `$context` describing the archive it belongs to. The rest of its properties are named after
what they are, so there is no argument order to remember.

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
| `StreamedBytes` | `written` | Archive bytes, throttled. `written` is the bytes since the previous report, not one write |
| `SavingToDisk` | `disk`, `path` | Before `saveToDisk()` writes |
| `SavedToDisk` | `disk`, `path`, `size` | After `saveToDisk()` |
| `SavingToFilesystem` | `path` | Before `saveToLocal()` writes |
| `SavedToFilesystem` | `path`, `size` | After `saveToLocal()` |
| `StreamingResponse` | - | Before the response streams |
| `StreamedResponse` | - | After the response streams |

`StreamedBytes` is deliberately outside `LifecycleEvent`, since it fires at PHP's 8 KB write size - see
[Progress](progress.md). `fn (Event $event)` does include it.

An event is only built when something listens for it, so handlers you don't register cost one array lookup.

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

**On a response that promised a `Content-Length`, aborting reads as a failed transfer.** The header went out
before the body did, and a length cannot be recalled any more than the bytes can - so the browser reports a
broken download rather than handing over a short archive. That is usually the honest outcome, and it is why an
application that wants the partial download to be openable should leave `withContentLength()` off and stream
chunked.

**`abort(discard: true)`** throws the archive away instead of finishing it - for a cancelled download, where a
half archive left in a bucket is billed for and could still be handed out:

| | `abort()` | `abort(discard: true)` |
|---|---|---|
| `saveToDisk()` | Returns the size of what was written | Aborts the multipart upload, deletes the key if this call created it, returns `null` |
| `saveToLocal()` | Returns the size of what was written | Removes the file it was building, returns `null` |
| `toResponse()` | Stops writing | Stops writing - sent bytes cannot be recalled |
| `toString()` | Returns what was written | Throws `ArchiveDiscardedException`, since there is nothing to clean up |

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

---

Next: [Progress](progress.md) - the context, and reporting how far along an archive is.

Back to the [documentation index](README.md).
