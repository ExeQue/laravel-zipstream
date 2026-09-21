<?php

declare(strict_types=1);

$documents = array_merge(
    glob(dirname(__DIR__, 2) . '/docs/*.md'),
    glob(dirname(__DIR__, 2) . '/contributing/*.md'),
    [dirname(__DIR__, 2) . '/UPGRADE.md'],
);

$withToc = array_values(array_filter(
    $documents,
    fn (string $path) => str_contains(file_get_contents($path), '<!-- toc -->'),
));

it('keeps the table of contents in step with the headings', function (string $path) {
    $lines = explode("\n", str_replace("\r\n", "\n", file_get_contents($path)));

    $headings = [];
    $listed = [];
    $inToc = false;
    $fenced = false;

    foreach ($lines as $line) {
        $line = rtrim($line);

        if (str_starts_with($line, '```')) {
            $fenced = ! $fenced;
        }

        if ($line === '<!-- toc -->') {
            $inToc = true;

            continue;
        }

        if ($line === '<!-- /toc -->') {
            $inToc = false;

            continue;
        }

        if ($inToc) {
            if (preg_match('/\[(.+)\]\(#(.+)\)/', $line, $match)) {
                $listed[] = $match[1];
            }

            continue;
        }

        if (! $fenced && preg_match('/^#{2,3} (.+)$/', $line, $match)) {
            $headings[] = $match[1];
        }
    }

    expect($listed)->toBe($headings);
})->with($withToc);

it('has a table of contents on every page that needs one', function (string $path) use ($withToc) {
    $headings = preg_match_all('/^#{2,3} /m', file_get_contents($path));

    if ($headings < 3) {
        expect(true)->toBeTrue();

        return;
    }

    expect(in_array($path, $withToc, true))
        ->toBeTrue(basename($path) . ' has ' . $headings . ' headings but no table of contents.');
})->with($documents);
