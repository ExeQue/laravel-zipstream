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

        $this->entries[] = $streamable;

        return $this;
    }

    public function process(ZipStream $stream): void
    {
        $entries = collect($this->entries);

        $directories = $entries->filter(fn ($entry) => $entry instanceof Directory);
        $files = $entries->filter(fn ($entry) => $entry instanceof StreamableToZip);

        $directories->each(function (Directory $directory) use ($stream) {
            $options = $directory->getFileOptions();

            $stream->addDirectory(
                fileName: $directory->destination(),
                comment: $options->comment,
                lastModificationDateTime: $options->lastModified,
            );
        });

        $files->each(function (StreamableToZip $file) use ($stream) {
            $options = $file instanceof HasFileOptions
                ? $file->getFileOptions()
                : new FileOptions();

            $stream->addFileFromCallback(
                fileName: $file->destination(),
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
