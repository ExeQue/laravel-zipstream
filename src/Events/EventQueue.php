<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use Closure;
use ExeQue\ZipStream\Events\Contracts\Event;
use ExeQue\ZipStream\Events\Data\Context;
use ExeQue\ZipStream\Events\Internal\ArchiveState;
use ExeQue\ZipStream\Exceptions\InvalidEventHandlerException;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;

class EventQueue
{
    /** @var array<int, array{types: string[], handler: callable}> */
    private array $handlers = [];

    private ArchiveState $state;

    public function __construct()
    {
        $this->state = new ArchiveState(uniqid('zip-', true));
    }

    /**
     * The archive as it stands, written to by the builder. Events are handed a frozen copy of it.
     *
     * @internal
     */
    public function state(): ArchiveState
    {
        return $this->state;
    }

    /**
     * The context as it stands right now, as handlers would see it.
     */
    public function context(): Context
    {
        return $this->state->snapshot();
    }

    /**
     * Register a handler for whatever its first parameter is type-hinted with.
     */
    public function add(callable $handler): self
    {
        $this->handlers[] = [
            'types'   => $this->listensFor($handler),
            'handler' => $handler,
        ];

        return $this;
    }

    /**
     * Whether anything listens for this event, including through an interface it implements.
     *
     * @param  class-string<Event>  $event
     */
    public function hasHandlerFor(string $event): bool
    {
        return $this->handlersFor($event) !== [];
    }

    /**
     * Build the event and hand it to every handler that listens for it.
     *
     * The event is only constructed when something is listening, which keeps a handler-less
     * StreamedBytes down to one array lookup per write.
     *
     * @param  class-string<Event>  $event
     */
    public function dispatch(string $event, mixed ...$args): void
    {
        $handlers = $this->handlersFor($event);

        if ($handlers === []) {
            return;
        }

        $dispatched = new $event($this->state->snapshot(), ...$args);

        foreach ($handlers as $handler) {
            $handler($dispatched);
        }
    }

    /**
     * @param  class-string<Event>  $event
     * @return array<int, callable>
     */
    private function handlersFor(string $event): array
    {
        $handlers = [];

        foreach ($this->handlers as $registered) {
            foreach ($registered['types'] as $type) {
                if (is_a($event, $type, true)) {
                    $handlers[] = $registered['handler'];

                    continue 2;
                }
            }
        }

        return $handlers;
    }

    /**
     * @return string[]
     */
    private function listensFor(callable $handler): array
    {
        $parameter = (new ReflectionFunction(Closure::fromCallable($handler)))->getParameters()[0] ?? null;
        $type = $parameter?->getType();

        $types = match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType => $type->getTypes(),
            default                              => InvalidEventHandlerException::forUntyped(),
        };

        return array_map(static function ($type): string {
            $name = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

            if (!is_a($name, Event::class, true)) {
                InvalidEventHandlerException::forType($name);
            }

            return $name;
        }, $types);
    }
}
