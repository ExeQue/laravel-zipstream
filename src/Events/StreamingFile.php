<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Events\Contracts\StreamingToZip;
use ExeQue\ZipStream\Events\Data\Context;
use ExeQue\ZipStream\Options\FileOptions;

/**
 * Before a file entry is streamed.
 */
final readonly class StreamingFile implements StreamingToZip
{
    public function __construct(
        public Context $context,
        public StreamableToZip $file,
        public FileOptions $options,
    ) {
    }
}
