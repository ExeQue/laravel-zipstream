<?php

namespace ExeQue\ZipStream\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use ExeQue\ZipStream\Options\FileOptions;
use ZipStream\CompressionMethod;

trait InteractsWithFileOptions
{
    private FileOptions $fileOptions;

    private function prepareFileOptions(): void
    {
        $this->fileOptions = new FileOptions();
    }

    public function comment(string $comment = ''): static
    {
        $this->fileOptions->comment = $comment;

        return $this;
    }

    public function compressionMethod(?CompressionMethod $compressionMethod): static
    {
        $this->fileOptions->compressionMethod = $compressionMethod;

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

    public function deflateLevel(?int $level): static
    {
        $this->fileOptions->deflateLevel = $level;

        return $this;
    }

    public function lastModified(null|DateTimeInterface|int|string $timestamp): static
    {
        if (is_int($timestamp)) {
            $timestamp = '@' . $timestamp;
        }

        if (!is_null($timestamp)) {
            $timestamp = new CarbonImmutable($timestamp);
        }

        $this->fileOptions->lastModified = new CarbonImmutable($timestamp);

        return $this;
    }

    public function zeroHeader(?bool $enabled): static
    {
        $this->fileOptions->enableZeroHeader = $enabled;

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

    public function getFileOptions(): FileOptions
    {
        return $this->fileOptions;
    }
}
