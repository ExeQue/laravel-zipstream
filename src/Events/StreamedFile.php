<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Options\FileOptions;

/**
 * After a file entry has been streamed.
 */
final readonly class StreamedFile implements StreamedToZip
{
    public function __construct(
        public string $id,
        public StreamableToZip $file,
        public FileOptions $options,
    ) {
    }
}
