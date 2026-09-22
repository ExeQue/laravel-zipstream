<?php

declare(strict_types=1);

use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Events\Contracts\Event;
use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\Contracts\ProgressEvent;
use ExeQue\ZipStream\Events\Contracts\StreamedToZip;
use ExeQue\ZipStream\Events\Contracts\StreamingToZip;
use ExeQue\ZipStream\Events\EventQueue;
use ExeQue\ZipStream\Events\ProcessAborted;
use ExeQue\ZipStream\Events\ProcessError;
use ExeQue\ZipStream\Events\ProcessFinished;
use ExeQue\ZipStream\Events\ProcessStarted;
use ExeQue\ZipStream\Events\SavedToDisk;
use ExeQue\ZipStream\Events\SavedToFilesystem;
use ExeQue\ZipStream\Events\SavingToDisk;
use ExeQue\ZipStream\Events\SavingToFilesystem;
use ExeQue\ZipStream\Events\StreamedBytes;
use ExeQue\ZipStream\Events\StreamedDirectory;
use ExeQue\ZipStream\Events\StreamedFile;
use ExeQue\ZipStream\Events\StreamedResponse;
use ExeQue\ZipStream\Events\StreamingDirectory;
use ExeQue\ZipStream\Events\StreamingFile;
use ExeQue\ZipStream\Events\StreamingResponse;
use ExeQue\ZipStream\Options\FileOptions;

/**
 * The group each event belongs to. Handlers are registered by type, so moving an event between
 * groups silently changes which handlers see it - this is the list that has to be edited first.
 */
const EVENT_GROUPS = [
    ProcessStarted::class     => LifecycleEvent::class,
    ProcessFinished::class    => LifecycleEvent::class,
    ProcessAborted::class     => LifecycleEvent::class,
    ProcessError::class       => LifecycleEvent::class,
    StreamingFile::class      => StreamingToZip::class,
    StreamingDirectory::class => StreamingToZip::class,
    StreamedFile::class       => StreamedToZip::class,
    StreamedDirectory::class  => StreamedToZip::class,
    SavingToDisk::class       => LifecycleEvent::class,
    SavedToDisk::class        => LifecycleEvent::class,
    SavingToFilesystem::class => LifecycleEvent::class,
    SavedToFilesystem::class  => LifecycleEvent::class,
    StreamingResponse::class  => LifecycleEvent::class,
    StreamedResponse::class   => LifecycleEvent::class,
    StreamedBytes::class      => ProgressEvent::class,
];

/**
 * A value the constructor will accept, picked from the declared type.
 */
function sampleFor(ReflectionParameter $parameter): mixed
{
    $type = $parameter->getType()->getName();

    return match ($type) {
        'string' => 'sample',
        'int'    => 42,
        default  => match (true) {
            $type === Directory::class   => Directory::make('dir'),
            $type === FileOptions::class => new FileOptions(),
            $type === Throwable::class   => new RuntimeException('boom'),
            default                      => Mockery::mock($type),
        },
    };
}

/**
 * @return class-string<Event>[]
 */
function eventClasses(): array
{
    return collect(glob(__DIR__ . '/../../../src/Events/*.php'))
        ->map(fn (string $file) => 'ExeQue\\ZipStream\\Events\\' . basename($file, '.php'))
        ->filter(fn (string $class) => class_exists($class) && is_a($class, Event::class, true))
        ->values()
        ->all();
}

describe('event types', function () {
    it('keeps every event in its group', function (string $event, string $group) {
        expect($event)->toImplement($group)
            ->and($event)->toImplement(Event::class);
    })->with(array_map(
        fn ($event, $group) => [$event, $group],
        array_keys(EVENT_GROUPS),
        EVENT_GROUPS,
    ));

    it('belongs to exactly one group', function (string $event) {
        $groups = [LifecycleEvent::class, ProgressEvent::class, StreamingToZip::class, StreamedToZip::class];

        $implemented = array_values(array_filter(
            $groups,
            fn (string $group) => is_a($event, $group, true),
        ));

        // A group implied by a more specific one is fine; two unrelated groups are not, since a
        // handler would then be picked by whichever interface it happened to type-hint.
        $specific = array_values(array_filter($implemented, function (string $group) use ($implemented) {
            foreach ($implemented as $other) {
                if ($other !== $group && is_a($other, $group, true)) {
                    return false;
                }
            }

            return true;
        }));

        expect($specific)->toHaveCount(1, sprintf('%s is in %s', $event, implode(' and ', $specific)));
    })->with(fn () => eventClasses());

    it('covers every event class', function () {
        expect(array_keys(EVENT_GROUPS))->toEqualCanonicalizing(eventClasses());
    });

    it('can be dispatched with the payload its constructor asks for', function (string $event) {
        // dispatch() builds the event with new $event($id, ...$args), so a wrong order or type only
        // shows up once something listens. This walks every event class so none of them can drift.
        $arguments = array_map(
            fn (ReflectionParameter $parameter) => sampleFor($parameter),
            array_slice((new ReflectionClass($event))->getConstructor()->getParameters(), 1),
        );

        $received = null;

        $events = new EventQueue(app());
        $events->add(function (Event $dispatched) use (&$received) {
            $received = $dispatched;
        });

        $events->dispatch($event, ...$arguments);

        expect($received)->toBeInstanceOf($event)
            ->and($received->context->id)->toBe($events->context()->id);

        foreach (array_slice((new ReflectionClass($event))->getConstructor()->getParameters(), 1) as $index => $parameter) {
            expect($received->{$parameter->getName()})->toBe($arguments[$index]);
        }
    })->with(fn () => eventClasses());

    it('is a final readonly class taking the context first', function (string $event) {
        $reflection = new ReflectionClass($event);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->getConstructor()->getParameters()[0]->getName())->toBe('context');
    })->with(fn () => eventClasses());
});
