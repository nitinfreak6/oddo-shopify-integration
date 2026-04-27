<?php

namespace App\Exceptions;

use RuntimeException;

class OdooApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $faultCode = 0,
        private readonly ?string $endpoint = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $faultCode, $previous);
    }

    public function getFaultCode(): int
    {
        return $this->faultCode;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }
}
