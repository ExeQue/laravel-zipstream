# Upgrade Guide

## Upgrading from 0.x to 1.0

1.0 stops `saveToDisk()` and `output(true)` from buffering the whole archive. Both now build the archive while it
is being read, so memory and temp disk use stay constant no matter how large the archive is.

For most apps upgrading only means changing the version constraint. Read the sections below if you use
`output(true)`, extend `Builder`, or rely on what happens when `saveToDisk()` fails.

```bash
composer require exeque/laravel-zipstream:^1.0
```

While 1.0 is in beta, require it explicitly:

```bash
composer require exeque/laravel-zipstream:^1.0@beta
```

`saveToLocal()` and `toResponse()` are unchanged. They already wrote straight to their destination.

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

### A failed `saveToDisk()` deletes the target path

**Impact: medium**

Before, the whole archive was built before anything was written to the disk. A failure while building (for
example an unreadable source file) was thrown before the disk was touched, and any existing file at `$path` was
left alone.

Now the archive is written while it is built, so a failure can happen halfway through the upload. When that
happens, `saveToDisk()`:

1. aborts the write, so an S3 multipart upload isn't completed with a truncated archive;
2. deletes `$path` on the disk, so no partial archive is left behind;
3. rethrows the original exception, not Flysystem's `UnableToWriteFile`, and does so even when the disk is
   configured with `'throw' => false`.

**This also removes any file that was already at `$path`.** If you overwrite an existing archive in place and need
the old one to survive a failed build, write to a temporary path and move it once `saveToDisk()` returns:

```php
$zip->saveToDisk('s3', 'backups/today.zip.tmp');

Storage::disk('s3')->move('backups/today.zip.tmp', 'backups/today.zip');
```

Error handling is otherwise unchanged. The same exceptions reach you. A `ProcessError` handler registered with
`on()` still runs first and can still skip the file.

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

### Memory use

`saveToDisk()` keeps the first 6 MB of the archive in memory. The AWS SDK reads up to 5 MB to work out how to
upload a body of unknown size and then rewinds it, and those 6 MB let that single rewind work. Beyond that, memory
use doesn't grow with the size of the archive.
