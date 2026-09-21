<!-- Part of the Laravel ZipStream documentation - see ../README.md -->

# Progress

What an archive reports about itself while it is being built.

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
- `entries` - An `Entries`, counted before the first byte is written
- `bytes` - A `Bytes`, whose total is only known when the size was worked out up front
- `data` - Whatever was handed to `withContext()`
- `entryData()` - The context set on the current entry, as an array

### Entries and Bytes

Both render themselves, so a status line needs no formatting of its own.

`Entries` - how many entries are done. The total is counted up front, so nothing here is ever null:

| | Returns | |
|---|---|---|
| `done` | `int` | Entries finished so far |
| `total` | `int` | Entries in the archive |
| `percentage()` | `float` | `8.0`. An archive with no entries is 100.0 |
| `isComplete()` | `bool` | |
| `toHuman()` | `string` | `"10 of 125 files"` |
| `percentageToHuman(int $precision = 0)` | `string` | `"8%"`, `"8.0%"` with a precision |

`Bytes` - how much of the archive has been written. The total is null unless [`withKnownSize()`](output.md) was
used, and everything derived from it is null with it:

| | Returns | |
|---|---|---|
| `done` | `int` | Bytes written so far |
| `total` | `?int` | The archive size, or null |
| `percentage()` | `?float` | `25.0`, or null |
| `isComplete()` | `bool` | False while the total is unknown - an archive that might still grow is not finished |
| `toHuman(?int $precision = null)` | `string` | `"5.00 KB of 64.00 MB"`, or `"5.00 KB"` without a total |
| `doneToHuman(?int $precision = null)` | `string` | `"5.00 KB"` |
| `totalToHuman(?int $precision = null)` | `?string` | `"64.00 MB"`, or null |
| `percentageToHuman(int $precision = 0)` | `?string` | `"25%"`, or null |

```php
$context->entries->toHuman();              // "10 of 125 files"
$context->entries->percentageToHuman();    // "8%"

$context->bytes->toHuman();                // "5.00 KB of 64.00 MB"
$context->bytes->toHuman(0);               // "5 KB of 64 MB"
$context->bytes->doneToHuman();            // "5.00 KB"
$context->bytes->percentageToHuman(1);     // "25.0%"
```

Bytes are reduced to the largest unit they fit in. The decimals default to the `size_precision` config value -
two out of the box - and an argument wins over it.

Without a total there is no sentence to build, so `toHuman()` is the size on its own, and it reads the same in
every language.

The sentences come from the package's own translations, shipped for 30 locales: `bg`, `ca`, `cs`, `da`, `de`,
`el`, `en`, `es`, `et`, `fi`, `fr`, `hr`, `hu`, `is`, `it`, `lt`, `lv`, `nb`, `nl`, `nn`, `pl`, `pt`, `pt_BR`,
`ro`, `ru`, `sk`, `sl`, `sv`, `tr` and `uk`. Publish them to change the wording or add a language:

```bash
php artisan vendor:publish --tag="laravel-zipstream-translations"
```

That writes `lang/vendor/laravel-zipstream/{locale}/progress.php`. The numbers themselves are formatted by
Laravel's `Number` helper, so a decimal comma is a matter of `Number::useLocale()` in the application rather than
of these files.

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

---

Next: [Testing](testing.md) - asserting what an archive contained.

Back to the [documentation index](README.md).
