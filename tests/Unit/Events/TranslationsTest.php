<?php

declare(strict_types=1);

use ExeQue\ZipStream\Events\Data\Bytes;
use ExeQue\ZipStream\Events\Data\Entries;
use Illuminate\Support\Facades\App;

covers(Entries::class);

$locales = array_map('basename', glob(dirname(__DIR__, 3) . '/lang/*'));

afterEach(fn () => App::setLocale('en'));

it('renders every shipped locale, for every count', function (string $locale) {
    App::setLocale($locale);

    foreach ([0, 1, 2, 5, 21, 125] as $count) {
        $rendered = (new Entries(1, $count))->toHuman();

        // A missing plural form shows up as the untranslated key, or as a leftover placeholder.
        expect($rendered)->not->toContain('laravel-zipstream::')
            ->and($rendered)->not->toContain(':total')
            ->and($rendered)->toContain((string) $count);
    }

    $bytes = (new Bytes(5120, 67108864))->toHuman();

    expect($bytes)->not->toContain('laravel-zipstream::')
        ->and($bytes)->toContain('5.00 KB')
        ->and($bytes)->toContain('64.00 MB');
})->with($locales);

it('ships the languages the readme promises', function () use ($locales) {
    expect($locales)->toContain('da', 'de', 'en', 'nb', 'sv');
});
