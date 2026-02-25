<?php

namespace Workbench\App\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->extend(Repository::class, function (Repository $config) {
            $config->set('filesystems.disks.local', [
                'driver' => 'local',
                'root' => dirname(__DIR__, 2) . '/storage/app',
                'serve' => true,
                'throw' => false,
                'report' => false,
            ]);

            return $config;
        });
    }
}
