<?php

declare(strict_types=1);

namespace ExeQue\ZipStream\Contracts;

interface HasComment
{
    /**
     * Set the comment for the file.
     */
    public function comment(string $comment): static;
}
