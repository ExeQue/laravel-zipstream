<!-- Part of the Laravel ZipStream documentation - see ../README.md -->

# Documentation

- [Adding content](content.md) - Disks, local paths, whole directories, raw content, your own models, per-entry options and stream ownership
- [Output](output.md) - Response, local path, disk, string or stream - and what a failed write leaves behind
- [Events](events.md) - Context, progress, error handling and stopping an archive early
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
| `exactSize()`, `maxSize()` | [Adding content](content.md#entry-sizes) |
| `toResponse()`, `saveToDisk()`, `saveToLocal()`, `toString()` | [Output](output.md) |
| `withContentLength()`, `withKnownSize()` | [Output](output.md) |
| `on()`, `Context`, `StreamedBytes` | [Events](events.md) |
| `progressEveryBytes()`, `progressEveryInterval()` | [Events](events.md#progress) |
| `abort()`, `abort(discard: true)` | [Events](events.md#stopping-early) |
| `ProcessError`, `FileUnavailableException` | [Events](events.md#handling-errors) |
| `store()`, `deflate()`, `withZeroHeader()` and every other fluent option | [Configuration](configuration.md#fluent-configuration) |
| `progress_every` and the rest of the config file | [Configuration](configuration.md#the-config-file) |
| `as()` and how the filename is sent | [Output](output.md#naming-the-archive) |
| `withoutVerification()` | [Adding content](content.md#skipping-verification) |
| `context()` on an entry, `withContext()` | [Adding content](content.md#recognising-an-entry-again) |
| `Zip::fake()` | [Testing](testing.md) |
| `toHuman()`, `percentageToHuman()` and the rest | [Events](events.md#entries-and-bytes) |
| Translations for those strings | [Configuration](configuration.md#translations) |
