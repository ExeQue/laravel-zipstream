<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Events\Contracts\StreamedToZip;
use ExeQue\ZipStream\Events\Data\Context;
use ExeQue\ZipStream\Options\FileOptions;

/**
 * After a file entry has been streamed.
 */
final readonly class StreamedFile implements StreamedToZip
{
    public function __construct(
        public Context $context,
        public StreamableToZip $file,
        public FileOptions $options,
    ) {
    }
}
