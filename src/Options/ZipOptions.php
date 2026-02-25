<?php

namespace ExeQue\ZipStream\Options;

use Illuminate\Contracts\Config\Repository;
use ReflectionMethod;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class ZipOptions
{
    private static ?self $default = null;

    public string $comment;
    public CompressionMethod $compressionMethod;
    public int $deflateLevel;
    public bool $enableZeroHeader;

    private function __construct(Repository $config)
    {
        $reflector = new ReflectionMethod(ZipStream::class, '__construct');

        $defaults = [];
        foreach ($reflector->getParameters() as $parameter) {
            if (!$parameter->isDefaultValueAvailable()) {
                continue; // @codeCoverageIgnore
            }

            $defaults[$parameter->getName()] = $parameter->getDefaultValue();
        }

        $this->comment = $defaults['comment'];
        $this->applyDefaultCompressionMethod($config, $defaults['defaultCompressionMethod']);
        $this->applyDefaultDeflateLevel($config, $defaults['defaultDeflateLevel']);
        $this->applyDefaultZeroHeader($config, $defaults['defaultEnableZeroHeader']);
    }

    private function applyDefaultCompressionMethod(Repository $config, mixed $default): void
    {
        $default = $config->get('laravel-zipstream.default_compression_method', $default);


        if ($default instanceof CompressionMethod) {
            $this->compressionMethod = $default;
            return;
        }

        if (is_string($default)) {
            $default = CompressionMethod::{$default};
        } elseif (is_int($default)) {
            $default = CompressionMethod::from($default);
        }

        if (is_null($default)) {
            return;
        }

        $this->compressionMethod = $default;
    }

    private function applyDefaultDeflateLevel(Repository $repository, mixed $default): void
    {
        $default = $repository->get('laravel-zipstream.default_deflate_level', $default);

        if (is_string($default)) {
            $default = (int)$default;
        }

        if (is_null($default)) {
            return;
        }

        $this->deflateLevel = $default;
    }

    private function applyDefaultZeroHeader(Repository $repository, mixed $default): void
    {
        $default = $repository->get('laravel-zipstream.enable_zero_header', $default);

        if (is_null($default)) {
            return;
        }

        $this->enableZeroHeader = $default;
    }

    public static function default(Repository $config): self
    {
        return clone(self::$default ??= new self($config));
    }

    public static function clearCached(): void
    {
        self::$default = null;
    }
}
