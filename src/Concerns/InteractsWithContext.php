<?php

namespace ExeQue\ZipStream\Concerns;

trait InteractsWithContext
{
    /** @var array<string, mixed> */
    private array $context = [];

    /**
     * Attach whatever the application needs to recognise this entry again.
     *
     * Events and exceptions about the entry carry it back, so an archive built from database rows does
     * not need its own map from destination to record.
     *
     * @param  array<string, mixed>  $context
     */
    public function context(array $context): static
    {
        $this->context = [...$this->context, ...$context];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
