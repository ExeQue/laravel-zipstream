<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events\Concerns;

use ExeQue\ZipStream\Events\EventType;
use InvalidArgumentException;

trait NormalizesEventTypes
{
    /**
     * @return EventType[]
     */
    private function normalizeEventTypes(EventType|array $types): array
    {
        $types = $types instanceof EventType ? [$types] : $types;

        foreach ($types as $type) {
            if ($type instanceof EventType) {
                continue;
            }

            throw new InvalidArgumentException(
                sprintf(
                    'Expected an instance of %s, got %s',
                    EventType::class,
                    get_debug_type($type),
                ),
            );
        }

        return $types;
    }
}
