<?php

namespace App\Exceptions;

use RuntimeException;

class ShopifyApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?string $endpoint = null,
        private readonly ?array $responseBody = null,
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

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}
