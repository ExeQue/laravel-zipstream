<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

use ExeQue\ZipStream\Events\Concerns\NormalizesEventTypes;

class EventQueue
{
    use NormalizesEventTypes;

    private array $handlers = [];
    private string $id;

    public function __construct()
    {
        $this->id = uniqid('zip-', true);
    }

    public function hasHandler(EventType $type, bool $exclusive = false): bool
    {
        $types = $exclusive ? [$type] : [EventType::Any, $type];

        foreach ($types as $t) {
            if (!empty($this->handlers[$t->name])) {
                return true;
            }
        }

        return false;
    }

    public function add(EventType|array $types, callable $handler): self
    {
        foreach ($this->normalizeEventTypes($types) as $type) {
            $this->handlers[$type->name][] = [
                'handler' => $handler,
            ];
        }

        return $this;
    }

    public function call(EventType|array $types, mixed ...$args): void
    {
        $args[] = $this->id;

        $types = [
            EventType::Any,
            ...$this->normalizeEventTypes($types)
        ];

        foreach ($types as $type) {
            foreach ($this->handlers[$type->name] ?? [] as $handler) {
                $handler['handler'](...$args);
            }
        }
    }
}
