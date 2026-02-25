<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private array $testFiles = [];

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
