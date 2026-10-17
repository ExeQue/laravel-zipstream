<?php

use Symfony\Component\Process\Process;

it('leaves the output layer without a warning under a sapi that sends', function () {
    $errors = tempnam(sys_get_temp_dir(), 'ziperr');
    $port = 49152 + random_int(0, 10000);

    $server = new Process(
        [PHP_BINARY, '-d', 'output_buffering=0', '-d', 'zlib.output_compression=0', '-S', "127.0.0.1:{$port}", '-t', __DIR__ . '/../Support/server'],
        env: ['ZIPSTREAM_ERROR_LOG' => $errors],
    );
    $server->start();

    try {
        $server->waitUntil(fn ($type, $output) => str_contains($output, 'started'));

        $body = file_get_contents("http://127.0.0.1:{$port}/");
    } finally {
        $server->stop();
    }

    expect($body)->toStartWith("PK\x03\x04")
        ->and(file_get_contents($errors))->toBe('');

    unlink($errors);
});
