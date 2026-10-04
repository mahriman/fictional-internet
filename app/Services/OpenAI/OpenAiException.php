<?php

namespace App\Services\OpenAI;

use RuntimeException;
use Throwable;

class OpenAiException extends RuntimeException
{
    public readonly OpenAiFailureKind $failureKind;

    /**
     * @param  array<string, int|string|bool|null>  $diagnosticContext
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
        ?OpenAiFailureKind $failureKind = null,
        public readonly array $diagnosticContext = [],
    ) {
        $this->failureKind = $failureKind ?? match ($statusCode) {
            401 => OpenAiFailureKind::Authentication,
            403 => OpenAiFailureKind::Authorization,
            408 => OpenAiFailureKind::Network,
            429 => OpenAiFailureKind::RateLimited,
            default => $statusCode !== null && $statusCode >= 500 && $statusCode <= 599
                ? OpenAiFailureKind::TemporaryProvider
                : OpenAiFailureKind::Other,
        };

        parent::__construct($message, $statusCode ?? 0, $previous);
    }
}
