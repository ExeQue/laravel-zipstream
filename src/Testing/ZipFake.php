<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Testing;

use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Contracts\StreamableToZip;
use PHPUnit\Framework\Assert;

/**
 * What the archives built during a test contained, and where they were sent.
 *
 * Records rather than writes, so a test about which entries an archive gets needs no storage at all.
 */
class ZipFake
{
    /** @var FakeBuilder[] */
    private array $builders = [];

    /** @var array<int, array{destination: string, path: string|null, builder: FakeBuilder}> */
    private array $saved = [];

    public function record(FakeBuilder $builder): void
    {
        $this->builders[] = $builder;
    }

    public function recordSave(FakeBuilder $builder, string $destination, ?string $path): void
    {
        $this->saved[] = ['destination' => $destination, 'path' => $path, 'builder' => $builder];
    }

    /**
     * Every entry added to any archive, by its path inside the archive.
     *
     * @return string[]
     */
    public function added(): array
    {
        $added = [];

        foreach ($this->builders as $builder) {
            foreach ($builder->entries() as $entry) {
                $added[] = $entry->destination();
            }
        }

        return $added;
    }

    /**
     * @return array<int, StreamableToZip|Directory>
     */
    public function entries(): array
    {
        return array_merge(...array_map(fn (FakeBuilder $builder) => $builder->entries(), $this->builders)) ?: [];
    }

    public function assertAdded(string $destination): self
    {
        Assert::assertContains(
            $destination,
            $this->added(),
            "Failed asserting that [$destination] was added to an archive.",
        );

        return $this;
    }

    public function assertNotAdded(string $destination): self
    {
        Assert::assertNotContains(
            $destination,
            $this->added(),
            "Failed asserting that [$destination] was not added to an archive.",
        );

        return $this;
    }

    public function assertAddedCount(int $expected): self
    {
        Assert::assertCount(
            $expected,
            $this->added(),
            'Failed asserting the number of entries added to archives.',
        );

        return $this;
    }

    public function assertNothingAdded(): self
    {
        return $this->assertAddedCount(0);
    }

    public function assertSavedToDisk(?string $path = null): self
    {
        return $this->assertSaved('disk', $path);
    }

    public function assertSavedToLocal(?string $path = null): self
    {
        return $this->assertSaved('local', $path);
    }

    public function assertStreamed(): self
    {
        return $this->assertSaved('response', null);
    }

    public function assertNothingSaved(): self
    {
        Assert::assertSame([], $this->saved, 'Failed asserting that no archive was saved.');

        return $this;
    }

    private function assertSaved(string $destination, ?string $path): self
    {
        $matches = array_filter(
            $this->saved,
            fn (array $save) => $save['destination'] === $destination
                && ($path === null || $save['path'] === $path),
        );

        Assert::assertNotEmpty(
            $matches,
            $path === null
                ? "Failed asserting that an archive was saved to [$destination]."
                : "Failed asserting that an archive was saved to [$destination] at [$path].",
        );

        return $this;
    }
}
