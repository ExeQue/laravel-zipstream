<?php

declare(strict_types=1);

use ExeQue\ZipStream\Contracts\ArchiveBuilder;
use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\EventQueue;
use ExeQue\ZipStream\Events\ProcessStarted;
use ExeQue\ZipStream\Events\StreamedBytes;
use ExeQue\ZipStream\Events\StreamingFile;
use ExeQue\ZipStream\Exceptions\ArchiveFrozenException;
use ExeQue\ZipStream\Exceptions\InvalidEventHandlerException;
use ExeQue\ZipStream\Facades\Zip;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;

covers(EventQueue::class);

it('hands a handler the archive it belongs to', function () {
    $seen = null;

    $zip = Zip::as('archive.zip')
        ->on(function (ProcessStarted $event, ArchiveBuilder $archive) use (&$seen) {
            $seen = $archive;
        })
        ->fromRaw('a.txt', 'content');

    $zip->saveToLocal($this->createTestFile());

    expect($seen)->toBe($zip);
});

it('resolves anything else from the container', function () {
    $resolved = null;

    $zip = Zip::as('archive.zip')
        ->on(function (ProcessStarted $event, ArchiveBuilder $archive, Repository $config) use (&$resolved) {
            $resolved = $config;
        })
        ->fromRaw('a.txt', 'content');

    $zip->saveToLocal($this->createTestFile());

    expect($resolved)->toBe(app(Repository::class));
});

it('takes the container without asking for the archive first', function () {
    $resolved = null;

    $zip = Zip::as('archive.zip')
        ->on(function (ProcessStarted $event, Factory $filesystem) use (&$resolved) {
            $resolved = $filesystem;
        })
        ->fromRaw('a.txt', 'content');

    $zip->saveToLocal($this->createTestFile());

    expect($resolved)->toBe(app(Factory::class));
});

it('hands over the container itself when asked for', function () {
    $seen = null;

    $zip = Zip::as('archive.zip')
        ->progressEveryBytes(0)
        ->on(function (StreamedBytes $event, Container $app) use (&$seen) {
            $seen ??= $app;
        })
        ->fromRaw('a.txt', 'content');

    $zip->saveToLocal($this->createTestFile());

    expect($seen)->toBe(app());
});

it('uses a default for anything it cannot type', function () {
    $seen = null;

    $zip = Zip::as('archive.zip')
        ->on(function (ProcessStarted $event, string $label = 'fallback') use (&$seen) {
            $seen = $label;
        })
        ->fromRaw('a.txt', 'content');

    $zip->saveToLocal($this->createTestFile());

    expect($seen)->toBe('fallback');
});

it('refuses a parameter it can neither build nor default', function () {
    $events = new EventQueue();

    expect(fn () => $events->add(fn (ProcessStarted $event, string $label) => null))
        ->toThrow(InvalidEventHandlerException::class, 'no type the container can build');
});

it('refuses the archive when the events belong to no builder', function () {
    $events = new EventQueue();
    $events->add(fn (ProcessStarted $event, ArchiveBuilder $archive) => null);

    expect(fn () => $events->dispatch(ProcessStarted::class))
        ->toThrow(InvalidEventHandlerException::class, 'dispatched without one');
});

it('lets a handler abort through the archive it was handed', function () {
    $path = $this->createTestFile();

    $zip = Zip::as('archive.zip')
        ->on(function (StreamingFile $event, ArchiveBuilder $archive) {
            if ($event->file->destination() === 'second.txt') {
                $archive->abort();
            }
        })
        ->fromRaw('first.txt', 'one')
        ->fromRaw('second.txt', 'two')
        ->fromRaw('third.txt', 'three');

    $zip->saveToLocal($path);

    $archive = new ZipArchive();
    $archive->open($path);

    expect($archive->numFiles)->toBe(2);

    $archive->close();
});

it('refuses an entry added while the archive is being written', function () {
    $zip = Zip::as('archive.zip')
        ->on(function (LifecycleEvent $event, ArchiveBuilder $archive) {
            if ($event instanceof StreamingFile) {
                $archive->fromRaw('late.txt', 'too late');
            }
        })
        ->fromRaw('a.txt', 'content');

    expect(fn () => $zip->saveToLocal($this->createTestFile()))
        ->toThrow(ArchiveFrozenException::class, 'while the archive is being written');
});

it('reopens the builder once the run is over', function () {
    $zip = Zip::as('archive.zip')->fromRaw('a.txt', 'content');

    $zip->saveToLocal($this->createTestFile());

    // Not frozen by the run that just finished.
    $zip->fromRaw('b.txt', 'more');

    expect($zip->toString())->toContain('b.txt');
});
