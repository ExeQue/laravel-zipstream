<!-- Part of the Laravel ZipStream documentation - see ../README.md -->

# Configuration

## The config file
Publish it to set application-wide defaults:

```bash
php artisan vendor:publish --tag="laravel-zipstream-config"
```

Every key can also be set from the environment, and every one of them is optional - leaving a key out, or
publishing nothing at all, uses the default.

- `default_compression_method` (`ZIPSTREAM_COMPRESSION_METHOD`) - `"DEFLATE"`, `"STORE"` or null. Default: `"DEFLATE"`
- `default_deflate_level` (`ZIPSTREAM_DEFLATE_LEVEL`) - 0-9. Default: 6
- `enable_zero_header` (`ZIPSTREAM_ENABLE_ZERO_HEADER`) - true or false
- `progress_every` (`ZIPSTREAM_PROGRESS_EVERY`) - how often a `StreamedBytes` event is dispatched. A number is
  bytes, a string is a duration. Default: `'PT1S'`
- `size_precision` - decimals in `Bytes::toHuman()`. `2` gives `"5.00 KB of 64.00 MB"`, `0` gives
  `"5 KB of 64 MB"`. Default: `2`. No env var: it is a rendering choice, not a deployment one

```php
'progress_every' => 1048576,             // every 1 MB written
'progress_every' => 'PT1S',              // at most once per second
'progress_every' => '500 milliseconds',  // below a second
'progress_every' => 0,                   // every write
```

A number between 1 and 8191 throws `InvalidProgressIntervalException`: below PHP's 8 KB write size it reports on
every write anyway, and it is a duration written as a number far more often than it is a real threshold. See
[Progress](progress.md#progress) for every accepted format and for setting it per archive.

## Translations
The strings `Entries::toHuman()` and `Bytes::toHuman()` render ship with the package, in 30 locales covering
most of Europe: `bg`, `ca`, `cs`, `da`, `de`, `el`, `en`, `es`, `et`, `fi`, `fr`, `hr`, `hu`, `is`, `it`, `lt`,
`lv`, `nb`, `nl`, `nn`, `pl`, `pt`, `pt_BR`, `ro`, `ru`, `sk`, `sl`, `sv`, `tr` and `uk`. Publish them to reword
them or add a language:

```bash
php artisan vendor:publish --tag="laravel-zipstream-translations"
```

They land in `lang/vendor/laravel-zipstream/{locale}/progress.php`, and a locale you do not publish falls back to
the one the package ships.

## Fluent Configuration
Every default can be overridden per archive:

```php
Zip::as('archive.zip')
    ->store()                            // no compression, for already compressed media
    ->withZeroHeader()
    ->progressEveryInterval('PT1S')      // how often StreamedBytes fires
    ->withKnownSize()                    // work the size out up front, for a percentage
    ->fromLocal($file)
    ->toResponse();
```

**Compression**

- `store()` - add entries without compressing them
- `deflate()` / `deflateLevel(int $level)` - compress, and how hard (0-9)
- `compressionMethod(CompressionMethod $method)` - the same choice, by enum
- `withZeroHeader()` / `withoutZeroHeader()` / `zeroHeader(bool)` - write sizes after each entry instead of
  before it, which is what lets an entry of unknown size be streamed

**Progress and events**

- `on(callable $handler)` - register a handler, see [Events](events.md)
- `progressEveryBytes(int $bytes)` - report progress every so many bytes. `0` reports every write
- `progressEveryInterval(DateInterval|string $interval)` - report at most that often: `'PT1S'`, `'250ms'`
- `withContext(array $context)` - attach data handed back on every event about this archive

**Size and contents**

- `as(string $filename)` - name the archive, see [Naming the archive](output.md#naming-the-archive)
- `withKnownSize()` - work the archive size out before writing, filling in the byte total for progress
- `withContentLength()` - send a `Content-Length` header, which turns the above on as well
- `withoutVerification()` - do not check that entries exist as they are added
- `stopOnConnectionAborted()` - stop the archive when the client hangs up
- `abort(bool $discard = false)` - stop it yourself, see [Stopping early](events.md#stopping-early)

## Extending the Builder (Macros)

The `Zip` facade and `Builder` class use the Laravel `Macroable` trait, allowing you to add custom functionality at runtime.

```php
use ExeQue\ZipStream\Facades\Zip;

Zip::macro('fromS3', function (string $path, ?string $destination = null) {
    return $this->fromDisk('s3', $path, $destination);
});

// Usage
Zip::fromS3('exports/report.pdf')->toResponse();
```

---

Next: [Adding content](content.md).

Back to the [documentation index](README.md).
