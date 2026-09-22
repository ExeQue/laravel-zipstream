<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Concerns;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use ZipStream\ZipStream;

/**
 * What this package does to PHP's own output layer before a response streams.
 *
 * Part of Builder, kept apart so each concern sits with the state it owns.
 *
 * @internal
 */
trait InteractsWithOutputLayer
{
    private bool $managesOutput;

    private ?int $timeLimit = 0;

    public function manageOutput(): static
    {
        $this->managesOutput = true;

        return $this;
    }

    public function doesntManageOutput(): static
    {
        $this->managesOutput = false;

        return $this;
    }

    public function timeLimit(?int $seconds): static
    {
        $this->timeLimit = $seconds;

        return $this;
    }

    /**
     * Put PHP's output layer out of the way for the length of a streamed response.
     *
     * Every one of these is invisible until the first large download stalls in production, and none of
     * them is specific to one application.
     *
     * @return callable(): void  Puts back what was changed, once the response is done. Under a worker
     *                           that survives the request - Octane, RoadRunner - the process is reused,
     *                           so leaving any of it behind would reach the next request.
     */
    private function clearTheWay(): callable
    {
        // Symfony skips the SAPIs where output is not going to a socket, and so does this: a test
        // harness or a queue worker has its own reasons for the buffers it opened.
        if (!$this->managesOutput || in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            return static fn () => null;
        }

        // Would hold the whole archive in memory to recompute Content-Length.
        $zlib = ini_set('zlib.output_compression', '0');

        // ZipStream's own ob_flush() only reaches the topmost buffer; anything above it still swallows
        // the bytes. Flush them all, rather than discard what an application already wrote.
        SymfonyResponse::closeOutputBuffers(0, true);

        $timeLimit = null;

        if ($this->timeLimit !== null) {
            $timeLimit = (int) ini_get('max_execution_time');

            set_time_limit($this->timeLimit);
        }

        return static function () use ($zlib, $timeLimit): void {
            if ($zlib !== false) {
                ini_set('zlib.output_compression', $zlib);
            }

            if ($timeLimit !== null) {
                set_time_limit($timeLimit);
            }
        };
    }
}
