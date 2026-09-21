<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Options\FileOptions;

/**
 * Before a file entry is streamed.
 */
final readonly class StreamingFile implements StreamingToZip
{
    public function __construct(
        public string $id,
        public StreamableToZip $file,
        public FileOptions $options,
    ) {
    }
}
