# Upgrade Guide

Each section below is headed `## Upgrading from <from> to <to>`. Apply every section between the version that is
installed and the one being moved to, oldest first. The `laravel-zipstream-upgrade` Boost skill works through
them in that order.

## Upgrading from 0.x to 1.0

1.0 stops `saveToDisk()` and `output(true)` from buffering the whole archive. Both now build the archive while it
is being read, so memory and temp disk use stay constant no matter how large the archive is.

Events also became objects, which touches every handler you have registered. Read the sections below if you
use events, `output(true)`, extend `Builder`, or rely on what happens when `saveToDisk()` fails.

Everything that breaks, in one list:

| What | Where |
|---|---|
| `output(true)` is read-once and not seekable | [read-once stream](#outputtrue-returns-a-read-once-stream) |
| A failed `saveToDisk()` aborts the upload and cleans up the path | [cleans up after itself](#a-failed-savetodisk-cleans-up-after-itself) |
| `ProcessError` no longer catches your handlers' exceptions | [ProcessError](#processerror-no-longer-catches-exceptions-from-your-event-handlers) |
| `EventType` is gone; handlers declare what they listen for | [events are objects](#events-are-objects-and-a-handler-declares-what-it-listens-for) |
| `$event->id` is `$event->context->id`, and `StreamedBytes` lost `total` | [context](#context-on-every-event-and-streamedbytes-without-its-total) |
| Event interfaces moved to `Events\Contracts` | [namespace](#the-events-namespace-has-subfolders) |
| `Content-Type` is `application/zip`, and a plain filename is unquoted | [headers](#the-response-headers-changed) |
| `saveToDisk()` takes `$options`, which breaks an override | [$options](#savetodisk-has-a-new-options-parameter) |
| `DiskFile::make()` takes a `FilesystemAdapter` | [verification](#verifying-a-disk-entry-costs-one-request-instead-of-two) |

```bash
composer require exeque/laravel-zipstream:^1.0
```

Until 1.0 is tagged, track the branch:

```bash
composer require exeque/laravel-zipstream:1.x-dev
```

`saveToLocal()` and `toResponse()` are unchanged. They already wrote straight to their destination.

### If you published the config file

1.0 adds one key. A published `config/laravel-zipstream.php` is not updated by composer, so add it by hand -
republishing with `--force` would throw away whatever you configured:

```php
// How often to dispatch a StreamedBytes event. A number is bytes, a string is an
// ISO 8601 duration. Use 0 to report every write. Default: 'PT1S'.
'progress_every' => env('ZIPSTREAM_PROGRESS_EVERY'),
```

Leaving it out is safe: the default applies when the key is missing. See
[byte progress](#new-byte-progress) for what it controls.

---

### `output(true)` returns a read-once stream

**Impact: high, if you use `output(true)`**

Before, `output(true)` built the complete archive in `php://temp` and returned a rewound, seekable stream. It now
returns a stream that builds the archive as you read it.

| | 0.x | 1.0 |
|---|---|---|
| When the archive is built | During `output(true)` | While the stream is read |
| `isSeekable()` | `true` | `false` |
| `seek()` / `rewind()` | Works | Throws `RuntimeException` |
| `getSize()` | Archive size | `null` |
| Readable more than once | Yes, after `rewind()` | No |
| Where build errors are thrown | From `output(true)` | From `read()` / `getContents()` |

Read the stream once, front to back:

```php
$stream = Zip::fromDisk('s3', 'report.pdf')->output(true);

while (! $stream->eof()) {
    echo $stream->read(1024 * 1024);
}
```

If you need a seekable stream or the size up front, buffer it yourself:

```php
$buffered = \GuzzleHttp\Psr7\Utils::streamFor(fopen('php://temp', 'w+b'));

\GuzzleHttp\Psr7\Utils::copyToStream(Zip::fromDisk('s3', 'report.pdf')->output(true), $buffered);

$buffered->rewind();
$size = $buffered->getSize();
```

You can also save the archive with `saveToLocal()` and open the file.

**Changes to the builder after `output(true)` are included.** Because the archive is built later, any file added
to the builder after `output(true)` but before the stream is read ends up in the archive. Configure the builder
completely before you call `output(true)`.

`output()` without arguments still returns the archive as a string.

---

### A failed `saveToDisk()` cleans up after itself

**Impact: medium**

Before, the whole archive was built before anything was written to the disk. A failure while building (for
example an unreadable source file) was thrown before the disk was touched.

Now the archive is written while it is built, so a failure can happen halfway through the upload. When that
happens, `saveToDisk()`:

1. aborts the S3 multipart upload, so no parts are left behind, billed and invisible in a listing;
2. deletes the target path, but only if this call created it. A file that was already there is left alone;
3. rethrows the original exception, not Flysystem's `UnableToWriteFile`, and does so even when the disk is
   configured with `'throw' => false`.

To find out whether the path already existed, `saveToDisk()` calls `exists()` once before writing. On S3 that is
one extra `HEAD` request per archive.

`saveToLocal()` cleans up the same way, minus the multipart step it has no use for: a partial archive it
created is removed rather than left looking like a zip.

A disk that writes in place, such as the local one, has already overwritten the previous file by the time the
failure happens. Only an S3-style multipart upload keeps the old object intact until it completes. If you write
to a stable key on a local-style disk and need the previous archive to survive, write to a temporary path and
move it once `saveToDisk()` returns:

```php
$zip->saveToDisk('local', 'backups/today.zip.tmp');

Storage::disk('local')->move('backups/today.zip.tmp', 'backups/today.zip');
```

Error handling is otherwise unchanged. The same exceptions reach you, and a `ProcessError` handler registered
with `on()` still runs first and can still skip the file.

---

### `ProcessError` no longer catches exceptions from your event handlers

**Impact: medium, if you register a `ProcessError` handler**

In 0.x, one `catch (Throwable)` covered both the entry being streamed and the event handlers fired around it. An
exception thrown by your own `StreamingFile`/`StreamedFile` handler was reported as a streaming error, and the
archive was finished as if nothing had happened.

`ProcessError` now covers only the entry itself, matching what the README always said. An exception from a
handler bubbles up to the caller.

This matters if you cancel an archive by throwing from a handler. It used to work only as long as no
`ProcessError` handler was registered. It now works either way.

To stop an archive from a handler, call the new `abort()` instead of throwing. `abort(discard: true)` throws the
archive away rather than finishing it, which is what a cancelled download wants. Remaining entries are skipped,
`ProcessAborted` fires and the archive is still finished, so what has been written opens as a zip - which
throwing from a response that is already writing bytes cannot give you:

```php
$zip = Zip::as('archive.zip');

$zip->on(function (StreamedFile $event) use ($zip) {
    if ($this->cancelled()) {
        $zip->abort();
    }
});
```

If you'd rather keep the old behaviour, catch inside the handler yourself:

```php
->on(function (StreamedFile $event) {
    try {
        $this->track($event->file);
    } catch (Throwable $e) {
        report($e);
    }
})
```

---

### Events are objects, and a handler declares what it listens for

**Impact: high, if you register any handler**

`EventType` is gone. A handler now type-hints the event it wants, and that type hint *is* the registration:

```php
// 0.x
$zip->on(EventType::StreamedFile, function ($file, $options, string $id) {
    Log::info($file->destination());
});

// 1.0
use ExeQue\ZipStream\Events\StreamedFile;

$zip->on(function (StreamedFile $event) {
    Log::info($event->file->destination());
});
```

`on()` takes the handler alone. The event carries a `$context` describing the archive, so the id is no longer the
last argument, and the rest of the payload is named:

| 0.x | 1.0 |
|---|---|
| `EventType::ProcessError`, `function (Throwable $e, $id)` | `function (ProcessError $e)` → `$e->exception` |
| `$id` as the last argument | `$e->context->id` |
| `EventType::StreamingFile`, `function ($file, $options, $id)` | `function (StreamingFile $e)` → `$e->file`, `$e->options` |
| `EventType::SavedToDisk`, `function ($disk, $path, $size, $id)` | `function (SavedToDisk $e)` → `$e->disk`, `$e->path`, `$e->size` |
| `EventType::StreamedBytes`, `function ($written, $total, $id)` | `function (StreamedBytes $e)` → `$e->written`, `$e->total` |

Groups are interfaces rather than extra enum cases:

```php
// 0.x: the pair fired alongside the concrete type
$zip->on(EventType::StreamedToZip, $handler);

// 1.0: StreamedFile and StreamedDirectory both implement StreamedToZip
$zip->on(fn (StreamedToZip $event) => ...);
```

`EventType::Any` becomes `LifecycleEvent`, which is everything except byte progress - the same exclusion `Any`
had:

```php
// 0.x
$zip->on(EventType::Any, fn (...$args) => Log::info(count($args)));

// 1.0
$zip->on(fn (LifecycleEvent $event) => Log::info($event::class, ['zip' => $event->context->id]));
```

Use `Event` instead of `LifecycleEvent` if you do want byte progress in the same handler, and a union to pick
several:

```php
$zip->on(fn (SavedToDisk|SavedToFilesystem $event) => Log::info("Saved to {$event->path}"));
```

A handler whose first parameter is missing or isn't an event type now throws `InvalidEventHandlerException` when
you register it, rather than never firing. The README lists every event, its properties and the interfaces it
belongs to.

---

### Context on every event, and `StreamedBytes` without its total

**Impact: high, if you register any handler**

`$event->context` carries the archive id, the entry being streamed right now, how far along it is in entries and
in bytes, and whatever `withContext()` was given. `StreamedBytes` keeps only `written` - the bytes since the
previous report - because the running total lives on the context now:

```php
// 0.x had no context; 1.0 before this change
$zip->on(fn (StreamedBytes $event) => Cache::put("zip:{$event->id}", $event->total));

// 1.0
$zip->on(function (StreamedBytes $event) {
    $context = $event->context;

    Cache::put("zip:{$context->id}", [
        'file'    => $context->entry?->destination(),
        'files'   => "{$context->entries->done}/{$context->entries->total}",
        'bytes'   => $context->bytes->done,
        'percent' => $context->bytes->percentage(),   // null unless the size is known
    ]);
});
```

| Was | Is |
|---|---|
| `$event->id` | `$event->context->id` |
| `$event->total` on `StreamedBytes` | `$event->context->bytes->done` |
| - | `$event->context->entries->done` / `->total` |
| - | `$event->context->bytes->total`, null unless `withKnownSize()` |
| - | `$event->context->entry`, null between entries |

`entries` is an `Entries` and `bytes` a `Bytes`, both with `done`, `total`, `percentage()` and
`isComplete()`. An entry total is always known; a byte total is null unless asked for. Both render themselves:
`toHuman()` gives "10 of 125 files" and "5.00 KB of 64.00 MB", `percentageToHuman()` gives "8%", and the
sentences come from the package's translations - shipped for 30 European locales, publishable with the
`laravel-zipstream-translations` tag. The context
is a frozen snapshot taken when the event was dispatched, so a handler can keep it, and nothing a handler does
reaches back into the archive.

A byte total means knowing the archive size before writing it, which only holds when every entry is stored with
a known `exactSize`. `withKnownSize()` asks for it and `withContentLength()` implies it; without either,
`bytes->total` is null.

#### Per-entry context

Entries take their own context, handed back on every event about them, which replaces keeping a map from
destination back to a record:

```php
$zip->fromDisk('s3', $media->path, $media->name, fn (DiskFile $file) => $file->context(['media' => $media->id]));

$zip->on(fn (ProcessError $event) => Log::error('Entry failed', $event->context->entryData()));
```

---

### The `Events` namespace has subfolders

**Impact: high, if you type-hint an interface or the context**

The events themselves stay at the root of `ExeQue\ZipStream\Events`. What moved:

| Was | Is |
|---|---|
| `Events\Event` | `Events\Contracts\Event` |
| `Events\LifecycleEvent` | `Events\Contracts\LifecycleEvent` |
| `Events\ProgressEvent` | `Events\Contracts\ProgressEvent` |
| `Events\StreamingToZip` | `Events\Contracts\StreamingToZip` |
| `Events\StreamedToZip` | `Events\Contracts\StreamedToZip` |
| - | `Events\Data\Context`, `Events\Data\Entries`, `Events\Data\Bytes` |

A handler type-hinting a concrete event is unaffected. One type-hinting `LifecycleEvent` needs its import
changed.

---

### The response headers changed

**Impact: low**

`Content-Type` is now `application/zip`, the registered IANA type, rather than the unregistered
`application/x-zip`. `Content-Disposition` is built with Symfony's `HeaderUtils::makeDisposition()`, so a
filename with quotes or non-ASCII characters is escaped and carries an RFC 6266 `filename*` fallback:

```
attachment; filename="Arsrapport \"2026\".zip"; filename*=utf-8''%C3%85rsrapport%20%222026%22.zip
```

A plain name is no longer quoted - `attachment; filename=archive.zip` - which matters if you assert on the
header. `as()` now throws `InvalidFilenameException` for a name containing CR or LF.

---

### New: byte progress

`StreamedBytes` fires as the archive is written, on every destination, carrying the bytes since the previous
report. The archive so far is on its context - see above. The README has an example.

It is throttled: an event is dispatched at most once per second, and the last one always carries the finished
size. Change it per archive with `progressEveryBytes()` or `progressEveryInterval()`, or for the application
with `progress_every` in the config, where a number is bytes and a string is a duration - ISO 8601 (`'PT1S'`)
or relative (`'500 milliseconds'`). `progressEveryBytes(0)` reports every write. The README lists every accepted
format.

It sits outside `LifecycleEvent`, because it fires at PHP's write size (8 KB). A handler on the whole lifecycle
is therefore not flooded by it.

---

### New: whole prefixes, and a known size to go with them

`fromDiskDirectory($disk, $prefix, $destination, $recursive)` and `fromLocalDirectory($path, $destination,
$recursive)` add every file below a prefix or directory in one pass, with the exact sizes the listing or the walk
already carries. That is what `withContentLength()` and
`withKnownSize()` need, so a percentage and a `Content-Length` come for free on the common case of "everything
under here". On S3 it replaces a request per file.

---

### New: a test double

`Zip::fake()` records what archives would have contained instead of building them, so a test about which entries
an archive gets needs no storage:

```php
Zip::fake();

app(BuildGalleryArchive::class)->execute($event);

Zip::assertAdded('IMG-0001.jpg')->assertAddedCount(4)->assertSavedToDisk('archives/gala.zip');
```

---

### `saveToDisk()` has a new `$options` parameter

**Impact: low, unless you extend `Builder`**

```php
// 0.x
public function saveToDisk(string|FilesystemAdapter $disk, string $path): ?int

// 1.0
public function saveToDisk(string|FilesystemAdapter $disk, string $path, array $options = []): ?int
```

Existing calls keep working. If you subclass `Builder` and override `saveToDisk()`, add the parameter to your
signature, or PHP will throw a fatal error about an incompatible declaration.

`$options` is passed on to the disk's `writeStream()`. On S3 it is how you raise the part size. A multipart upload
is capped at 10,000 parts, so the default part size limits an archive to about 50 GB:

```php
Zip::fromDisk('s3', 'huge.bin')
    ->saveToDisk('s3', 'backups/huge.zip', ['part_size' => 64 * 1024 * 1024]); // up to 640 GB
```

On an S3 disk the package wraps `before_upload` to remember the multipart upload it has to abort on failure.
Your own `before_upload` callback still runs.

---

### Event handlers run while the upload is in progress

**Impact: low**

Events are fired in the same order and with the same arguments as before, including the size passed to
`SavedToDisk`. What changes is the timing. The processing events (`ProcessStarted`, `StreamingFile`,
`StreamedFile`, `ProcessFinished`, ...) now fire while the disk is receiving the archive, not before the upload
starts.

They also run inside a PHP `Fiber`. This only matters if a handler suspends fibers itself, for example through an
async runtime such as Revolt or Amp. Such a handler would suspend the archive build instead. Keep handlers
synchronous.

---

### Verifying a disk entry costs one request instead of two

**Impact: low**

`DiskFile::verify()` used to call `exists()` and then list `directories()` of the parent to rule out a directory
masquerading as a file. It now calls `fileExists()`, which answers both in one request - on S3 that halves the
requests made before a byte is read, and a 500 file archive drops from 1000 to 500.

`DiskFile::make()` now takes an `Illuminate\Filesystem\FilesystemAdapter` rather than the
`Illuminate\Contracts\Filesystem\Filesystem` contract, since `fileExists()` lives on the adapter.
`Storage::disk()` returns one, so `fromDisk()` is unaffected. Only code constructing `DiskFile` with a custom
`Filesystem` implementation has to change.

---

### Memory use

`saveToDisk()` keeps the first 6 MB of the archive in memory. The AWS SDK reads up to 5 MB to work out how to
upload a body of unknown size and then rewinds it, and those 6 MB let that single rewind work. Beyond that, memory
use doesn't grow with the size of the archive.
