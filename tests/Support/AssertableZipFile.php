<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use ZipArchive;

class AssertableZipFile
{
    private string $tmpDir;

    public function __construct(
        private string $file,
    ) {
    }

    private function unpack(): void
    {
        if (isset($this->tmpDir)) {
            return;
        }

        $this->tmpDir = $this->createTmpDir();

        $archive = new ZipArchive();

        $archive->open($this->file);

        $archive->extractTo($this->tmpDir);
    }

    public function path(string $path): AssertableZipPath
    {
        $this->unpack();

        return new AssertableZipPath($this, $this->resolvePath($path));
    }

    private function resolvePath(string $name): string
    {
        $this->unpack();

        return $this->tmpDir . '/' . $name;
    }

    public function cleanup(): void
    {
        if (isset($this->tmpDir)) {
            File::deleteDirectory($this->tmpDir);
            unset($this->tmpDir);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function createTmpDir(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ziptest-extract');
        unlink($tmp);
        mkdir($tmp);

        return $tmp;
    }
}
