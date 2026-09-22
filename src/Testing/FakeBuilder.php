<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Testing;

use ExeQue\ZipStream\Builder;
use ExeQue\ZipStream\Events\EventQueue;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use Illuminate\Filesystem\FilesystemAdapter;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A builder that records what it was asked to archive instead of producing bytes.
 *
 * Everything up to the writing behaves as it would: entries verify themselves, options apply, events
 * fire. Only the output methods are replaced.
 */
class FakeBuilder extends Builder
{
    public function __construct(
        Factory $filesystemManager,
        Repository $config,
        private readonly ZipFake $fake,
        Container $container,
        ?EventQueue $events = null,
    ) {
        parent::__construct($filesystemManager, $config, $container, $events);

        $this->fake->record($this);
    }

    /**
     * The entries this archive would have contained.
     *
     * @return array<int, StreamableToZip|Directory>
     */
    public function entries(): array
    {
        return $this->pending()->entries();
    }

    public function saveToDisk(string|FilesystemAdapter $disk, string $path, array $options = []): ?int
    {
        $this->fake->recordSave($this, 'disk', $path);

        return 0;
    }

    public function saveToLocal(string $path): ?int
    {
        $this->fake->recordSave($this, 'local', $path);

        return 0;
    }

    public function toResponse($request): StreamedResponse
    {
        $this->fake->recordSave($this, 'response', null);

        return new StreamedResponse(fn () => null, 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename=archive.zip',
        ]);
    }

    public function toStream(): StreamInterface
    {
        $this->fake->recordSave($this, 'output', null);

        return Utils::streamFor('');
    }

    public function toString(): string
    {
        $this->fake->recordSave($this, 'output', null);

        return '';
    }
}
