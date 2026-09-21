<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events\Internal;

use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Events\Data\Context;
use ExeQue\ZipStream\Events\Data\Bytes;
use ExeQue\ZipStream\Events\Data\Entries;

/**
 * The archive as it is right now, written to as the work happens.
 *
 * Handlers never see this: every event gets a frozen Context instead.
 *
 * @internal
 */
final class ArchiveState
{
    public StreamableToZip|Directory|null $entry = null;

    public int $entriesDone = 0;

    public int $entriesTotal = 0;

    public int $bytesDone = 0;

    public ?int $bytesTotal = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public function __construct(
        public readonly string $id,
    ) {
    }

    public function snapshot(): Context
    {
        return new Context(
            id: $this->id,
            data: $this->data,
            entry: $this->entry,
            entries: new Entries($this->entriesDone, $this->entriesTotal),
            bytes: new Bytes($this->bytesDone, $this->bytesTotal),
        );
    }
}
