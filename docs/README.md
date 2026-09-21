<!-- Part of the Laravel ZipStream documentation - see ../README.md -->

# Documentation

Also published at [exeque.github.io/laravel-zipstream](https://exeque.github.io/laravel-zipstream/), with
navigation and search.

- [Adding content](content.md) - Disks, local paths, whole directories, raw content and your own models
- [Entry options](entries.md) - Sizes, compression, context and verification, per entry
- [Output](output.md) - Response, local path, disk, string or stream - and what a failed write leaves behind
- [Events](events.md) - Listening, handling errors and stopping an archive early
- [Progress](progress.md) - The context an event carries, and reporting how far along an archive is
- [S3](drivers/s3.md) - Reading and writing buckets: part sizes, options and request counts
- [Local filesystem](drivers/local.md) - Absolute paths, and why saveToLocal() is the safer target
- [Configuration](configuration.md) - Config file, fluent defaults, translations and macros
- [Testing](testing.md) - `Zip::fake()` and asserting what an archive contained

Also in this repository:

- [Upgrade guide](../UPGRADE.md) - Moving between major versions
- [Testing against S3 with MinIO](../contributing/testing-with-minio.md) - For people working on the package itself

## Where things are

| Looking for | Page |
|---|---|
| `fromDisk()`, `fromLocal()`, `fromRaw()` | [Adding content](content.md) |
| `fromDiskDirectory()`, `fromLocalDirectory()` | [Adding content](content.md#from-a-whole-prefix-or-directory) |
| `StreamableToZip`, `CanStreamToZip` | [Adding content](content.md#from-custom-classes-contracts) |
| `exactSize()`, `maxSize()` | [Entry options](entries.md#entry-sizes) |
| `toResponse()`, `saveToDisk()`, `saveToLocal()`, `toString()` | [Output](output.md) |
| `part_size`, `mup_threshold`, `params` | [S3](drivers/s3.md#options) |
| `stream_reads` and why it matters | [S3](drivers/s3.md#the-disk) |
| `withContentLength()`, `withKnownSize()` | [Output](output.md) |
| `manageOutput()`, `timeLimit()` | [Output](output.md#what-the-response-does-to-php) |
| `on()`, the event list | [Events](events.md) |
| `Context`, `StreamedBytes` | [Progress](progress.md) |
| `progressEveryBytes()`, `progressEveryInterval()` | [Progress](progress.md#progress) |
| `abort()`, `abort(discard: true)` | [Events](events.md#stopping-early) |
| `ProcessError`, `FileUnavailableException` | [Events](events.md#handling-errors) |
| `store()`, `deflate()`, `withZeroHeader()` and every other fluent option | [Configuration](configuration.md#fluent-configuration) |
| `progress_every` and the rest of the config file | [Configuration](configuration.md#the-config-file) |
| `as()` and how the filename is sent | [Output](output.md#naming-the-archive) |
| `withoutVerification()` | [Entry options](entries.md#skipping-verification) |
| `context()` on an entry, `withContext()` | [Entry options](entries.md#recognising-an-entry-again) |
| `Zip::fake()` | [Testing](testing.md) |
| `toHuman()`, `percentageToHuman()` and the rest | [Progress](progress.md#entries-and-bytes) |
| Translations for those strings | [Configuration](configuration.md#translations) |
