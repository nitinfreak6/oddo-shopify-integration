<?php

namespace App\Exceptions;

use RuntimeException;

class AmazonApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?string $endpoint = null,
        private readonly ?array $errors = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function getErrors(): ?array
    {
        return $this->errors;
    }

    public function isThrottled(): bool
    {
        return $this->httpStatus === 429;
    }
}
