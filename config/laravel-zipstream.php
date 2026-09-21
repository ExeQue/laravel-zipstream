<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Compression Method
    |--------------------------------------------------------------------------
    |
    | This option controls the default compression method to use by default
    | when creating new Zip archives.
    |
    | Supported: "DEFLATE", "STORE", null
    | Default: "DEFLATE"
    |
    */
    'default_compression_method' => env('ZIPSTREAM_COMPRESSION_METHOD'),

    /*
    |--------------------------------------------------------------------------
    | Default Deflate Level
    |--------------------------------------------------------------------------
    |
    | This option controls the default deflate compression level to use by default
    | when creating new Zip archives.
    |
    | Supported: 0-9, or null
    | Default: 6
    |
    */
    'default_deflate_level' => env('ZIPSTREAM_DEFLATE_LEVEL'),

    /*
    |--------------------------------------------------------------------------
    | Default Zero Header
    |--------------------------------------------------------------------------
    |
    | This option controls whether to enable zero header compression for new Zip archives.
    |
    | Supported: true, false, null
    |
    */
    'enable_zero_header' => env('ZIPSTREAM_ENABLE_ZERO_HEADER'),

    /*
    |--------------------------------------------------------------------------
    | Progress Reporting Interval
    |--------------------------------------------------------------------------
    |
    | How often a StreamedBytes event is dispatched. PHP writes in 8 KB chunks,
    | so reporting every write is rarely what a progress bar wants.
    |
    | The unit comes from the type. A NUMBER is BYTES, a STRING is a DURATION:
    |
    |   1048576             bytes - every 1 MB written
    |   '1048576'           bytes - an env var is always a string
    |   0                   every write, unthrottled
    |   'PT1S'              ISO 8601 duration - at most once per second
    |   'PT2M30S'           ISO 8601 - every two and a half minutes
    |   '500 milliseconds'  relative duration - ISO 8601 has no fractional
    |   '250ms'             seconds, so sub-second throttles are written out
    |   '1 second'          relative, spelled out
    |   null                the default below
    |
    | A duration is the better choice for a progress bar, since a byte count
    | fires far more often on a local disk than on a slow upload.
    |
    | These throw InvalidProgressIntervalException:
    |
    |   1 .. 8191           below one write, so it reports on every write - and
    |                       is a duration written as a number far more often
    |                       than it is a real threshold ('progress_every' => 1)
    |   -1                  not a threshold
    |   'every second'      neither an ISO 8601 nor a relative duration
    |
    | Supported: bytes as a number, a duration string, a DateInterval, or null
    | Default: 'PT1S' (at most once per second)
    |
    */
    'progress_every' => env('ZIPSTREAM_PROGRESS_EVERY'),

    /*
    |--------------------------------------------------------------------------
    | Size Precision
    |--------------------------------------------------------------------------
    |
    | How many decimals Bytes::toHuman() renders: 2 gives "5.00 KB of 64.00 MB",
    | 0 gives "5 KB of 64 MB". Every method takes a precision of its own, which
    | wins over this.
    |
    | Supported: 0 and up
    | Default: 2
    |
    */
    'size_precision' => 2,
];
