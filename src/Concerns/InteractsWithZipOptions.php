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

    public function zeroHeader(?bool $enabled): static
    {
        $this->zipOptions->enableZeroHeader = $enabled;

        return $this;
    }

    public function withZeroHeader(): static
    {
        return $this->zeroHeader(true);
    }

    public function withoutZeroHeader(): static
    {
        return $this->zeroHeader(false);
    }

    public function getZipOptions(): ZipOptions
    {
        return $this->zipOptions;
    }
}
