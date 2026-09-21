<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Events;

enum EventType
{
    /**
     * Before the zip starts to be streamed
     */
    case ProcessStarted;

    /**
     * After the zip has been streamed
     */
    case ProcessFinished;

    /**
     * If the zip streaming process is aborted
     */
    case ProcessAborted;

    /**
     * When an exception is thrown while streaming an entry
     */
    case ProcessError;

    /**
     * Before a directory is streamed
     */
    case StreamingDirectory;

    /**
     * After a directory is streamed
     */
    case StreamedDirectory;

    /**
     * Before a file is streamed
     */
    case StreamingFile;

    /**
     * After a file is streamed
     */
    case StreamedFile;

    /**
     * Every time bytes of the archive itself are written
     *
     * Receives the number of bytes just written and the running total. Granularity is PHP's
     * write size (8 KB), so a handler doing real work should throttle itself. For that reason it
     * is the one event Any does not cover: register for it directly.
     */
    case StreamedBytes;

    /**
     * Before a file or directory is streamed to the zip
     */
    case StreamingToZip;

    /**
     * After a file or directory is streamed to the zip
     */
    case StreamedToZip;

    /**
     * Before the zip is saved to disk
     */
    case SavingToDisk;

    /**
     * After the zip is saved to disk
     */
    case SavedToDisk;

    /**
     * Before the zip is saved to the filesystem
     */
    case SavingToFilesystem;

    /**
     * After the zip is saved to the filesystem
     */
    case SavedToFilesystem;

    /**
     * Before a response is streamed
     */
    case StreamingResponse;

    /**
     * After a response is streamed
     */
    case StreamedResponse;

    /**
     * Any event type
     */
    case Any;
}
