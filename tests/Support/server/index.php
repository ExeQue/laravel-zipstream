<?php

// Served by `php -S` in OutputLayerTest: the restore after the body only fails under a SAPI that sends.

use ExeQue\ZipStream\Builder;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemManager;

require __DIR__ . '/../../../vendor/autoload.php';

set_error_handler(function (int $errno, string $message): bool {
    file_put_contents(getenv('ZIPSTREAM_ERROR_LOG'), $message . PHP_EOL, FILE_APPEND);

    return true;
});

$container = new Container();

(new Builder(new FilesystemManager($container), new Repository(), $container))
    ->fromRaw('test.txt', 'content')
    ->toResponse(null)
    ->send();
