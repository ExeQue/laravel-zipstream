<?php

namespace Tests\Support;

use Illuminate\Filesystem\LocalFilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter as Flysystem;

use function Illuminate\Filesystem\join_paths;

class AssertableZipPath
{
    public function __construct(
        private AssertableZipFile $zip,
        private string            $path
    ) {
    }

    /**
     * Asserts that the path exists.
     */
    public function exists(string $message = ''): static
    {
        $message = $message ?: "Failed asserting that path \"$this->path\" exists.";

        expect($this->path)->toBeFile($message);

        return $this;
    }

    // region Files

    /**
     * Asserts that the path is a file.
     */
    public function isFile(string $message = ''): static
    {
        $message = $message ?: "Failed asserting that path \"$this->path\" is a file.";

        expect($this->path)->toBeFile($message)->not()->toBeDirectory($message);

        return $this;
    }

    /**
     * Asserts that the file contains the expected content.
     */
    public function contains(string $expected, string $message = ''): static
    {
        $this->isFile();

        $message = $message ?: "Failed asserting that path \"$this->path\" has the expected content.";

        expect(file_get_contents($this->path))->toBe($expected, $message);

        return $this;
    }

    /**
     * Asserts that the file has the same content as the given path.
     */
    public function sameAs(string $path, string $message = ''): static
    {
        $message = $message ?: "Failed asserting that path \"$this->path\" has the same content as \"$path\".";

        return $this->contains(file_get_contents($path), $message);
    }

    // endregion

    // region Directories

    /**
     * Asserts that the path is a directory.
     */
    public function isDirectory(string $message = ''): static
    {
        $message = $message ?: "Failed asserting that path \"$this->path\" is a directory.";

        expect($this->path)->toBeDirectory($message);

        return $this;
    }

    /**
     * Returns a new AssertableZipPath instance for the given path within the directory.
     */
    public function inDir(string $path): static
    {
        $this->isDirectory();

        return new static($this->zip, join_paths($this->path, $path));
    }

    /**
     * Asserts that the directory has the expected number of files.
     */
    public function fileCount(int $expected, bool $recursive = false, string $message = ''): static
    {
        $this->isDirectory();

        $message = $message ?: "Failed asserting that path \"$this->path\" has the expected number of files.";

        $adapter = $this->adapter();

        $files = $adapter->files($this->path, $recursive);

        expect(count($files))->toBe($expected, $message);

        return $this;
    }

    // endregion

    /**
     * Returns a new AssertableZipPath instance for the given path within the zip file.
     */
    public function and(string $path): self
    {
        return $this->zip->path($path);
    }

    private function adapter(): LocalFilesystemAdapter
    {
        $flysystem = new Flysystem($this->path);

        return new LocalFilesystemAdapter(
            new Filesystem($flysystem),
            $flysystem
        );
    }
}
