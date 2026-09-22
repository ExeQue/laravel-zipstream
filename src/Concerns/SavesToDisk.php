<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Concerns;

use ExeQue\ZipStream\Events\SavedToDisk;
use ExeQue\ZipStream\Events\SavingToDisk;
use ExeQue\ZipStream\Exceptions\ArchiveDiscardedException;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Filesystem\FilesystemAdapter;
use Throwable;

/**
 * Writing the archive to a Laravel disk, and leaving nothing behind when that fails.
 *
 * Part of Builder, kept apart so each concern sits with the state it owns.
 *
 * @internal
 */
trait SavesToDisk
{
    public function saveToDisk(string|FilesystemAdapter $disk, string $path, array $options = []): ?int
    {
        $disk = is_string($disk) ? $this->filesystemManager->disk($disk) : $disk;

        $this->events->dispatch(SavingToDisk::class, $disk, $path);

        // Only an S3 disk hands out a client, and only S3 can leave a multipart upload behind.
        $client = method_exists($disk, 'getClient') ? $disk->getClient() : null;
        $upload = null;

        if ($client !== null) {
            $options = $this->capturingMultipartUpload($options, $upload);
        }

        $existed = $disk->exists($path);

        $size = null;
        $failure = null;

        $handle = StreamWrapper::getResource($this->rewindableHead($this->lazyArchive($size, $failure)));

        try {
            $disk->writeStream($path, $handle, $options);
        } catch (Throwable $e) {
            $failure ??= $e;
        } finally {
            // A disk may already have closed it through the PSR-7 stream it wrapped it in.
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($failure !== null) {
            $this->cleanUpFailedWrite($disk, $path, $existed, $client, $upload);

            if ($failure instanceof ArchiveDiscardedException) {
                return null;
            }

            // The disk wraps (or, when not set to throw, swallows) errors raised while reading.
            throw $failure;
        }

        $this->events->dispatch(SavedToDisk::class, $disk, $path, $size);

        return $size;
    }

    /**
     * Remember the multipart upload S3 starts, so that a failed write can abort it.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, string>|null  $upload  Receives Bucket/Key/UploadId once a part is on its way.
     * @return array<string, mixed>
     */
    private function capturingMultipartUpload(array $options, ?array &$upload): array
    {
        $before = $options['before_upload'] ?? null;

        $options['before_upload'] = function ($command) use ($before, &$upload): void {
            if (isset($command['UploadId'])) {
                $upload = [
                    'Bucket'   => $command['Bucket'],
                    'Key'      => $command['Key'],
                    'UploadId' => $command['UploadId'],
                ];
            }

            if ($before !== null) {
                $before($command);
            }
        };

        return $options;
    }

    /**
     * Leave the disk as it was before the failed write.
     *
     * @param  array<string, string>|null  $upload
     */
    private function cleanUpFailedWrite(
        FilesystemAdapter $disk,
        string $path,
        bool $existed,
        mixed $client,
        ?array $upload,
    ): void {
        if ($client !== null && $upload !== null) {
            try {
                // The SDK keeps the parts of a failed multipart upload, billed and hidden from a listing.
                $client->abortMultipartUpload($upload);
            } catch (Throwable) {
                // Nothing left to do about it - the original failure is the one worth reporting.
            }
        }

        if (! $existed) {
            // Only what this call created: a file that was already there is not ours to remove.
            $disk->delete($path);
        }
    }
}
