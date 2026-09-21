<?php

declare(strict_types=1);

use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Contracts\ArchiveBuilder;
use Illuminate\Support\Traits\Macroable;

covers(ArchiveBuilder::class);

/**
 * Macros are the framework's, not this package's, and a constructor is not part of an interface.
 */
function notPartOfTheContract(): array
{
    return array_merge(
        array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            (new ReflectionClass(Macroable::class))->getMethods(),
        ),
        ['__construct'],
    );
}

it('declares every public method the builder has', function () {
    $exempt = notPartOfTheContract();

    $public = array_map(
        fn (ReflectionMethod $method) => $method->getName(),
        (new ReflectionClass(Builder::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    $declared = array_map(
        fn (ReflectionMethod $method) => $method->getName(),
        (new ReflectionClass(ArchiveBuilder::class))->getMethods(),
    );

    $missing = array_values(array_diff($public, $declared, $exempt));

    // A public method that is not on the interface is public API nobody agreed to.
    expect($missing)->toBe([]);
});

it('declares nothing the builder does not have', function () {
    $declared = array_map(
        fn (ReflectionMethod $method) => $method->getName(),
        (new ReflectionClass(ArchiveBuilder::class))->getMethods(),
    );

    $public = array_map(
        fn (ReflectionMethod $method) => $method->getName(),
        (new ReflectionClass(Builder::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect(array_values(array_diff($declared, $public)))->toBe([]);
});

it('exposes no public properties at all', function () {
    $properties = array_map(
        fn (ReflectionProperty $property) => $property->getName(),
        (new ReflectionClass(Builder::class))->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    // State belongs behind the methods above; an interface cannot promise a property's meaning.
    expect($properties)->toBe([]);
});

it('is what the builder is type-hinted as', function () {
    expect(Builder::class)->toImplement(ArchiveBuilder::class);
});
