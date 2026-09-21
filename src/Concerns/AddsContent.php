<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Concerns;

use Closure;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Content\DiskFile;
use ExeQue\ZipStream\Content\LocalFile;
use ExeQue\ZipStream\Content\Raw;
use ExeQue\ZipStream\Contracts\CanStreamToZip;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Str;

/**
 * Everything that puts an entry into the archive.
 *
 * Part of Builder, kept apart so each concern sits with the state it owns.
 *
 * @internal
 */
trait AddsContent
{
    public function add(StreamableToZip|CanStreamToZip|Directory $content, ?callable $modify = null): static
    {
        $modify = $this->resolveModifierCallback($modify);

        $this->pending->add(
            tap($content, $modify),
        );

        return $this;
    }

    public function fromDisk(
        string|FilesystemAdapter $disk,
        string $source,
        ?string $destination = null,
        ?callable $modify = null,
    ): static {
        $disk = is_string($disk) ? $this->filesystemManager->disk($disk) : $disk;

        $destination ??= basename($source);

        return $this->add(DiskFile::make($disk, $source, $destination), $modify);
    }

    public function fromDiskDirectory(
        string|FilesystemAdapter $disk,
        string $source,
        ?string $destination = null,
        bool $recursive = true,
        ?callable $modify = null,
    ): static {
        $disk = is_string($disk) ? $this->filesystemManager->disk($disk) : $disk;

        $source = trim($source, '/');
        $destination = trim($destination ?? basename($source), '/');
        $modify = $this->resolveModifierCallback($modify);

        foreach ($disk->getDriver()->listContents($source, $recursive) as $attributes) {
            if (!$attributes->isFile()) {
                continue;
            }

            $file = DiskFile::make(
                $disk,
                $attributes->path(),
                trim($destination . '/' . Str::after($attributes->path(), $source), '/'),
            );

            if ($attributes->fileSize() !== null) {
                $file->exactSize($attributes->fileSize());
            }

            if ($attributes->lastModified() !== null) {
                $file->lastModified($attributes->lastModified());
            }

            $this->pending->add(tap($file, $modify), verify: false);
        }

        return $this;
    }

    public function fromLocal(
        string $source,
        ?string $destination = null,
        ?callable $modify = null,
    ): static {
        return $this->add(LocalFile::make($source, $destination), $modify);
    }

    public function fromLocalDirectory(
        string $source,
        ?string $destination = null,
        bool $recursive = true,
        ?callable $modify = null,
    ): static {
        $source = rtrim($source, '/\\');
        $destination = trim($destination ?? basename($source), '/');
        $modify = $this->resolveModifierCallback($modify);

        $files = $recursive ? Filesystem::allFiles($source) : Filesystem::files($source);

        foreach ($files as $file) {
            $entry = LocalFile::make(
                $file->getPathname(),
                trim($destination . '/' . str_replace('\\', '/', $file->getRelativePathname()), '/'),
            );

            $entry->exactSize($file->getSize())->lastModified($file->getMTime());

            $this->pending->add(tap($entry, $modify), verify: false);
        }

        return $this;
    }

    public function fromRaw(
        string $destination,
        string $content,
        ?callable $modify = null,
    ): static {
        return $this->add(Raw::make($destination, $content), $modify);
    }

    public function emptyDirectory(string $directory, ?callable $modify = null): static
    {
        return $this->add(new Directory($directory), $modify);
    }

    private function resolveModifierCallback(?callable $modify): Closure
    {
        return ($modify ?? static fn ($optionable) => null)(...);
    }
}
