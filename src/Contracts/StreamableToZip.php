<?php

namespace ExeQue\ZipStream\Contracts;

use Psr\Http\Message\StreamInterface;

interface StreamableToZip
{
    /**
     * Resolve the stream contents
     *
     * @return resource|StreamInterface|string|(callable(): resource|StreamInterface|string)
     */
    public function stream();

    /**
     * The internal path in the zip archive
     *
     * @return string
     */
    public function destination(): string;
}
