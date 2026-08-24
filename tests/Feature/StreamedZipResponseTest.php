<?php

declare(strict_types=1);

use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Facades\Zip;
use Illuminate\Support\Facades\Route;
use Tests\Support\AssertableZipFile;

covers(Builder::class);
covers(Zip::class);

it('can stream a zip response from a route', function () {
    Route::get('/download-zip', function () {
        return Zip::as('feature-test.zip')
            ->fromRaw('test.txt', 'content from route')
            ->toResponse(request());
    });

    $response = $this->get('/download-zip');

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'application/x-zip')
        ->assertHeader('Content-Disposition', 'attachment; filename="feature-test.zip"');

    // To verify content, we need to capture the streamed output
    $content = captureStreamedOutput(fn () => $response->baseResponse->sendContent());

    expect($content)->not->toBeEmpty();

    $tmpFile = $this->createTestFile();
    file_put_contents($tmpFile, $content);

    $zip = new AssertableZipFile($tmpFile);
    $zip->path('test.txt')
        ->exists()
        ->contains('content from route');
});

it('sends a Content-Length that matches the streamed archive', function () {
    Route::get('/download-sized-zip', function () {
        return Zip::as('sized.zip')
            ->store()
            ->withContentLength()
            ->fromRaw('test.txt', 'content from route')
            ->toResponse(request());
    });

    $response = $this->get('/download-sized-zip');

    $response->assertStatus(200)->assertHeader('Content-Type', 'application/x-zip');

    $content = captureStreamedOutput(fn () => $response->baseResponse->sendContent());

    expect($response->headers->get('Content-Length'))->toBe((string) strlen($content));

    $tmpFile = $this->createTestFile();
    file_put_contents($tmpFile, $content);

    (new AssertableZipFile($tmpFile))
        ->path('test.txt')
        ->exists()
        ->contains('content from route');
});
