<?php

declare(strict_types=1);

use ExeQue\ZipStream\Facades\Zip;
use ExeQue\ZipStream\Testing\ZipFake;
use PHPUnit\Framework\AssertionFailedError;

covers(ZipFake::class);

beforeEach(function () {
    Zip::fake();
});

it('records entries without touching storage', function () {
    Zip::as('gallery.zip')
        ->fromRaw('IMG-0001.jpg', 'binary')
        ->fromRaw('IMG-0002.jpg', 'binary')
        ->emptyDirectory('raw');

    Zip::assertAdded('IMG-0001.jpg')
        ->assertNotAdded('IMG-0003.jpg')
        ->assertAddedCount(3);
});

it('records across every builder made during the test', function () {
    Zip::fromRaw('a.txt', 'a');
    Zip::fromRaw('b.txt', 'b');

    Zip::assertAddedCount(2);
});

it('records where an archive was sent, and writes nothing', function () {
    $path = sys_get_temp_dir() . '/' . uniqid('zipfake') . '.zip';

    expect(Zip::fromRaw('a.txt', 'a')->saveToLocal($path))->toBe(0)
        ->and(file_exists($path))->toBeFalse();

    Zip::assertSavedToLocal($path);
});

it('records a response without streaming it', function () {
    $response = Zip::as('archive.zip')->fromRaw('a.txt', 'a')->toResponse(null);

    expect($response->headers->get('Content-Type'))->toBe('application/zip');

    Zip::assertStreamed();
});

it('fails an assertion when nothing matches', function () {
    Zip::fromRaw('a.txt', 'a');

    expect(fn () => Zip::assertAdded('missing.txt'))->toThrow(AssertionFailedError::class)
        ->and(fn () => Zip::assertNothingAdded())->toThrow(AssertionFailedError::class)
        ->and(fn () => Zip::assertSavedToDisk())->toThrow(AssertionFailedError::class);

    Zip::assertNothingSaved();
});
