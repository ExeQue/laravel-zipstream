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
