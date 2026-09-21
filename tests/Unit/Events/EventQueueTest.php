<?php

declare(strict_types=1);

use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Events\Event;
use ExeQue\ZipStream\Events\EventQueue;
use ExeQue\ZipStream\Events\LifecycleEvent;
use ExeQue\ZipStream\Events\ProcessError;
use ExeQue\ZipStream\Events\ProcessStarted;
use ExeQue\ZipStream\Events\StreamedBytes;
use ExeQue\ZipStream\Events\StreamedFile;
use ExeQue\ZipStream\Events\StreamedToZip;
use ExeQue\ZipStream\Exceptions\InvalidEventHandlerException;
use ExeQue\ZipStream\Options\FileOptions;

covers(EventQueue::class);

describe(EventQueue::class, function () {
    it('has no handler for an event nobody listens for', function () {
        $events = new EventQueue();
        $events->add(fn (ProcessStarted $event) => null);

        expect($events->hasHandlerFor(ProcessError::class))->toBeFalse();
    });

    it('matches a handler through the interfaces of an event', function () {
        $events = new EventQueue();
        $events->add(fn (StreamedToZip $event) => null);

        expect($events->hasHandlerFor(StreamedFile::class))->toBeTrue();
    });

    it('does not report byte progress to a lifecycle handler', function () {
        $events = new EventQueue();
        $events->add(fn (LifecycleEvent $event) => null);

        expect($events->hasHandlerFor(StreamedBytes::class))->toBeFalse();
    });

    it('reports byte progress to a handler on every event', function () {
        $events = new EventQueue();
        $events->add(fn (Event $event) => null);

        expect($events->hasHandlerFor(StreamedBytes::class))->toBeTrue();
    });

    it('dispatches to each matching handler once', function () {
        $seen = [];

        $events = new EventQueue();
        $events->add(function (StreamedFile $event) use (&$seen) {
            $seen[] = 'concrete';
        });
        // Matches through both of its type-hints - and is still called once.
        $events->add(function (StreamedToZip|LifecycleEvent $event) use (&$seen) {
            $seen[] = 'interface';
        });
        $events->add(function (ProcessError $event) use (&$seen) {
            $seen[] = 'other';
        });

        $events->dispatch(StreamedFile::class, Mockery::mock(StreamableToZip::class), new FileOptions());

        expect($seen)->toBe(['concrete', 'interface']);
    });

    it('calls a handler for every type in its union', function () {
        $seen = [];

        $events = new EventQueue();
        $events->add(function (StreamedFile|ProcessError $event) use (&$seen) {
            $seen[] = $event::class;
        });

        $events->dispatch(StreamedFile::class, Mockery::mock(StreamableToZip::class), new FileOptions());
        $events->dispatch(ProcessError::class, new RuntimeException('boom'));
        $events->dispatch(ProcessStarted::class);

        expect($seen)->toBe([StreamedFile::class, ProcessError::class])
            ->and($events->hasHandlerFor(StreamedFile::class))->toBeTrue()
            ->and($events->hasHandlerFor(ProcessError::class))->toBeTrue()
            ->and($events->hasHandlerFor(ProcessStarted::class))->toBeFalse();
    });

    it('does not build an event nobody listens for', function () {
        $events = new EventQueue();

        // Wrong argument count would fatal if the event were constructed.
        $events->dispatch(StreamedBytes::class);

        expect($events->hasHandlerFor(StreamedBytes::class))->toBeFalse();
    });

    it('rejects a handler without a type-hinted first parameter', function () {
        $events = new EventQueue();

        expect(fn () => $events->add(fn () => null))->toThrow(InvalidEventHandlerException::class)
            ->and(fn () => $events->add(fn (string $nope) => null))->toThrow(InvalidEventHandlerException::class);
    });
});
