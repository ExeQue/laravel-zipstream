<!-- Part of the Laravel ZipStream documentation - see ../../README.md -->

# Local filesystem

The local filesystem is reached two ways: as a Laravel disk, like any other, or by absolute path with
`fromLocal()`, `fromLocalDirectory()` and `saveToLocal()`.

```php
Zip::fromLocalDirectory('/var/exports/2026', 'exports')
    ->saveToLocal(storage_path('app/archives/exports.zip'));
```

## Reading

`fromLocal()` stats each file as it is added, which is what turns a missing one into a `FileNotFoundException`
while the archive is still being composed rather than half way through streaming it.

`fromLocalDirectory()` walks the directory once and takes the size and modification time the filesystem already
knows, so entries out of it carry an `exactSize` and skip that check.

## Writing

`saveToLocal()` creates the directory if it is missing, then builds the archive beside its target as a `.part`
file and renames it into place when it finishes. `rename()` is atomic within a filesystem, so:

- A reader either sees the previous archive or the new one, never half of one.
- A failure removes the `.part` file and leaves whatever was at the target untouched.

That is the one behaviour a local disk cannot offer through `saveToDisk()`, which writes in place: there, a
failure has already truncated the previous file by the time it happens. Prefer `saveToLocal()` when the target
path is one other things read.

## No multipart, no parts

Nothing here has an upload to abort or a part size to tune. The archive is written straight out, and the only
memory in play is one chunk at a time.

---

Back to the [documentation index](../README.md).
