<?php

declare(strict_types=1);

namespace ExeQue\ZipStream;

use Illuminate\Support\ServiceProvider;

class LaravelZipStreamServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/laravel-zipstream.php' => config_path('laravel-zipstream.php'),
        ], 'laravel-zipstream-config');

        $this->mergeConfigFrom(__DIR__ . '/../config/laravel-zipstream.php', 'laravel-zipstream');
    }
}
