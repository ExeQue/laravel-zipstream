<?php

namespace Tests;

use ExeQue\ZipStream\LaravelZipStreamServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private array $testFiles = [];

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaravelZipStreamServiceProvider::class];
    }

    /**
     * @return false|string
     */
    public function createTestFile(): string
    {
        $name = tempnam(sys_get_temp_dir(), 'ziptest');

        $this->testFiles[$name] = $name;

        return $name;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->testFiles as $file) {
            unlink($file);
        }

        $this->testFiles = [];
    }
}
