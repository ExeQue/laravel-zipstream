<?php

namespace ExeQue\ZipStream\Concerns;

trait InteractsWithDestination
{
    public function as(string $filename): static
    {
        $this->destination = $filename;

        return $this;
    }

    public function destination(): string
    {
        return $this->destination;
    }
}
