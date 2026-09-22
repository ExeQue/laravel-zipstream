<?php

namespace Tests\Support;

use ExeQue\ZipStream\Events\Contracts\Event;
use ExeQue\ZipStream\Events\Contracts\LifecycleEvent;
use ExeQue\ZipStream\Events\EventQueue as BaseEventQueue;

class EventQueueSpy extends BaseEventQueue
{
    /** @var Event[] */
    private array $events = [];

    public function __construct()
    {
        parent::__construct();

        // LifecycleEvent rather than Event: spying on everything would also switch on byte progress.
        parent::add(function (LifecycleEvent $event) {
            $this->events[] = $event;
        });
    }

    /**
     * @return Event[]
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Assert the events that were dispatched, in order.
     *
     * @param  class-string<Event>[]  $expected
     */
    public function assertTypes(array $expected): self
    {
        expect(array_map(fn (Event $event) => $event::class, $this->events))->toBe($expected);

        return $this;
    }

    /**
     * @param  callable(Event): void  $assertion
     */
    public function assertAt(int $position, callable $assertion): self
    {
        expect($this->events)->toHaveKey($position);

        $assertion($this->events[$position]);

        return $this;
    }
}
