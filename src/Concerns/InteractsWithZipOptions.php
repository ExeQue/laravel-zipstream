<?php

namespace ExeQue\ZipStream\Concerns;

use ExeQue\ZipStream\Options\ZipOptions;
use Illuminate\Contracts\Config\Repository;
use ZipStream\CompressionMethod;

trait InteractsWithZipOptions
{
    private ZipOptions $zipOptions;

    private function prepareZipOptions(Repository $config): void
    {
        $this->zipOptions = ZipOptions::default($config);
    }

    public function compressionMethod(CompressionMethod $compressionMethod): static
    {
        $this->zipOptions->compressionMethod = $compressionMethod;

        return $this;
    }

    public function store(): static
    {
        return $this->compressionMethod(CompressionMethod::STORE);
    }

    public function deflate(): static
    {
        return $this->compressionMethod(CompressionMethod::DEFLATE);
    }

    public function deflateLevel(int $level): static
    {
        $this->zipOptions->deflateLevel = $level;

        return $this;
    }

    public function withZeroHeader(): static
    {
        $this->zipOptions->enableZeroHeader = true;

        return $this;
    }

    public function withoutZeroHeader(): static
    {
        $this->zipOptions->enableZeroHeader = false;

        return $this;
    }

    /**
     * Neither on nor off: inherit whatever the level above decides.
     */
    public function inheritZeroHeader(): static
    {
        $this->zipOptions->enableZeroHeader = null;

        return $this;
    }

    public function getZipOptions(): ZipOptions
    {
        return $this->zipOptions;
    }
}
