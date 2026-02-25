<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ExeQue\ZipStream\Options\FileOptions;
use ZipStream\CompressionMethod;

/**
 * @param Closure $factory
 * @return void
 * @throws DateMalformedStringException
 */
function fileTests(Closure $factory): void
{
    it('can change destination', function () use ($factory) {
        $file = $factory($this);

        $file->as('new/path/to/file.txt');

        expect($file->destination())->toBe('new/path/to/file.txt');
    });

    it('can set the comment', function () use ($factory) {
        $file = $factory($this);

        $file->comment('This is a comment');

        expect($file->getFileOptions()->comment)->toBe('This is a comment');
    });

    it('can set the compression method', function (CompressionMethod $method) use ($factory) {
        $file = $factory($this);

        $file->compressionMethod($method);

        expect($file->getFileOptions()->compressionMethod)->toBe($method);
    })->with([
        'store'   => [CompressionMethod::STORE],
        'deflate' => [CompressionMethod::DEFLATE],
    ]);

    it('can set the compression method (store)', function () use ($factory) {
        $file = $factory($this);

        $file->store();

        expect($file->getFileOptions()->compressionMethod)->toBe(CompressionMethod::STORE);
    });

    it('can set the compression method (deflate)', function () use ($factory) {
        $file = $factory($this);

        $file->deflate();

        expect($file->getFileOptions()->compressionMethod)->toBe(CompressionMethod::DEFLATE);
    });

    it('can set the deflate level', function () use ($factory) {
        $file = $factory($this);

        $file->deflateLevel(9);

        expect($file->getFileOptions()->deflateLevel)->toBe(9);
    });

    it('can set the last modified timestamp', function (mixed $input, DateTimeImmutable $expected) use ($factory) {
        $file = $factory($this);

        $file->lastModified($input);

        expect($file->getFileOptions()->lastModified->format('U'))->toEqual($expected->format('U'));
    })->with('timestamps');

    it('can set the zero header', function (bool|null $input) use ($factory) {
        $file = $factory($this);

        $file->zeroHeader($input);

        expect($file->getFileOptions()->enableZeroHeader)->toBe($input);
    })->with([
        'true'  => [true],
        'false' => [false],
        'null'  => [null],
    ]);

    it('can set the zero header (withZeroHeader)', function () use ($factory) {
        $file = $factory($this);

        $file->withZeroHeader();

        expect($file->getFileOptions()->enableZeroHeader)->toBeTrue();
    });

    it('can set the zero header (withoutZeroHeader)', function () use ($factory) {
        $file = $factory($this);

        $file->withoutZeroHeader();

        expect($file->getFileOptions()->enableZeroHeader)->toBeFalse();
    });

    it('can get the file options', function () use ($factory) {
        $file = $factory($this);

        $file->comment('This is a comment');
        $file->compressionMethod(CompressionMethod::DEFLATE);
        $file->deflateLevel(9);
        $file->lastModified(new DateTimeImmutable('2023-01-01 00:00:00'));
        $file->zeroHeader(true);

        $default = new FileOptions();

        $actual = $file->getFileOptions();

        expect($actual)->toBeInstanceOf(FileOptions::class)->not()->toEqual($default)
            ->and($actual->comment)->toBe('This is a comment')
            ->and($actual->compressionMethod)->toBe(CompressionMethod::DEFLATE)
            ->and($actual->deflateLevel)->toBe(9)
            ->and($actual->lastModified)->toEqual(new DateTimeImmutable('2023-01-01 00:00:00'))
            ->and($actual->enableZeroHeader)->toBeTrue();
    });
}

dataset('timestamps', fn () => [
    'epoch'    => [
        'input'    => time(),
        'expected' => new DateTimeImmutable('@' . time()),
    ],
    'string 1' => [
        'input'    => '2023-01-01 00:00:00',
        'expected' => new DateTimeImmutable('2023-01-01 00:00:00'),
    ],
    'string 2' => [
        'input'    => '@0',
        'expected' => new DateTimeImmutable('@0'),
    ],
    'datetime' => [
        'input'    => new CarbonImmutable('2023-01-01 00:00:00'),
        'expected' => new DateTimeImmutable('2023-01-01 00:00:00'),
    ],
]);
