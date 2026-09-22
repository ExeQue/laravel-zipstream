<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Progress
    |--------------------------------------------------------------------------
    |
    | What Entries::toHuman() and Bytes::toHuman() render. :done and :total are
    | already formatted - a count for entries, a file size for bytes.
    |
    */

    'entries' => ':done of :total file|:done of :total files',

    'bytes' => ':done of :total',
];
