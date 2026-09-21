<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Options\FileOptions;

/**
 * Before a directory entry is added.
 */
final readonly class StreamingDirectory implements StreamingToZip
{
    public function __construct(
        public string $id,
        public Directory $directory,
        public FileOptions $options,
    ) {
    }
}
