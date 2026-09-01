<?php

namespace App\Exceptions;

use RuntimeException;

class WarzoneApiException extends RuntimeException
{
    /** @var int|null */
    private $statusCode;

    /** @var bool */
    private $ambiguous;

    /** @var bool */
    private $retryable;

    public function __construct(
        string $message,
        int $statusCode = null,
        bool $ambiguous = false,
        bool $retryable = false
    ) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->ambiguous = $ambiguous;
        $this->retryable = $retryable;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function isAmbiguous(): bool
    {
        return $this->ambiguous;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
