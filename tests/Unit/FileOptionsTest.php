<?php

declare(strict_types=1);

use ExeQue\ZipStream\Contracts\HasComment;
use ExeQue\ZipStream\Contracts\HasFileOptions;
use ExeQue\ZipStream\Contracts\HasLastModified;

describe(HasFileOptions::class, function () {
    arch('Extends interfaces')->expect(HasFileOptions::class)->toImplement([
        HasComment::class,
        HasLastModified::class,
    ]);
});
