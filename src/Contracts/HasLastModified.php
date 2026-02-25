<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Contracts;

use DateTimeInterface;

interface HasLastModified
{
    /**
     * Set the last modified time for the file.
     */
    public function lastModified(null|DateTimeInterface|int|string $timestamp): static;
}
