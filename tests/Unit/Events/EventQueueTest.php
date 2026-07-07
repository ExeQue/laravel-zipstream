<?php

declare(strict_types=1);

use ExeQue\ZipStream\Events\EventQueue;
use ExeQue\ZipStream\Events\EventType;

covers(EventQueue::class);

describe(EventQueue::class, function () {
    it('returns false for a type with no registered handler', function () {
        $events = new EventQueue();

        expect($events->hasHandler(EventType::ProcessError))->toBeFalse();
    });

    it('returns true for a type with a registered handler', function () {
        $events = new EventQueue();
        $events->add(EventType::ProcessError, fn () => null);

        expect($events->hasHandler(EventType::ProcessError))->toBeTrue();
    });

    it('returns true for an unregistered type when Any has a handler', function () {
        $events = new EventQueue();
        $events->add(EventType::Any, fn () => null);

        expect($events->hasHandler(EventType::ProcessError))->toBeTrue();
    });

    it('exclusive: ignores Any and only checks the given type', function () {
        $events = new EventQueue();
        $events->add(EventType::Any, fn () => null);

        expect($events->hasHandler(EventType::ProcessError, exclusive: true))->toBeFalse();

        $events->add(EventType::ProcessError, fn () => null);

        expect($events->hasHandler(EventType::ProcessError, exclusive: true))->toBeTrue();
    });
});
