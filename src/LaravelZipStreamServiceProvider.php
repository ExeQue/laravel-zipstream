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

        $this->publishes([
            __DIR__ . '/../lang' => $this->app->langPath('vendor/laravel-zipstream'),
        ], 'laravel-zipstream-translations');

        $this->mergeConfigFrom(__DIR__ . '/../config/laravel-zipstream.php', 'laravel-zipstream');

        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'laravel-zipstream');
    }
}
