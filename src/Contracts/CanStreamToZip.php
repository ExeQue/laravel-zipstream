<?php

namespace ExeQue\ZipStream\Contracts;

interface CanStreamToZip
{
    /**
     * Get streamable content to be added to zip
     *
     * @return StreamableToZip|StreamableToZip[]|CanStreamToZip|CanStreamToZip[]
     */
    public function getStreamableToZip(): StreamableToZip|CanStreamToZip|iterable;
}
