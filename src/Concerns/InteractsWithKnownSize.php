<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Concerns;

use ExeQue\ZipStream\Content\DiskFile;
use ExeQue\ZipStream\Content\LocalFile;
use ExeQue\ZipStream\Events\EventQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use ZipStream\Exception\OverflowException;
use ZipStream\Exception\SimulationFileUnknownException;
use ZipStream\OperationMode;

/**
 * Working out how large the archive will be before writing it.
 *
 * Part of Builder, kept apart so each concern sits with the state it owns.
 *
 * @internal
 */
trait InteractsWithKnownSize
{
    private bool $withContentLength = false;

    private bool $withKnownSize = false;

    /** Memoised: working the size out walks every entry, and both the header and progress want it. */
    private ?int $knownSize = null;

    private bool $knownSizeResolved = false;

    public function withContentLength(): static
    {
        $this->withContentLength = true;

        return $this->withKnownSize();
    }

    public function withoutContentLength(): static
    {
        $this->withContentLength = false;

        return $this->withoutKnownSize();
    }

    public function withKnownSize(): static
    {
        $this->withKnownSize = true;

        return $this;
    }

    public function withoutKnownSize(): static
    {
        $this->withKnownSize = false;

        return $this;
    }

    /**
     * Fill in the sizes a known size needs, in as few requests as it can.
     *
     * An entry added by hand carries no size, and asking the disk for each one is a request per entry.
     * The listing a directory needs anyway covers every entry in it, so the entries are grouped by disk
     * and directory and each directory is listed once - the same cost as fromDiskDirectory().
     *
     * Only entries without a size of their own are touched, and only when a size is being asked for.
     */
    private function fillMissingSizes(): void
    {
        /** @var array<string, array{disk: FilesystemAdapter, directory: string, entries: DiskFile[]}> $lookups */
        $lookups = [];

        foreach ($this->pending->entries() as $entry) {
            if (!$entry instanceof DiskFile && !$entry instanceof LocalFile) {
                continue;
            }

            if ($entry->getFileOptions()->exactSize !== null) {
                continue;
            }

            if ($entry instanceof LocalFile) {
                // A local stat costs nothing worth grouping for.
                $size = @filesize($entry->source());

                if ($size !== false) {
                    $entry->exactSize($size);
                }

                continue;
            }

            $directory = dirname($entry->source());
            $directory = $directory === '.' ? '' : $directory;
            $key = spl_object_id($entry->disk()) . ':' . $directory;

            $lookups[$key] ??= ['disk' => $entry->disk(), 'directory' => $directory, 'entries' => []];
            $lookups[$key]['entries'][] = $entry;
        }

        foreach ($lookups as ['disk' => $disk, 'directory' => $directory, 'entries' => $entries]) {
            $sizes = [];

            foreach ($disk->getDriver()->listContents($directory, false) as $attributes) {
                if ($attributes->isFile() && $attributes->fileSize() !== null) {
                    $sizes[$attributes->path()] = $attributes->fileSize();
                }
            }

            foreach ($entries as $entry) {
                if (isset($sizes[ltrim($entry->source(), '/')])) {
                    $entry->exactSize($sizes[ltrim($entry->source(), '/')]);
                }
            }
        }
    }

    /**
     * Give progress its target, where one can be had.
     */
    private function resolveKnownSize(): void
    {
        if (!$this->knownSizeResolved) {
            if ($this->withKnownSize) {
                $this->fillMissingSizes();
            }

            $this->knownSize = $this->withKnownSize ? $this->calculateSize() : null;
            $this->knownSizeResolved = true;
        }

        $this->events->state()->bytesTotal = $this->knownSize;
        $this->events->state()->bytesDone = 0;
    }

    private function resolvedSize(): ?int
    {
        if (!$this->knownSizeResolved) {
            $this->fillMissingSizes();

            $this->knownSize = $this->calculateSize();
            $this->knownSizeResolved = true;
        }

        return $this->knownSize;
    }

    /**
     * Determine the exact archive size without performing any I/O.
     *
     * Returns null when a size cannot be known up front - every entry has to use
     * CompressionMethod::STORE and carry a known exactSize for the simulation to succeed.
     */
    private function calculateSize(): ?int
    {
        $sink = fopen('php://memory', 'w+b');
        $simulation = $this->prepareZipStream($sink, OperationMode::SIMULATE_STRICT);

        try {
            // A fresh queue fires no user handlers, and - since it has no ProcessError
            // handler - lets a failed simulation bubble out instead of being swallowed.
            $this->pending->process($simulation, new EventQueue(), $this->getZipOptions());

            return $simulation->finish();
        } catch (SimulationFileUnknownException|OverflowException) {
            return null;
        } finally {
            fclose($sink);
        }
    }
}
