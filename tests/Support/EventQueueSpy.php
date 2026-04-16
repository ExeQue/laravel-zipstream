<?php

namespace Tests\Support;

use ExeQue\ZipStream\Events\EventType;
use ExeQue\ZipStream\Events\EventQueue as BaseEventQueue;

class EventQueueSpy extends BaseEventQueue
{
    private array $calls = [];
    private ?array $nextTypes = null;

    public function __construct()
    {
        parent::__construct();

        // Persistent Any handler records args (including appended id). It will also consume any queued types set by call().
        parent::add(EventType::Any, function (...$args) {
            $typesCalled = $this->nextTypes ?? [EventType::Any];

            $this->calls[] = [
                'types' => [EventType::Any],
                'types_called' => $typesCalled,
                'args'  => $args,
            ];

            $this->nextTypes = null;
        });
    }

    public function calls(): array
    {
        return $this->calls;
    }

    public function assertCount(int $expected): self
    {
        expect(count($this->calls))->toBe($expected);

        return $this;
    }

    public function assertAt(int $position, callable $assertion): self
    {
        // position is zero-indexed
        $index = $position;

        expect(array_key_exists($index, $this->calls))->toBeTrue();

        $call = $this->calls[$index];
        $types = $call['types'];
        $first = count($types) === 1 ? $types[0] : $types;

        $assertion($first, ...$call['args']);

        return $this;
    }

    public function assertArg(int $position, int $argPosition, callable $assertion): self
    {
        // both position and argPosition are zero-indexed
        $index = $position;
        expect(array_key_exists($index, $this->calls))->toBeTrue();

        $args = $this->calls[$index]['args'];
        $argIndex = $argPosition;

        expect(array_key_exists($argIndex, $args))->toBeTrue();

        $assertion($args[$argIndex]);

        return $this;
    }

    private function normalizeEventTypesLocal(EventType|array $types): array
    {
        $types = $types instanceof EventType ? [$types] : $types;

        foreach ($types as $type) {
            if ($type instanceof EventType) {
                continue;
            }

            throw new \InvalidArgumentException(
                sprintf(
                    'Expected an instance of %s, got %s',
                    EventType::class,
                    get_debug_type($type),
                ),
            );
        }

        return $types;
    }

    public function call(EventType|array $types, mixed ...$args): void
    {
        // queue normalized types for the Any handler to consume so we can record types alongside args
        $norm = $this->normalizeEventTypesLocal($types);
        $this->nextTypes = array_merge([EventType::Any], $norm);

        parent::call($types, ...$args);
    }

    /**
     * Assert that the sequence of event types emitted matches the provided array. Each element in
     * the provided array is an EventType that is expected to be present in the types_called for the
     * corresponding call (the Any-prefixed types list).
     */
    public function assertTypes(array $expectedTypes): self
    {
        $this->assertCount(count($expectedTypes));

        foreach ($expectedTypes as $i => $expected) {
            $this->assertAt($i, function ($type, ...$args) use ($i, $expected) {
                $called = $this->calls[$i]['types_called'] ?? $this->calls[$i]['types'];

                if (is_array($called)) {
                    expect(in_array($expected, $called, true))->toBeTrue();
                } else {
                    expect($called)->toBe($expected);
                }
            });
        }

        return $this;
    }
}
