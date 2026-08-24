<?php

require_once __DIR__ . '/Contracts/FileTests.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Capture output written to php://output by a streamed response.
 *
 * The buffer is deliberately started without PHP_OUTPUT_HANDLER_FLUSHABLE so that
 * ZipStream's ob_flush() is skipped and the bytes stay in the buffer for assertion.
 */
function captureStreamedOutput(Closure $callback): string
{
    ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_REMOVABLE);

    try {
        $callback();
    } finally {
        $content = ob_get_clean();
    }

    return $content;
}
