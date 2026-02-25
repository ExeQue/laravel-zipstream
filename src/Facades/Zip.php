<?php

namespace ExeQue\ZipStream\Facades;

use ExeQue\ZipStream\Builder;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin Builder
 */
class Zip extends Facade
{
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return Builder::class;
    }
}
