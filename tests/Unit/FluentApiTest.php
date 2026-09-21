<?php

declare(strict_types=1);

use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Content\DiskFile;
use ExeQue\ZipStream\Content\LocalFile;
use ExeQue\ZipStream\Content\Raw;
use ExeQue\ZipStream\Pending;

$fluent = [Builder::class, Pending::class, Raw::class, LocalFile::class, DiskFile::class, Directory::class];

/**
 * @return string[]
 */
function publicMethods(string $class): array
{
    return array_map(
        fn (ReflectionMethod $method) => $method->getName(),
        (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
}

it('pairs every with* with a without*', function (string $class) {
    $methods = publicMethods($class);

    $missing = [];

    foreach ($methods as $method) {
        if (! str_starts_with($method, 'with') || str_starts_with($method, 'without')) {
            continue;
        }

        $negative = 'without' . substr($method, 4);

        if (! in_array($negative, $methods, true)) {
            $missing[] = "$method() has no $negative()";
        }
    }

    expect($missing)->toBe([]);
})->with($fluent);

it('keeps a bool out of a toggle', function (string $class) {
    $offenders = [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $name = $method->getName();

        if (! preg_match('/^(with|without|doesnt|inherit)/', $name)) {
            continue;
        }

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === 'bool') {
                // The pair says which way it goes; a flag would only say it twice.
                $offenders[] = "$name() takes a bool";
            }
        }
    }

    expect($offenders)->toBe([]);
})->with($fluent);

it('pairs every without* with a with*', function (string $class) {
    $methods = publicMethods($class);

    $missing = [];

    foreach ($methods as $method) {
        if (! str_starts_with($method, 'without')) {
            continue;
        }

        $positive = 'with' . substr($method, 7);

        if (! in_array($positive, $methods, true)) {
            $missing[] = "$method() has no $positive()";
        }
    }

    expect($missing)->toBe([]);
})->with($fluent);
