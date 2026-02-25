<?php

namespace ExeQue\ZipStream\Contracts;

use ExeQue\ZipStream\Options\FileOptions;
use ZipStream\CompressionMethod;

interface HasFileOptions extends HasLastModified, HasComment
{
    /**
     * Get the file options.
     */
    public function getFileOptions(): FileOptions;


    /**
     * Set the compression method for the file.
     */
    public function compressionMethod(?CompressionMethod $compressionMethod): static;

    /**
     * Use the store method for compression (no compression)
     */
    public function store(): static;


    /**
     * Use the deflate method for compression.
     */
    public function deflate(): static;

    /**
     * Set the deflate level for the file.
     */
    public function deflateLevel(?int $level): static;

    /**
     * Enable or disable zero header for the file.
     */
    public function zeroHeader(?bool $enabled): static;

    /**
     * Enable zero header for the file.
     */
    public function withZeroHeader(): static;

    /**
     * Disable zero header for the file.
     */
    public function withoutZeroHeader(): static;
}
