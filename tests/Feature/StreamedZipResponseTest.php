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
    ob_start();
    $response->baseResponse->sendContent();
    $content = ob_get_clean();

    expect($content)->not->toBeEmpty();

    $tmpFile = $this->createTestFile();
    file_put_contents($tmpFile, $content);

    $zip = new AssertableZipFile($tmpFile);
    $zip->path('test.txt')
        ->exists()
        ->contains('content from route');
});
