<?php

namespace ExeQue\ZipStream;

use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Contracts\CanStreamToZip;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use ExeQue\ZipStream\Contracts\Verifiable;
use ExeQue\ZipStream\Options\FileOptions;
use ZipStream\ZipStream;

class Pending
{
    /** @var StreamableToZip[]|Directory[] */
    private array $entries;

    private array $directories = [];

    public function add(StreamableToZip|CanStreamToZip|Directory $streamable): static
    {
        if ($streamable instanceof Verifiable) {
            $streamable->verify();
        }

        if ($streamable instanceof CanStreamToZip) {
            $entries = $streamable->getStreamableToZip();

            if (is_iterable($entries)) {
                foreach ($entries as $entry) {
                    $this->add($entry);
                }

                return $this;
            }

            return $this->add($entries);
        }

        $destination = $streamable->destination();

        $this->entries[$destination] = $streamable;

        return $this;
    }

    public function process(ZipStream $stream): void
    {
        [$files, $directories] = collect($this->entries)
            ->partition(fn ($entry) => $entry instanceof StreamableToZip)
            ->all();

        $directories->each(function (Directory $directory, string $destination) use ($stream) {
            $options = $directory->getFileOptions();

            $stream->addDirectory(
                fileName: $destination,
                comment: $options->comment,
                lastModificationDateTime: $options->lastModified,
            );
        });

        $files->each(function (StreamableToZip $file, string $destination) use ($stream) {
            $options = $file instanceof HasFileOptions
                ? $file->getFileOptions()
                : new FileOptions();

            $stream->addFileFromCallback(
                fileName: $destination,
                callback: fn () => $file->stream(),
                comment: $options->comment,
                compressionMethod: $options->compressionMethod,
                deflateLevel: $options->deflateLevel,
                lastModificationDateTime: $options->lastModified,
                enableZeroHeader: $options->enableZeroHeader
            );
        });
    }
}
