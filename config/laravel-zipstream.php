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
];
