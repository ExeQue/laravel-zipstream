<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Contracts;

use DateInterval;
use ExeQue\ZipStream\Content\Directory;
use ExeQue\ZipStream\Events\Contracts\Event;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Filesystem\FilesystemAdapter;
use Psr\Http\Message\StreamInterface;

/**
 * Everything an archive can be asked to do.
 *
 * The public surface of Builder, as a type to hint against. A test keeps the two in step, so a public
 * method that is not here is not public API.
 */
interface ArchiveBuilder extends HasZipOptions, Responsable
{
    /**
     * Name the archive. A missing .zip extension is added.
     *
     * The name reaches the browser in Content-Disposition, escaped, with an RFC 6266 fallback for
     * anything outside ASCII. A name containing CR or LF is refused.
     */
    public function as(string $filename): static;

    /**
     * Add an entry, or anything that can hand over entries of its own.
     *
     * @param  callable(mixed): void|null  $modify  Called with the entry, to set its options
     */
    public function add(StreamableToZip|CanStreamToZip|Directory $content, ?callable $modify = null): static;

    /**
     * Add a single file from a Laravel disk.
     *
     * @param  string|null  $destination  The path inside the archive. Defaults to the source's own name.
     * @param  callable(mixed): void|null  $modify
     */
    public function fromDisk(FilesystemAdapter|string $disk, string $source, ?string $destination = null, ?callable $modify = null): static;

    /**
     * Add every file under a prefix, with the sizes the listing already carries.
     *
     * One listing instead of a request per file, and the sizes are what withContentLength() and
     * withKnownSize() need. Entries out of a listing are known to exist, so they skip verification.
     *
     * @param  string|null  $destination  The folder inside the archive. Defaults to the source's own name.
     * @param  callable(mixed): void|null  $modify
     */
    public function fromDiskDirectory(FilesystemAdapter|string $disk, string $source, ?string $destination = null, bool $recursive = true, ?callable $modify = null): static;

    /**
     * Add a single file by absolute path.
     *
     * @param  string|null  $destination  The path inside the archive. Defaults to the source's own name.
     * @param  callable(mixed): void|null  $modify
     */
    public function fromLocal(string $source, ?string $destination = null, ?callable $modify = null): static;

    /**
     * Add every file under a local directory, with the sizes the filesystem already knows.
     *
     * The local counterpart of fromDiskDirectory(): one walk, and no request per file.
     *
     * @param  string|null  $destination  The folder inside the archive. Defaults to the directory's own name.
     * @param  callable(mixed): void|null  $modify
     */
    public function fromLocalDirectory(string $source, ?string $destination = null, bool $recursive = true, ?callable $modify = null): static;

    /**
     * Add an entry from a string.
     *
     * @param  callable(mixed): void|null  $modify
     */
    public function fromRaw(string $destination, string $content, ?callable $modify = null): static;

    /**
     * Add a directory with nothing in it.
     *
     * @param  callable(mixed): void|null  $modify
     */
    public function emptyDirectory(string $directory, ?callable $modify = null): static;

    /**
     * Register an event handler.
     *
     * What it listens for is its first parameter: a concrete event, one of the interfaces they are
     * grouped by, or a union of either.
     *
     * @param  callable(Event): void  $handler
     */
    public function on(callable $handler): static;

    /**
     * Stop the archive after the entry being streamed right now.
     *
     * Meant to be called from an event handler. Remaining entries are skipped, ProcessAborted fires and
     * the archive is finished, so what has been written stays a valid - if incomplete - zip.
     *
     * With $discard the archive is thrown away instead: an S3 multipart upload is aborted, a file this
     * call created is removed, and saveToDisk() and saveToLocal() return null.
     */
    public function abort(bool $discard = false): static;

    /**
     * Stop the archive when the client hangs up.
     */
    public function stopOnConnectionAborted(): static;

    /**
     * Attach whatever the application needs on every event about this archive.
     *
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): static;

    /**
     * Drop whatever context was attached to this archive.
     */
    public function withoutContext(): static;

    /**
     * Check that each entry exists as it is added. On by default.
     *
     * That check is a request per entry on a remote disk - a HEAD against S3 for every file - which is
     * paid before a single byte is streamed. It buys a FileNotFoundException while the archive is still
     * being composed, rather than a broken entry half way through. For a large archive of entries you
     * already trust, withoutVerification() is the difference between hundreds of requests and none.
     *
     * fromDiskDirectory() and fromLocalDirectory() skip it either way: the listing they walk is proof.
     */
    public function withVerification(): static;

    /**
     * Take each entry as given, without checking that it is there.
     *
     * Saves a request per entry. An entry that turns out to be missing then surfaces as a
     * FileUnavailableException while it is being streamed instead.
     */
    public function withoutVerification(): static;

    /**
     * Send a Content-Length header with the response, where the size can be known up front.
     *
     * Turns withKnownSize() on as well, since it is the same calculation.
     */
    public function withContentLength(): static;

    /**
     * Send no Content-Length, and stop working the size out for it.
     */
    public function withoutContentLength(): static;

    /**
     * Work out the archive size before writing it, so progress knows what it is working towards.
     *
     * Lands on Context::$bytes->total, which is null without it. The size can only be known when every
     * entry is stored with a known exactSize.
     */
    public function withKnownSize(): static;

    /**
     * Do not work the size out up front: Context::$bytes->total stays null.
     */
    public function withoutKnownSize(): static;

    /**
     * Report progress once this many bytes have been written. Zero reports every write.
     */
    public function progressEveryBytes(int $bytes): static;

    /**
     * Report progress at most this often, whatever the throughput.
     *
     * Takes a DateInterval, an ISO 8601 duration such as "PT1S", or a relative one such as "250ms".
     */
    public function progressEveryInterval(DateInterval|string $interval): static;

    /**
     * Put PHP's output layer out of the way before streaming a response. On by default.
     *
     * Only ever applies to toResponse(), and never under the CLI SAPI.
     */
    public function manageOutput(): static;

    /**
     * Leave PHP's output layer as it is, for an application that manages its own buffering.
     */
    public function doesntManageOutput(): static;

    /**
     * The time limit to give a streamed response, in seconds.
     *
     * Zero, the default, means no limit. Null leaves PHP's own setting alone.
     */
    public function timeLimit(?int $seconds): static;

    /**
     * The archive as a stream, built while it is read.
     *
     * Read once, front to back: it is not seekable, and getSize() is null.
     */
    public function toStream(): StreamInterface;

    /**
     * The whole archive as a string, held in memory.
     */
    public function toString(): string;

    /**
     * Write the archive to an absolute path, and return its size.
     *
     * Built beside the target and renamed into place, so a failure leaves what was there untouched.
     * Returns null when the archive was discarded.
     */
    public function saveToLocal(string $path): ?int;

    /**
     * Write the archive to a Laravel disk while it is being built, and return its size.
     *
     * Returns null when the archive was discarded. A failure aborts an S3 multipart upload and removes
     * the path if this call created it.
     *
     * @param  array<string, mixed>  $options  Passed on to the disk, e.g. ['part_size' => ...] for S3.
     */
    public function saveToDisk(FilesystemAdapter|string $disk, string $path, array $options = []): ?int;
}
