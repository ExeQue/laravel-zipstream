<?php

namespace Tests\Support;

use ReflectionClass;

/**
 * Helper to "invade" an object using reflection.
 *
 * Provides convenient access to private/protected properties and methods
 * for testing purposes.
 *
 * @template T of object
 */
class Invader
{
    /**
     * @param T $target
     */
    public function __construct(private object $target)
    {
    }

    /**
     * @template TTarget of object
     * @param TTarget $target
     * @return self<TTarget>
     */
    public static function make(object $target): self
    {
        return new self($target);
    }

    /**
     * Get the (private/protected/public) property value.
     */
    public function __get(string $property): mixed
    {
        $ref = new ReflectionClass($this->target);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($this->target);
    }

    /**
     * Set the (private/protected/public) property value.
     */
    public function __set(string $property, mixed $value): void
    {
        $ref = new ReflectionClass($this->target);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($this->target, $value);
    }

    /**
     * Check if a (private/protected/public) property is set.
     */
    public function __isset(string $property): bool
    {
        $ref = new ReflectionClass($this->target);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);

        return $prop->isInitialized($this->target) && $prop->getValue($this->target) !== null;
    }

    /**
     * Call a (private/protected/public) method on the target.
     */
    public function __call(string $method, array $args): mixed
    {
        $ref = new ReflectionClass($this->target);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($this->target, ...$args);
    }

    /**
     * Returns the underlying target object (possibly modified).
     *
     * @return T
     */
    public function target(): object
    {
        return $this->target;
    }
}
