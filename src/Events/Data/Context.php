<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events\Data;

use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Contracts\HasContext;
use ExeQue\ZipStream\Contracts\StreamableToZip;

/**
 * What was going on in the archive when this event was dispatched.
 *
 * A snapshot: holding on to it is safe, and nothing a handler does can change the archive.
 */
final readonly class Context
{
    /**
     * @param  string  $id  The same value for every event of one archive.
     * @param  array<string, mixed>  $data  Whatever was handed to Builder::withContext().
     * @param  StreamableToZip|Directory|null  $entry  What was being streamed, or null between entries.
     * @param  Entries  $entries  Total known before the first byte is written.
     * @param  Bytes  $bytes  Of the archive itself. The total is only known when the size was calculated up front.
     */
    public function __construct(
        public string $id,
        public array $data = [],
        public StreamableToZip|Directory|null $entry = null,
        public Entries $entries = new Entries(),
        public Bytes $bytes = new Bytes(),
    ) {
    }

    /**
     * The context set on the entry being streamed, as its own array.
     *
     * @return array<string, mixed>
     */
    public function entryData(): array
    {
        return $this->entry instanceof HasContext ? $this->entry->getContext() : [];
    }
}
