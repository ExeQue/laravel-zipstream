<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use Closure;
use ExeQue\ZipStream\Contracts\ArchiveBuilder;
use ExeQue\ZipStream\Events\Contracts\Event;
use ExeQue\ZipStream\Events\Data\Context;
use ExeQue\ZipStream\Events\Internal\ArchiveState;
use ExeQue\ZipStream\Exceptions\InvalidEventHandlerException;
use Illuminate\Container\Container;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

class EventQueue
{
    private const ARCHIVE = '@archive';

    /** @var array<int, array{types: string[], arguments: array<int, string|array{class: string}|array{default: mixed}>, handler: callable}> */
    private array $handlers = [];

    private ArchiveState $state;

    private ?ArchiveBuilder $archive = null;

    public function __construct()
    {
        $this->state = new ArchiveState(uniqid('zip-', true));
    }

    /**
     * The archive these events belong to, handed to any handler that asks for it.
     *
     * @internal
     */
    public function for(ArchiveBuilder $archive): self
    {
        $this->archive = $archive;

        return $this;
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
        $parameters = (new ReflectionFunction(Closure::fromCallable($handler)))->getParameters();

        $this->handlers[] = [
            'types'     => $this->listensFor($parameters),
            'arguments' => $this->argumentsFor($parameters),
            'handler'   => $handler,
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

        foreach ($handlers as ['handler' => $handler, 'arguments' => $plan]) {
            $handler($dispatched, ...$this->resolve($plan));
        }
    }

    /**
     * @param  class-string<Event>  $event
     * @return array<int, array{types: string[], arguments: array<int, mixed>, handler: callable}>
     */
    private function handlersFor(string $event): array
    {
        $handlers = [];

        foreach ($this->handlers as $registered) {
            foreach ($registered['types'] as $type) {
                if (is_a($event, $type, true)) {
                    $handlers[] = $registered;

                    continue 2;
                }
            }
        }

        return $handlers;
    }

    /**
     * @return string[]
     */
    /**
     * @param  ReflectionParameter[]  $parameters
     * @return string[]
     */
    private function listensFor(array $parameters): array
    {
        $parameter = $parameters[0] ?? null;
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

    /**
     * What to hand a handler after the event: the archive, or anything the container can build.
     *
     * Worked out once, when the handler is registered, so dispatching is a walk over a plan rather
     * than reflection per event.
     *
     * @param  ReflectionParameter[]  $parameters
     * @return array<int, string|array{class: string}|array{default: mixed}>
     */
    private function argumentsFor(array $parameters): array
    {
        $arguments = [];

        foreach (array_slice($parameters, 1) as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

            if ($name !== null && is_a(ArchiveBuilder::class, $name, true)) {
                $arguments[] = self::ARCHIVE;

                continue;
            }

            if ($name !== null) {
                $arguments[] = ['class' => $name];

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = ['default' => $parameter->getDefaultValue()];

                continue;
            }

            InvalidEventHandlerException::forUnresolvable($parameter->getName());
        }

        return $arguments;
    }

    /**
     * @param  array<int, string|array{class: string}|array{default: mixed}>  $plan
     * @return array<int, mixed>
     */
    private function resolve(array $plan): array
    {
        return array_map(function ($argument) {
            if ($argument === self::ARCHIVE) {
                return $this->archive ?? InvalidEventHandlerException::forArchiveOutsideBuilder();
            }

            return array_key_exists('default', $argument)
                ? $argument['default']
                : Container::getInstance()->make($argument['class']);
        }, $plan);
    }
}
