<?php

declare(strict_types=1);

use ExeQue\ZipStream\Options\ZipOptions;
use Illuminate\Contracts\Config\Repository;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

covers(ZipOptions::class);

beforeEach(function () {
    $this->config = Mockery::mock(Repository::class);
    // By default, the config will return the fallback value (the 2nd argument of get())
    $this->config->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);
});

describe(ZipOptions::class, function () {
    it('can get a default instance', function () {
        $options = ZipOptions::default($this->config);

        expect($options)->toBeInstanceOf(ZipOptions::class);
    });

    it('returns a clone each time ZipOptions::default() is called', function () {
        $options1 = ZipOptions::default($this->config);
        $options2 = ZipOptions::default($this->config);

        expect($options1)->not->toBe($options2);
    });

    it('has properties matching ZipStream constructor defaults when config is empty', function () {
        $options = ZipOptions::default($this->config);

        $reflector = new ReflectionMethod(ZipStream::class, '__construct');
        $defaults = [];
        foreach ($reflector->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        expect($options->comment)->toBe($defaults['comment'])
            ->and($options->compressionMethod)->toBe($defaults['defaultCompressionMethod'])
            ->and($options->deflateLevel)->toBe($defaults['defaultDeflateLevel'])
            ->and($options->enableZeroHeader)->toBe($defaults['defaultEnableZeroHeader']);
    });

    it('respects compression method from config', function ($configValue, $expected) {
        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('laravel-zipstream.default_compression_method')
            ->andReturn($configValue);
        $config->shouldReceive('get')
            ->andReturnUsing(fn ($key, $default = null) => $default);

        $options = ZipOptions::default($config);

        expect($options->compressionMethod)->toBe($expected);
    })->with([
        'named store'   => fn () => [
            'input'    => 'STORE',
            'expected' => CompressionMethod::STORE,
        ],
        'named deflate' => fn () => [
            'input'    => 'DEFLATE',
            'expected' => CompressionMethod::DEFLATE,
        ],
        'numeric 0'     => fn () => [
            'input'    => 0, // 0x00
            'expected' => CompressionMethod::STORE,
        ],
        'numeric 8'     => fn () => [
            'input'    => 8, // 0x08
            'expected' => CompressionMethod::DEFLATE,
        ],
        'enum store'    => fn () => [
            'input'    => CompressionMethod::STORE,
            'expected' => CompressionMethod::STORE,
        ],
        'enum deflate'  => fn () => [
            'input'    => CompressionMethod::DEFLATE,
            'expected' => CompressionMethod::DEFLATE,
        ],
    ]);

    it('respects deflate level from config', function ($input, $expected) {
        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('laravel-zipstream.default_deflate_level')
            ->andReturn($input);
        $config->shouldReceive('get')
            ->andReturnUsing(fn ($key, $default = null) => $default);

        $options = ZipOptions::default($config);

        expect($options->deflateLevel)->toBe($expected);
    })->with([
        'integer' => [
            'input'    => 9,
            'expected' => 9,
        ],
        'string'  => [
            'input'    => '9',
            'expected' => 9,
        ],
    ]);

    it('respects zero header from config', function () {
        $this->config->shouldReceive('get')
            ->with('laravel-zipstream.enable_zero_header')
            ->andReturn(true);

        $options = ZipOptions::default($this->config);

        expect($options->enableZeroHeader)->toBeTrue();
    });

    it('can handle null config values for compression method', function () {
        $this->expectNotToPerformAssertions();

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('laravel-zipstream.default_compression_method')
            ->andReturn(null);
        $config->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);

        ZipOptions::default($config);
    });

    it('can handle null config values for deflate level', function () {
        $this->expectNotToPerformAssertions();

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('laravel-zipstream.default_deflate_level')
            ->andReturn(null);
        $config->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);

        ZipOptions::default($config);
    });

    it('can handle null config values for zero header', function () {
        $this->expectNotToPerformAssertions();

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('laravel-zipstream.enable_zero_header')
            ->andReturn(null);
        $config->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);

        ZipOptions::default($config);
    });
});
