<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Options\FileOptions;

/**
 * After a directory entry has been added.
 */
final readonly class StreamedDirectory implements StreamedToZip
{
    public function __construct(
        public string $id,
        public Directory $directory,
        public FileOptions $options,
    ) {
    }
}
