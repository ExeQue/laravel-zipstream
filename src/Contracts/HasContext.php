<?php

namespace ExeQue\ZipStream\Contracts;

interface HasContext
{
    /**
     * Whatever the application attached to this entry, handed back on every event about it.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array;
}
