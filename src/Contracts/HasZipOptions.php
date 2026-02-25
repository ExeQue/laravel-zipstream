<?php

namespace ExeQue\ZipStream\Contracts;

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
     * Enable or disable the default zero header for the zip file.
     */
    public function zeroHeader(?bool $enabled): static;

    /**
     * Enable the default zero header for the zip file.
     */
    public function withZeroHeader(): static;

    /**
     * Disable the default zero header for the zip file.
     */
    public function withoutZeroHeader(): static;
}
