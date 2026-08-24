<?php

declare(strict_types=1);

use ExeQue\ZipStream\Concerns\InteractsWithDestination;
use ExeQue\ZipStream\Concerns\InteractsWithFileOptions;
use ExeQue\ZipStream\Content\Raw;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Exceptions\UnsupportedInputException;
use GuzzleHttp\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

covers(Raw::class);

describe(Raw::class, function () {
    arch('Implements interfaces')->expect(Raw::class)->toImplement([
        StreamableToZip::class,
        HasFileOptions::class,
        Verifiable::class,
    ]);

    arch('Uses traits')->expect(Raw::class)->toUseTraits([
        InteractsWithFileOptions::class,
        InteractsWithDestination::class,
    ]);

    it('sets the destination and content', function () {
        $destination = 'foo.bar';
        $content = 'some content';

        $raw = Raw::make($destination, $content);

        expect($raw->destination())->toBe($destination)
          ->and($raw->stream())->toBe($content);
    });

    it('returns the given content as stream', function ($content) {
        $raw = Raw::make('foo.bar', $content);

        expect($raw->stream())->toBe($content);
    })->with([
        'string' => 'some string',
        'resource' => fn () => fopen('php://memory', 'rb+'),
        'callable' => fn () => fn () => 'hello',
        'stream interface' => fn () => Mockery::mock(StreamInterface::class),
    ]);

    it('verifies valid content types', function ($content) {
        $raw = Raw::make('foo.bar', $content);

        $raw->verify();

        $this->expectNotToPerformAssertions();
    })->with([
        'string' => 'some string',
        'resource' => fn () => fopen('php://memory', 'rb+'),
        'callable' => fn () => fn () => 'hello',
        'stream interface' => fn () => new Stream(fopen('php://memory', 'rb+')),
    ]);

    it('throws UnsupportedInputException for invalid content types', function ($content) {
        $raw = Raw::make('foo.bar', $content);

        expect(fn () => $raw->verify())->toThrow(UnsupportedInputException::class);
    })->with([
        'array' => [['foo']],
        'object' => new stdClass(),
        'int' => 123,
        'bool' => true,
        'null' => null,
    ]);

    fileTests(fn () => Raw::make('foo.bar', 'some content'));

    it('defaults exactSize to the string length', function () {
        expect(Raw::make('a.txt', 'hello')->getFileOptions()->exactSize)->toBe(5);
    });

    it('leaves exactSize null for non-string content', function () {
        $resource = fopen('php://memory', 'r+b');

        expect(Raw::make('a.txt', $resource)->getFileOptions()->exactSize)->toBeNull()
            ->and(Raw::make('a.txt', fn () => 'hello')->getFileOptions()->exactSize)->toBeNull();

        fclose($resource);
    });

    it('does not override an explicit exactSize', function () {
        expect(Raw::make('a.txt', 'hello')->exactSize(99)->getFileOptions()->exactSize)->toBe(99);
    });
});
