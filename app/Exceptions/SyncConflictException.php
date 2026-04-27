<?php

namespace App\Exceptions;

use RuntimeException;

class SyncConflictException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $entityType = '',
        private readonly string $entityId = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }
}
