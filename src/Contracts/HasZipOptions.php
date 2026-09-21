<?php

namespace ExeQue\ZipStream\Contracts;

use ExeQue\ZipStream\Options\ZipOptions;
use ZipStream\CompressionMethod;

interface HasZipOptions
{
    /**
     * Set the default compression method for the zip file.
     */
    public function compressionMethod(CompressionMethod $compressionMethod): static;

    /**
     * Don't compress the contents of the zip file by default.
     */
    public function store(): static;

    /**
     * Compress the contents of the zip file using deflate by default.
     */
    public function deflate(): static;

    /**
     * Set the default deflate level for the zip file.
     */
    public function deflateLevel(int $level): static;

    /**
     * Enable the default zero header for the zip file.
     */
    public function withZeroHeader(): static;

    /**
     * Disable the default zero header for the zip file.
     */
    public function withoutZeroHeader(): static;

    /**
     * Drop the override and go back to the configured default.
     */
    public function inheritZeroHeader(): static;

    /**
     * The options as they stand, after every default and override.
     */
    public function getZipOptions(): ZipOptions;
}
