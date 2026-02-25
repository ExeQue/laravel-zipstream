<?php

namespace ExeQue\ZipStream\Options;

use DateTimeInterface;
use ZipStream\CompressionMethod;

class FileOptions
{
    public function __construct(
        public string             $comment = '',
        public ?CompressionMethod $compressionMethod = null,
        public ?int               $deflateLevel = null,
        public ?DateTimeInterface $lastModified = null,
        public ?bool              $enableZeroHeader = null
    ) {
    }
}
