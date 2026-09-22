<!-- Part of the Laravel ZipStream documentation - see ../README.md -->

# Testing Your Own Code

`Zip::fake()` records what an archive would have contained instead of building it, in the shape of
`Storage::fake()`. A test about which entries an archive gets then needs no storage at all:

```php
Zip::fake();

app(BuildGalleryArchive::class)->execute($event);

Zip::assertAdded('IMG-0001.jpg')
    ->assertNotAdded('IMG-0002.jpg')
    ->assertAddedCount(4)
    ->assertSavedToDisk('archives/gala.zip');
```

- `assertAdded($destination)` - An entry was added at that path inside an archive
- `assertNotAdded($destination)` - It was not
- `assertAddedCount($count)` - Entries across every archive built in the test
- `assertNothingAdded()` - No entries at all
- `assertSavedToDisk($path = null)` - `saveToDisk()` was called, optionally with that path
- `assertSavedToLocal($path = null)` - `saveToLocal()` was called
- `assertStreamed()` - `toResponse()` was called
- `assertNothingSaved()` - Nothing was written or streamed

`Zip::fake()` returns the recorder, so the same assertions can be called on it. `entries()` on it hands back the
entry objects themselves, for a test that needs to look at their options.

A faked archive skips the bytes, not the checks. Entries still verify themselves as they are added, so a test
whose files are not on the disk fails with `FileNotFoundException` exactly as the real builder would - pair it
with `Storage::fake()`, or add `withoutVerification()`, when the files are beside the point.

What the fake replaces is the writing: `saveToDisk()` and `saveToLocal()` return 0, `toString()` returns an empty
string, and the response streams nothing.

---

Back to the [documentation index](README.md).
