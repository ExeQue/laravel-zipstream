<?php

namespace ExeQue\ZipStream\Facades;

use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Testing\ZipFake;
use ExeQue\ZipStream\Testing\FakeBuilder;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin Builder
 *
 * @method static ZipFake assertAdded(string $destination)
 * @method static ZipFake assertNotAdded(string $destination)
 * @method static ZipFake assertAddedCount(int $expected)
 * @method static ZipFake assertNothingAdded()
 * @method static ZipFake assertSavedToDisk(?string $path = null)
 * @method static ZipFake assertSavedToLocal(?string $path = null)
 * @method static ZipFake assertStreamed()
 * @method static ZipFake assertNothingSaved()
 */
class Zip extends Facade
{
    protected static $cached = false;

    /** Assertions live on the fake, and are only reachable once one is in place. */
    private const ASSERTIONS = [
        'assertAdded', 'assertNotAdded', 'assertAddedCount', 'assertNothingAdded',
        'assertSavedToDisk', 'assertSavedToLocal', 'assertStreamed', 'assertNothingSaved',
    ];

    protected static function getFacadeAccessor(): string
    {
        return Builder::class;
    }

    /**
     * Record what archives would have contained instead of building them.
     *
     * Every Zip::... call from here on hands back a builder that writes nothing, so a test about which
     * entries an archive gets needs no storage.
     */
    public static function fake(): ZipFake
    {
        $fake = new ZipFake();

        static::$app->instance(ZipFake::class, $fake);

        static::$app->bind(Builder::class, fn ($app) => new FakeBuilder(
            $app->make(Factory::class),
            $app->make(Repository::class),
            $fake,
            $app,
        ));

        return $fake;
    }

    /**
     * @param  array<int, mixed>  $args
     */
    public static function __callStatic($method, $args)
    {
        if (in_array($method, self::ASSERTIONS, true)) {
            return static::$app->make(ZipFake::class)->$method(...$args);
        }

        return parent::__callStatic($method, $args);
    }
}
