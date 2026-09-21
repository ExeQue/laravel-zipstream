<?php

namespace ExeQue\ZipStream\Concerns;

use ExeQue\ZipStream\Options\ZipOptions;
use Illuminate\Contracts\Config\Repository;
use ZipStream\CompressionMethod;

trait InteractsWithZipOptions
{
    private ZipOptions $zipOptions;

    /** What the config asked for, to go back to when an override is dropped. */
    private bool $configuredZeroHeader;

    private function prepareZipOptions(Repository $config): void
    {
        $this->zipOptions = ZipOptions::default($config);
        $this->configuredZeroHeader = $this->zipOptions->enableZeroHeader;
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

    public function inheritZeroHeader(): static
    {
        // Nothing sits above the archive, so inheriting means going back to the configured default.
        $this->zipOptions->enableZeroHeader = $this->configuredZeroHeader;

        return $this;
    }

    public function getZipOptions(): ZipOptions
    {
        return $this->zipOptions;
    }
}
